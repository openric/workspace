<?php

// OpenRiC Workspace - resolving names to authority records and places (roadmap phase 17b2), after Heratio's
// AuthorityReviewController, ParkQueueController and AssignmentController: the queue of mentions with their best
// candidate; one mention for review, with its context, ranked candidates and their evidence; linking it to a candidate
// or another record, making a new one filled from outside authorities, parking it or rejecting it; the park queue;
// giving mentions to an archivist; and the institution's settings. Who may review proposals resolves names; who
// administers the institution sets the outside authorities up.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Authority\Assignments;
use App\Authority\Candidates;
use App\Authority\Decisions;
use App\Authority\Lookup\Prefill;
use App\Authority\Mentions;
use App\Authority\ParkQueue;
use App\Authority\Settings;
use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;
use App\Models\Concept;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class AuthorityResolutionController extends Controller
{
    public function __construct(private Decisions $decisions, private Settings $settings) {}

    public function queue(Request $request): View
    {
        $user = $request->user();
        $state = (string) $request->query('state', 'pending');
        $kind = (string) $request->query('kind');
        $mine = $request->boolean('mine');
        $mentions = AuthorityMention::where('institution_id', $user->institution_id)
            ->with('proposal.kind', 'proposal.agentType', 'record', 'state.labels', 'assignedTo', 'candidates')
            ->when($state !== 'any', fn ($q) => $q->whereHas('state', fn ($s) => $s->where('notation', $state)))
            ->when($kind === 'place', fn ($q) => $q->whereHas('proposal.kind', fn ($k) => $k->where('notation', 'place')))
            ->when($kind === 'agent', fn ($q) => $q->whereHas('proposal.kind', fn ($k) => $k->where('notation', 'agent')))
            ->when($mine, fn ($q) => $q->where('assigned_to_id', $user->id))
            ->orderByDesc('created_at')->paginate(50)->withQueryString();

        return view('authority.queue', ['mentions' => $mentions, 'state' => $state, 'kind' => $kind, 'mine' => $mine,
            'states' => Concept::choices(Vocab::BASE.'mention-state/')->get(), 'waiting' => $this->unpromoted($user->institution_id)->count(),
            'archivists' => app(Assignments::class)->archivists($user->institution_id)]);
    }

    /** Make mentions of the proposed names not yet in the queue (at most 200 at once). */
    public function promote(Request $request): RedirectResponse
    {
        $made = 0;
        foreach ($this->unpromoted($request->user()->institution_id)->limit(200)->get() as $proposal) {
            app(Mentions::class)->promote($proposal);
            $made++;
        }

        return back()->with('status', trans_choice(':count name brought into the queue.|:count names brought into the queue.', $made, ['count' => $made]));
    }

    public function review(Request $request, AuthorityMention $mention): View
    {
        $this->own($request, $mention);

        return view('authority.review', ['mention' => $mention->load('proposal.kind', 'proposal.agentType', 'proposal.inference', 'record', 'state.labels', 'assignedTo',
            'candidates', 'decisions.decision.labels', 'decisions.user', 'park.parkedBy'), 'archivists' => app(Assignments::class)->archivists($mention->institution_id),
            'open' => in_array($mention->state->notation, ['pending', 'parked'], true)]);
    }

    public function link(Request $request, AuthorityMention $mention, AuthorityCandidate $candidate): RedirectResponse
    {
        $this->own($request, $mention);

        return $this->decide(fn () => $this->decisions->link($mention, $request->user(), $candidate), __('Linked to :name.', ['name' => $candidate->display_name]), $mention);
    }

    public function linkDifferent(Request $request, AuthorityMention $mention): RedirectResponse
    {
        $this->own($request, $mention);
        $data = $request->validate(['entity' => ['required', 'uuid']]);

        return $this->decide(fn () => $this->decisions->linkDifferent($mention, $request->user(), $data['entity']), __('Linked.'), $mention);
    }

    /** Names of the institution's records or places for "Link to another", as JSON. */
    public function lookup(Request $request, AuthorityMention $mention): JsonResponse
    {
        $this->own($request, $mention);
        $q = trim((string) $request->query('q'));

        return response()->json($q === '' ? [] : app(Candidates::class)->search($mention->institution_id, $q, $mention->label())->take(20)->values());
    }

    public function createForm(Request $request, AuthorityMention $mention): View
    {
        $this->own($request, $mention);
        $prefill = app(Prefill::class)->prefill($mention, $request->query('source') ?: null);

        return view('authority.create', ['mention' => $mention->load('proposal.agentType', 'record'), 'prefill' => $prefill,
            'sources' => collect($this->settings->sources($mention->institution_id))->filter(fn ($s) => $s['enabled'])]);
    }

    public function create(Request $request, AuthorityMention $mention): RedirectResponse
    {
        $this->own($request, $mention);
        $data = $request->validate(['fields' => ['required', 'array'], 'fields.*' => ['nullable', 'string', 'max:20000'], 'provenance' => ['nullable', 'array'], 'provenance.*' => ['nullable', 'string']]);
        $provenance = collect($data['provenance'] ?? [])->map(fn ($p) => json_decode((string) $p, true))->filter(fn ($p) => is_array($p))->all();

        return $this->decide(fn () => $this->decisions->createNew($mention, $request->user(), $data['fields'], $provenance), __('Made and linked.'), $mention);
    }

    public function park(Request $request, AuthorityMention $mention): RedirectResponse
    {
        $this->own($request, $mention);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->decide(fn () => $this->decisions->park($mention, $request->user(), $data['reason']), __('Parked.'), $mention);
    }

    public function reject(Request $request, AuthorityMention $mention): RedirectResponse
    {
        $this->own($request, $mention);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return $this->decide(fn () => $this->decisions->reject($mention, $request->user(), $data['reason'] ?? null), __('Rejected; kept as feedback for the model.'), $mention);
    }

    public function parked(Request $request, ParkQueue $parks): View
    {
        $filters = $request->only('parked_by', 'kind', 'since', 'reason', 'sort') + ['new_only' => $request->boolean('new_only')];

        return view('authority.parked', ['parks' => $parks->list($request->user()->institution_id, $filters), 'filters' => $filters,
            'counts' => $parks->countsByArchivist($request->user()->institution_id), 'archivists' => app(Assignments::class)->archivists($request->user()->institution_id)]);
    }

    public function unpark(Request $request, AuthorityMention $mention, ParkQueue $parks): RedirectResponse
    {
        $this->own($request, $mention);
        $mention->state->notation === 'parked' || abort(404);
        $parks->unpark($mention, $request->user());

        return redirect()->route('authority.review', $mention)->with('status', __('Taken up again, with its candidates found afresh.'));
    }

    public function assign(Request $request, Assignments $assignments): RedirectResponse
    {
        $data = $request->validate(['archivist' => ['required', 'uuid'], 'mentions' => ['required', 'array', 'max:500'], 'mentions.*' => ['uuid'], 'note' => ['nullable', 'string', 'max:2000']]);
        $archivist = User::where('institution_id', $request->user()->institution_id)->findOrFail($data['archivist']);
        $mentions = AuthorityMention::where('institution_id', $request->user()->institution_id)->whereIn('id', $data['mentions'])->get();
        try {
            $result = $assignments->assignMany($mentions, $archivist, $request->user(), $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['archivist' => $e->getMessage()]);
        }

        return back()->with('status', trans_choice(':count name given to :name.|:count names given to :name.', $result['assigned'], ['count' => $result['assigned'], 'name' => $archivist->name]));
    }

    public function settings(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('authority.settings', ['topN' => $this->settings->topN($institution), 'tokens' => $this->settings->roleTokens($institution),
            'kinds' => Concept::choices(Vocab::BASE.'role-language-kind/')->get(), 'sources' => $this->settings->sources($institution),
            'sourceTerms' => Concept::choices(Vocab::BASE.'lookup-source/')->get()->keyBy('notation')]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['top_n' => ['required', 'integer', 'between:1,50'], 'tokens' => ['nullable', 'array'], 'tokens.*' => ['nullable', 'string', 'max:20000'],
            'sources' => ['nullable', 'array'], 'sources.*.enabled' => ['nullable', 'boolean'], 'sources.*.rate_limit' => ['nullable', 'integer', 'between:1,1000'],
            'sources.*.cache_ttl' => ['nullable', 'integer', 'between:60,31536000'], 'sources.*.username' => ['nullable', 'string', 'max:200'], 'sources.*.precedence' => ['nullable', 'integer', 'between:0,100']]);
        $institution = $request->user()->institution_id;
        $this->settings->save($institution, (int) $data['top_n'], collect($data['tokens'] ?? [])->map(fn ($t) => preg_split('/\R/u', (string) $t))->all());
        $this->settings->saveSources($institution, $data['sources'] ?? []);

        return back()->with('status', __('Saved.'));
    }

    /** The agent and place proposals waiting that are not yet mentions. */
    private function unpromoted(string $institutionId)
    {
        return Proposal::where('institution_id', $institutionId)->whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.'proposed'))
            ->whereHas('kind', fn ($q) => $q->whereIn('notation', ['agent', 'place']))->whereNotIn('id', AuthorityMention::select('proposal_id'))->orderBy('created_at');
    }

    private function decide(callable $do, string $done, AuthorityMention $mention): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('authority.review', $mention)->with('status', $done);
    }

    private function own(Request $request, AuthorityMention $mention): void
    {
        abort_unless($mention->institution_id === $request->user()->institution_id, 404);
    }
}
