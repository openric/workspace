<?php

// OpenRiC Workspace - the review queue (ADR 0007, step 3): proposals sent through the API, and relations proposed
// through it, for those who hold review-proposals on the record and may change it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Proposal;
use App\Models\RelationAssertion;
use App\Models\User;
use App\Proposals\ReviewService;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ProposalReviewController extends Controller
{
    /** ponytail: the newest 200 of each are shown, filtered here; page in SQL when a queue grows past that. */
    private const SHOWN = 200;

    public function __construct(private ReviewService $reviews, private RelationService $relations) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['proposed', 'accepted', 'rejected'])],
            'kind' => ['nullable', Rule::in(['agent', 'place', 'date', 'value', 'transcript', 'relation'])],
            'record' => ['nullable', 'uuid'],
        ]);
        $status = $filters['status'] ?? 'proposed';
        $kind = $filters['kind'] ?? null;
        $record = $filters['record'] ?? null;

        $proposals = $kind === 'relation' ? collect() : Proposal::where('institution_id', $user->institution_id)
            ->whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.$status))
            ->when($kind, fn ($q) => $q->whereHas('kind', fn ($q) => $q->where('iri', Vocab::PROPOSAL_KINDS.$kind)))
            ->when($record, fn ($q) => $q->where('record_resource_id', $record))
            ->with(['kind', 'status', 'element.labels', 'agentType', 'record', 'instantiation', 'proposedBy', 'reviewedBy'])
            ->orderByDesc('created_at')->limit(self::SHOWN)->get()
            ->filter(fn (Proposal $p) => $this->mayReview($user, $p->record))->values();

        $assertions = $kind !== null && $kind !== 'relation' ? collect() : RelationAssertion::where('institution_id', $user->institution_id)
            ->whereHas('origin', fn ($q) => $q->where('iri', Vocab::BASE.'assertion-origin/model'))
            ->whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.$status))
            ->when($record, fn ($q) => $q->whereHas('relation.evidence', fn ($q) => $q->where('record_resource_id', $record)))
            ->with(['status', 'user', 'reviewer', 'relation.relationType', 'relation.evidence.record'])
            ->orderByDesc('asserted_at')->limit(self::SHOWN)->get()
            ->filter(fn (RelationAssertion $a) => $this->mayReviewRelation($user, $a))->values();

        return view('proposals.index', [
            'proposals' => $proposals, 'assertions' => $assertions, 'status' => $status, 'kind' => $kind, 'record' => $record,
            'ends' => fn (RelationAssertion $a) => $this->ends($a),
            'agentTypes' => Concept::choices(Vocab::BASE.'agent-type/')->with('labels')->get(),
        ]);
    }

    public function accept(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorise($request, $proposal);
        $edits = $request->validate([
            'value' => ['required', 'string', 'max:65000'],
            'language' => ['nullable', 'string', 'max:35'],
            'normalized' => ['nullable', 'string', 'max:64'],
            'agent_type' => ['nullable', 'string', 'max:64'],
            'link' => ['nullable', 'string', 'max:36'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);
        try {
            $this->reviews->accept($proposal, $request->user(), array_filter($edits, fn ($v) => $v !== null && $v !== ''));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(["proposal-{$proposal->id}" => $e->getMessage()])->withInput();
        }

        return back()->with('status', __('Accepted: written to :record as a draft, for publishing.', ['record' => EntityKinds::label($proposal->record)]));
    }

    public function reject(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorise($request, $proposal);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:4000']])['note'] ?? null;
        try {
            $this->reviews->reject($proposal, $request->user(), $note);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(["proposal-{$proposal->id}" => $e->getMessage()]);
        }

        return back()->with('status', __('Rejected.'));
    }

    /** Accept or reject a relation proposed through the API, as the relation's own page does. */
    public function reviewRelation(Request $request, RelationAssertion $assertion): RedirectResponse
    {
        abort_unless($assertion->institution_id === $request->user()->institution_id && $this->mayReviewRelation($request->user(), $assertion), 404);
        $decision = $request->validate(['decision' => ['required', Rule::in(['accept', 'reject'])]])['decision'];
        try {
            $this->relations->review($assertion, $decision === 'accept', $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(["assertion-{$assertion->id}" => $e->getMessage()]);
        }

        return back()->with('status', __($decision === 'accept' ? 'Relation accepted.' : 'Relation rejected.'));
    }

    private function authorise(Request $request, Proposal $proposal): void
    {
        abort_unless($proposal->institution_id === $request->user()->institution_id && $this->mayReview($request->user(), $proposal->record), 404);
    }

    /** Review needs review-proposals on the record and the right to change it, which accepting does. */
    private function mayReview(User $user, $record): bool
    {
        return $user->may('review-proposals', 'description', $record) && $user->may('update', 'description', $record);
    }

    /** A relation: review-proposals on the record it was proposed for, and the right to change the entity it comes from. */
    private function mayReviewRelation(User $user, RelationAssertion $assertion): bool
    {
        $record = $assertion->relation->evidence->first()?->record;
        [$source] = $this->ends($assertion);

        return $record !== null && $source !== null && $user->may('review-proposals', 'description', $record)
            && $user->may('update', EntityKinds::permission($source), $source);
    }

    /** @return array{0: ?Model, 1: ?Model} */
    private function ends(RelationAssertion $assertion): array
    {
        $relation = $assertion->relation;
        $find = fn (string $table, string $id) => ($kind = EntityKinds::byTable($table)) ? EntityKinds::get($kind)['class']::find($id) : null;

        return [$find($relation->source_table, $relation->source_id), $find($relation->target_table, $relation->target_id)];
    }
}
