<?php

/*
 * OpenRiC Workspace - repatriation claims for staff (roadmap phase 17d2), copied from Heratio,
 * packages/ahg-semantic-search/src/Controllers/RepatriationClaimController.php, RepatriationDialogueController.php
 * and RepatriationKnowledgeController.php (moderate, moderateSet) at commit bca348f16d70f4a7defb2c6d4923248a88c4068f
 * (governance/CODE_PROVENANCE.md): the register of claims filtered by status, registering and amending a claim, its
 * dialogue workspace (the messages, shared or internal, the history of its status, the links to its shared record,
 * made and revoked), and the review of the knowledge people contribute. Changed: for accounts that may manage
 * repatriation claims, in their own institution (Heratio: administrators); a claim is registered from its
 * description; a new link is shown once, as only its hash is kept; the institution's choices; the namespace and code
 * style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\RepatriationClaim;
use App\Models\RepatriationKnowledge;
use App\Models\RepatriationSetting;
use App\Models\RepatriationShare;
use App\Relations\EntityKinds;
use App\Repatriation\Claims;
use App\Repatriation\Dialogue;
use App\Repatriation\Knowledge;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class RepatriationController extends Controller
{
    public function __construct(private Claims $claims, private Dialogue $dialogue, private Knowledge $knowledge) {}

    public function index(Request $request): View
    {
        $this->authorise($request);
        $institution = $request->user()->institution;
        $status = Concept::where('iri', Claims::STATUSES.$request->query('status'))->exists() ? $request->query('status') : null;

        return view('repatriation.index', ['claims' => $this->claims->list($institution, $status), 'status' => $status,
            'statuses' => Concept::choices(Claims::STATUSES)->get(), 'counts' => $this->claims->statusCounts($institution),
            'pending' => $this->knowledge->counts($institution)['pending'] ?? 0, 'settings' => RepatriationSetting::for($institution->id)]);
    }

    public function create(Request $request): View
    {
        $this->authorise($request);
        $record = RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->findOrFail((string) $request->query('record'));

        return view('repatriation.form', $this->form($request) + ['claim' => null, 'record' => $record]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $record = RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->findOrFail((string) $request->input('record'));
        $claim = $this->attempt(fn () => $this->claims->register($record, $this->validated($request), $request->user()));

        return redirect()->route('repatriation.dialogue', $claim)->with('status', __('Repatriation claim registered.'));
    }

    public function edit(Request $request, RepatriationClaim $claim): View
    {
        $this->authorise($request, $claim);

        return view('repatriation.form', $this->form($request) + ['claim' => $claim, 'record' => $claim->record]);
    }

    public function update(Request $request, RepatriationClaim $claim): RedirectResponse
    {
        $this->authorise($request, $claim);
        $this->attempt(fn () => $this->claims->update($claim, $this->validated($request), $request->user()));

        return redirect()->route('repatriation.dialogue', $claim)->with('status', __('Claim updated.'));
    }

    public function dialogue(Request $request, RepatriationClaim $claim): View
    {
        $this->authorise($request, $claim);

        return view('repatriation.dialogue', ['claim' => $claim->load('status.labels', 'record', 'claimantAgent', 'originPlace', 'statusChanges.from.labels', 'statusChanges.to.labels', 'shares.role.labels'),
            'messages' => $this->dialogue->messages($claim), 'statuses' => Concept::choices(Claims::STATUSES)->get(),
            'roles' => Concept::choices(Dialogue::PARTIES)->get(), 'visibilities' => Concept::choices(Dialogue::VISIBILITIES)->get(),
            'knowledge' => $this->knowledge->approvedFor($claim), 'disclaimer' => Claims::DISCLAIMER]);
    }

    public function message(Request $request, RepatriationClaim $claim): RedirectResponse
    {
        $this->authorise($request, $claim);
        $data = $request->validate(['body' => ['required', 'string', 'max:60000'], 'visibility' => ['required', 'string', 'max:64'], 'role' => ['required', 'string', 'max:64']]);
        $this->attempt(fn () => $this->dialogue->post($claim, $data['body'], $data['role'], $data['visibility'], $request->user()));

        return redirect()->route('repatriation.dialogue', $claim)->with('status', __('Message added to the dialogue.'));
    }

    public function status(Request $request, RepatriationClaim $claim): RedirectResponse
    {
        $this->authorise($request, $claim);
        $data = $request->validate(['status' => ['required', 'string', 'max:64'], 'note' => ['nullable', 'string', 'max:1024']]);
        $this->attempt(fn () => $this->claims->setStatus($claim, $data['status'], $data['note'] ?? null, $request->user()));

        return redirect()->route('repatriation.dialogue', $claim)->with('status', __('Claim status updated.'));
    }

    public function grant(Request $request, RepatriationClaim $claim): RedirectResponse
    {
        $this->authorise($request, $claim);
        $data = $request->validate(['grantee_name' => ['nullable', 'string', 'max:255'], 'grantee_role' => ['required', 'string', 'max:64'],
            'can_message' => ['nullable', 'boolean'], 'expires_at' => ['nullable', 'date']]);
        [, $token] = $this->attempt(fn () => $this->dialogue->grant($claim, [...$data, 'can_message' => $request->boolean('can_message')], $request->user()));

        return redirect()->route('repatriation.dialogue', $claim)->with('status', __('Shared-record link created. Send it to the claimant; it is shown only this once.'))
            ->with('new_share_url', route('repatriation.shared', $token));
    }

    public function revoke(Request $request, RepatriationClaim $claim, RepatriationShare $share): RedirectResponse
    {
        $this->authorise($request, $claim);
        abort_unless($share->claim_id === $claim->id, 404);
        $this->dialogue->revoke($share, $request->user());

        return redirect()->route('repatriation.dialogue', $claim)->with('status', __('The link is revoked.'));
    }

    public function knowledgeQueue(Request $request): View
    {
        $this->authorise($request);
        $institution = $request->user()->institution;
        $status = Concept::where('iri', Knowledge::STATUSES.$request->query('status'))->exists() ? $request->query('status') : 'pending';

        return view('repatriation.knowledge', ['contributions' => $this->knowledge->queue($institution, $status), 'status' => $status,
            'statuses' => Concept::choices(Knowledge::STATUSES)->get(), 'counts' => $this->knowledge->counts($institution)]);
    }

    public function moderate(Request $request, RepatriationKnowledge $contribution): RedirectResponse
    {
        $this->authorise($request);
        abort_unless($contribution->institution_id === $request->user()->institution_id, 404);
        $data = $request->validate(['status' => ['required', 'string', 'max:64']]);
        $this->attempt(fn () => $this->knowledge->moderate($contribution, $data['status'], $request->user()));

        return back()->with('status', __('Moderation saved.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $values = ['public_register' => $request->boolean('public_register'), 'public_lodging' => $request->boolean('public_lodging'), 'notify' => $request->boolean('notify')];
        DB::transaction(fn () => AuditLog::actingAs($request->user(), function () use ($request, $values) {
            $settings = RepatriationSetting::for($request->user()->institution_id);
            $settings->fill($values)->save();
            AuditLog::record(Vocab::action('update'), $request->user()->institution, null, ['repatriation_settings' => $values]);
        }));

        return redirect()->route('repatriation.index')->with('status', __('Saved.'));
    }

    private function form(Request $request): array
    {
        $institution = $request->user()->institution_id;
        $languages = Vocab::languages();

        return ['statuses' => Concept::choices(Claims::STATUSES)->get(),
            'agents' => Agent::where('institution_id', $institution)->whereNull('deleted_at')->get()->map(fn ($a) => ['id' => $a->id, 'name' => EntityKinds::label($a, $languages)])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'places' => Place::where('institution_id', $institution)->whereNull('deleted_at')->get()->map(fn ($p) => ['id' => $p->id, 'name' => EntityKinds::label($p, $languages)])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()];
    }

    private function validated(Request $request): array
    {
        return $request->validate(['claimant_community' => ['nullable', 'string', 'max:512'], 'claimant_agent_id' => ['nullable', 'uuid'], 'origin_place' => ['nullable', 'string', 'max:512'],
            'origin_place_id' => ['nullable', 'uuid'], 'current_holder' => ['nullable', 'string', 'max:512'], 'status' => ['required', 'string', 'max:64'],
            'evidence_summary' => ['nullable', 'string', 'max:20000'], 'contact' => ['nullable', 'string', 'max:512'], 'contact_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:20000']]);
    }

    /** Run it, turning a refusal into a form error. */
    private function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['claim' => $e->getMessage()]);
        }
    }

    private function authorise(Request $request, ?RepatriationClaim $claim = null): void
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-repatriation')), 403);
        abort_if($claim && $claim->institution_id !== $request->user()->institution_id, 404);
    }
}
