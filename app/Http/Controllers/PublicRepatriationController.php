<?php

/*
 * OpenRiC Workspace - repatriation in the public catalogue (roadmap phase 17d2), copied from Heratio,
 * packages/ahg-semantic-search/src/Controllers/RepatriationDashboardController.php (index, json),
 * VirtualReturnController.php (show), RepatriationKnowledgeController.php (form, contribute),
 * PublicClaimLodgeController.php (form, submit, thanks, with its hidden field and its three seconds) and
 * RepatriationSharedRecordController.php (show, message) at commit bca348f16d70f4a7defb2c6d4923248a88c4068f
 * (governance/CODE_PROVENANCE.md). Changed: the register, a claim's virtual return and contributing to it only where
 * the institution shows its register, and for a description its public catalogue shows; lodging only where the
 * institution allows it, about any description its catalogue shows (Heratio: an object its displaced-heritage
 * register traces, which is 17d3); the claimant's name is not kept apart from the contact; a shared record's link is
 * looked up by its hash; the walk through a 3D space waits for the exhibitions of phase 21; the namespace and code
 * style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\RepatriationClaim;
use App\Models\RepatriationSetting;
use App\Repatriation\Claims;
use App\Repatriation\Dialogue;
use App\Repatriation\Knowledge;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class PublicRepatriationController extends Controller
{
    public function __construct(private Claims $claims, private Dialogue $dialogue, private Knowledge $knowledge) {}

    public function dashboard(Request $request): View
    {
        $catalogue = $this->register($request);

        return view('discover.repatriation.dashboard', ['view' => $catalogue->view, 'dashboard' => $this->claims->dashboard($catalogue->institution, $catalogue),
            'disclaimer' => Claims::DISCLAIMER]);
    }

    public function json(Request $request): JsonResponse
    {
        $catalogue = $this->register($request);
        $d = $this->claims->dashboard($catalogue->institution, $catalogue);
        $languages = Vocab::languages();

        return response()->json(['total' => $d['total'], 'virtual_return' => $d['virtual_return'], 'returned' => $d['returned'], 'in_dialogue' => $d['in_dialogue'],
            'by_status' => array_map(fn ($s) => ['status' => $s['concept']->notation, 'label' => $s['concept']->label($languages), 'count' => $s['count']], $d['by_status']),
            'by_origin' => $d['by_origin'], 'by_community' => $d['by_community'],
            'recent' => $d['recent']->map(fn (RepatriationClaim $c) => ['id' => $c->id, 'status' => $c->status->notation, 'origin_place' => $c->origin_place,
                'claimant_community' => $c->claimant_community, 'url' => route('discover.repatriation.show', $c->id), 'updated_at' => $c->updated_at?->toIso8601String()])->all(),
            'disclaimer' => Claims::DISCLAIMER]);
    }

    public function show(Request $request, string $id): View
    {
        $catalogue = $this->register($request);
        $claim = $this->claim($catalogue, $id);

        return view('discover.repatriation.show', ['view' => $catalogue->view, 'claim' => $claim, 'unit' => $catalogue->find('records', $claim->record_resource_id),
            'knowledge' => $this->knowledge->approvedFor($claim), 'types' => Concept::choices(Knowledge::TYPES)->get(),
            'disclaimer' => Claims::DISCLAIMER, 'knowledgeDisclaimer' => Knowledge::DISCLAIMER]);
    }

    public function contribute(Request $request, string $id): RedirectResponse
    {
        $catalogue = $this->register($request);
        $claim = $this->claim($catalogue, $id);
        if ($request->filled('website')) {
            return redirect()->route('discover.repatriation.show', $claim->id);
        }
        $data = $request->validate(['type' => ['required', 'string', 'max:64'], 'body' => ['required', 'string', 'max:60000'], 'source' => ['nullable', 'string', 'max:512'],
            'contributor_name' => ['nullable', 'string', 'max:255']]);
        $this->attempt(fn () => $this->knowledge->contribute($claim, [...$data, 'credit_consent' => $request->boolean('credit_consent')], $request->user()));

        return redirect()->route('discover.repatriation.show', $claim->id)
            ->with('status', __('Thank you. Your contribution has been submitted and will appear once a reviewer has approved it.'));
    }

    public function lodge(Request $request, string $id): View
    {
        [$catalogue, $unit] = $this->lodging($request, $id);

        return view('discover.repatriation.lodge', ['view' => $catalogue->view, 'unit' => $unit, 'disclaimer' => Claims::DISCLAIMER, 'renderedAt' => time()]);
    }

    public function submit(Request $request, string $id): RedirectResponse
    {
        [, $unit] = $this->lodging($request, $id);
        // A robot fills the field people do not see, or sends the form faster than anyone could read it.
        if ($request->filled('website') || ($request->integer('rendered_at') > 0 && time() - $request->integer('rendered_at') < 3)) {
            return redirect()->route('discover.repatriation.thanks');
        }
        $data = $request->validate(['claimant_community' => ['required', 'string', 'max:512'], 'claimant_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'], 'origin_place' => ['nullable', 'string', 'max:512'], 'evidence_summary' => ['nullable', 'string', 'max:20000'],
            'consent' => ['accepted']]);
        $name = trim((string) ($data['claimant_name'] ?? ''));
        $contact = $name !== '' ? $name.' <'.$data['contact_email'].'>' : $data['contact_email'];
        $this->attempt(fn () => $this->claims->register($unit, ['claimant_community' => $data['claimant_community'], 'origin_place' => $data['origin_place'] ?? null,
            'evidence_summary' => $data['evidence_summary'] ?? null, 'contact' => $contact, 'contact_email' => $data['contact_email'],
            'notes' => __('Lodged from the public catalogue on :date. Pending staff review.', ['date' => now()->toDateString()])], null, true));

        return redirect()->route('discover.repatriation.thanks');
    }

    public function thanks(): View
    {
        return view('discover.repatriation.thanks', ['disclaimer' => Claims::DISCLAIMER]);
    }

    public function shared(string $token): View
    {
        $share = $this->dialogue->resolve($token);
        if ($share === null) {
            return view('repatriation.shared-inactive');
        }
        $claim = $share->claim->load('status.labels', 'record', 'statusChanges.from.labels', 'statusChanges.to.labels');
        $catalogue = new PublicCatalogue(Institution::findOrFail($claim->institution_id));

        return view('repatriation.shared', ['share' => $share, 'claim' => $claim, 'token' => $token, 'messages' => $this->dialogue->messages($claim, true),
            'unit' => $catalogue->find('records', $claim->record_resource_id), 'institution' => $catalogue->institution, 'disclaimer' => Claims::DISCLAIMER]);
    }

    public function sharedMessage(Request $request, string $token): View|RedirectResponse
    {
        $share = $this->dialogue->resolve($token);
        if ($share === null) {
            return view('repatriation.shared-inactive');
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:60000']]);
        $this->attempt(fn () => $this->dialogue->post($share->claim, $data['body'], 'claimant', 'shared', null, $share));

        return redirect()->route('repatriation.shared', $token)->with('status', __('Your message has been added to the dialogue.'));
    }

    /** The public catalogue, where the institution shows its register of claims. */
    private function register(Request $request): PublicCatalogue
    {
        $catalogue = $request->attributes->get('catalogue');
        abort_unless(RepatriationSetting::for($catalogue->institution->id)->public_register, 404);

        return $catalogue;
    }

    /** A claim of the institution about a description its catalogue shows. */
    private function claim(PublicCatalogue $catalogue, string $id): RepatriationClaim
    {
        $claim = preg_match('/^[0-9a-f-]{36}$/', $id) ? RepatriationClaim::where('institution_id', $catalogue->institution->id)->with('status.labels')->find($id) : null;
        abort_if($claim === null || $catalogue->find('records', $claim->record_resource_id) === null, 404);

        return $claim;
    }

    /** @return array{PublicCatalogue, RecordResource} */
    private function lodging(Request $request, string $id): array
    {
        $catalogue = $request->attributes->get('catalogue');
        abort_unless(RepatriationSetting::for($catalogue->institution->id)->public_lodging, 404);

        return [$catalogue, $catalogue->find('records', $id) ?? abort(404)];
    }

    private function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['claim' => $e->getMessage()]);
        }
    }
}
