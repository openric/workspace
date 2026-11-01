<?php

// OpenRiC Workspace - feedback on the catalogue or a record (roadmap phase 19a, ADR 0017), after Heratio's
// FeedbackController (ahg-feedback): anyone sends it, with their name and email; staff who may change descriptions
// read it, add notes, mark it completed or delete it. Heratio lets administrators only; corrections to descriptions
// are the cataloguers' work. A hidden field and a rate limit keep robots out.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Feedback;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Support\HostInstitution;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    public const TYPES = Vocab::BASE.'feedback-type/';

    public const STATUSES = Vocab::BASE.'feedback-status/';

    public function create(Request $request): View
    {
        return view('feedback.create', ['types' => Concept::choices(self::TYPES)->get(), 'record' => $this->record($request, $request->query('record'))]);
    }

    public function store(Request $request): RedirectResponse
    {
        // A robot fills the field people do not see.
        if ($request->filled('website')) {
            return redirect()->route('feedback.thanks');
        }
        $data = $request->validate(['subject' => ['required', 'string', 'max:1024'], 'remarks' => ['required', 'string', 'max:10000'],
            'name' => ['required', 'string', 'max:100'], 'surname' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'], 'relationship' => ['nullable', 'string', 'max:2000'], 'type' => ['required', 'string', 'max:64'],
            'record' => ['nullable', 'uuid']]);
        $record = $this->record($request, $data['record'] ?? null);
        $type = Concept::where('iri', self::TYPES.$data['type'])->value('id') ?? abort(422);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => Feedback::create([
            'institution_id' => $this->institution($request)->id, 'record_resource_id' => $record?->id, 'user_id' => $request->user()?->id, 'type_id' => $type,
            'status_id' => Concept::where('iri', self::STATUSES.'pending')->value('id'), 'subject' => trim($data['subject']), 'remarks' => trim($data['remarks']),
            'name' => trim($data['name']), 'surname' => trim($data['surname']), 'email' => trim($data['email']), 'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'relationship' => trim((string) ($data['relationship'] ?? '')) ?: null, 'language' => app()->getLocale(),
        ])));

        return redirect()->route('feedback.thanks', array_filter(['record' => $record?->id]));
    }

    public function thanks(Request $request): View
    {
        return view('feedback.thanks', ['record' => $this->record($request, $request->query('record'))]);
    }

    public function index(Request $request): View
    {
        $this->authorise($request);
        $status = in_array($request->query('status'), ['pending', 'completed'], true) ? $request->query('status') : null;
        $all = Feedback::where('institution_id', $request->user()->institution_id)->with('status', 'type', 'record');

        return view('feedback.index', ['entries' => (clone $all)->when($status, fn ($q) => $q->whereHas('status', fn ($s) => $s->where('iri', self::STATUSES.$status)))
            ->orderByDesc('created_at')->get(), 'status' => $status, 'counts' => $all->get()->countBy(fn ($f) => $f->status->notation)]);
    }

    public function show(Request $request, Feedback $feedback): View
    {
        $this->authorise($request, $feedback);

        return view('feedback.show', ['entry' => $feedback->load('status', 'type', 'record', 'completedBy'), 'statuses' => Concept::choices(self::STATUSES)->get()]);
    }

    public function update(Request $request, Feedback $feedback): RedirectResponse
    {
        $this->authorise($request, $feedback);
        $data = $request->validate(['status' => ['required', 'in:pending,completed'], 'staff_notes' => ['nullable', 'string', 'max:10000']]);
        $done = $data['status'] === 'completed';
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $feedback->update(['status_id' => Concept::where('iri', self::STATUSES.$data['status'])->value('id'),
            'staff_notes' => trim((string) ($data['staff_notes'] ?? '')) ?: null, 'completed_at' => $done ? ($feedback->completed_at ?? now()) : null,
            'completed_by_id' => $done ? ($feedback->completed_by_id ?? $request->user()->id) : null])));

        return redirect()->route('feedback.show', $feedback)->with('status', __('Saved.'));
    }

    public function destroy(Request $request, Feedback $feedback): RedirectResponse
    {
        $this->authorise($request, $feedback);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $feedback->delete()));

        return redirect()->route('feedback.index')->with('status', __('The feedback is deleted.'));
    }

    private function authorise(Request $request, ?Feedback $feedback = null): void
    {
        abort_unless($request->user()->mayAnywhere('update', 'description'), 403);
        abort_if($feedback && $feedback->institution_id !== $request->user()->institution_id, 404);
    }

    /** The institution whose catalogue this is: the account's, else the one the public catalogue serves. */
    private function institution(Request $request): Institution
    {
        return $request->user()?->institution ?? HostInstitution::for($request) ?? abort(404);
    }

    private function record(Request $request, ?string $id): ?RecordResource
    {
        return $id && preg_match('/^[0-9a-f-]{36}$/', $id) ? RecordResource::where('institution_id', $this->institution($request)->id)->whereNull('deleted_at')->find($id) : null;
    }
}
