<?php

// OpenRiC Workspace - retention and disposal (roadmap phase 13b, ADR 0015), after Heratio's ahg-records-manage
// RetentionController, DisposalController and ReviewController: the overview (schedules, records due for disposal,
// reviews due, disposals by status), a schedule and its classes, a record's class and reviews, and a disposal from
// initiation to its certificate. Who administers the institution keeps the schedules; who may delete a record takes
// part in its disposal; only who administers the institution destroys.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\DestructionCertificate;
use App\Models\DisposalAction;
use App\Models\DisposalClass;
use App\Models\Institution;
use App\Models\RecordDisposalClass;
use App\Models\RecordResource;
use App\Models\RetentionReview;
use App\Models\RetentionSchedule;
use App\Models\User;
use App\Retention\DisposalService;
use App\Retention\RetentionService;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class RetentionController extends Controller
{
    public function __construct(private RetentionService $retention, private DisposalService $disposals) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('delete', 'description'), 403);
        $institution = $request->user()->institution_id;

        return view('retention.index', ['schedules' => RetentionSchedule::where('institution_id', $institution)->with('status')->withCount('classes')->orderBy('reference')->orderByDesc('version')->get(),
            'due' => $this->retention->dueForDisposal($institution), 'reviews' => RetentionReview::where('institution_id', $institution)->whereNull('completed_on')->with('record', 'assignedTo')->orderBy('due_on')->get(),
            'disposals' => DisposalAction::where('institution_id', $institution)->with('record', 'action', 'status')->orderByDesc('initiated_at')->limit(50)->get(),
            'counts' => $this->disposals->counts($institution), 'manages' => $this->manages($request)]);
    }

    public function schedule(Request $request, RetentionSchedule $schedule): View
    {
        $this->own($request, $schedule->institution_id);

        return view('retention.schedule', ['schedule' => $schedule->load('status', 'previous', 'classes.trigger', 'classes.action'), 'manages' => $this->manages($request),
            'triggers' => Concept::choices(Vocab::BASE.'retention-trigger/')->get(), 'actions' => Concept::choices(Vocab::BASE.'disposal-action-type/')->get()]);
    }

    public function saveSchedule(Request $request, ?RetentionSchedule $schedule = null): RedirectResponse
    {
        abort_unless($this->manages($request), 403);
        $schedule && $this->own($request, $schedule->institution_id);
        $data = $request->validate(['reference' => ['required', 'string', 'max:100'], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'authority' => ['nullable', 'string', 'max:500'], 'jurisdiction' => ['nullable', 'string', 'max:100'], 'effective_on' => ['nullable', 'date_format:Y-m-d'],
            'review_on' => ['nullable', 'date_format:Y-m-d'], 'expires_on' => ['nullable', 'date_format:Y-m-d']]);
        $saved = $this->retention->saveSchedule(Institution::findOrFail($request->user()->institution_id), $data, $schedule, $request->user());

        return redirect()->route('retention.schedule', $saved)->with('status', __('Saved.'));
    }

    public function approveSchedule(Request $request, RetentionSchedule $schedule): RedirectResponse
    {
        abort_unless($this->manages($request), 403);
        $this->own($request, $schedule->institution_id);
        $by = $request->validate(['approved_by' => ['required', 'string', 'max:255']])['approved_by'];

        return $this->attempt(fn () => $this->retention->approveVersion($schedule, $by, $request->user()), __('Approved.'));
    }

    public function newVersion(Request $request, RetentionSchedule $schedule): RedirectResponse
    {
        abort_unless($this->manages($request), 403);
        $this->own($request, $schedule->institution_id);

        return redirect()->route('retention.schedule', $this->retention->newVersion($schedule, $request->user()))->with('status', __('A new draft version, with its classes.'));
    }

    public function saveClass(Request $request, RetentionSchedule $schedule, ?DisposalClass $class = null): RedirectResponse
    {
        abort_unless($this->manages($request), 403);
        $this->own($request, $schedule->institution_id);
        abort_if($class && $class->retention_schedule_id !== $schedule->id, 404);
        $data = $request->validate(['reference' => ['required', 'string', 'max:100'], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'retention_years' => ['nullable', 'integer', 'min:0', 'max:500'], 'retention_months' => ['nullable', 'integer', 'min:0', 'max:1200'], 'trigger' => ['required', 'string'],
            'action' => ['required', 'string'], 'review_required' => ['nullable', 'boolean'], 'citation' => ['nullable', 'string', 'max:2000'], 'is_active' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => $this->retention->saveClass($schedule, $data, $class, $request->user()), __('Saved.'));
    }

    /** Give the record a class (or take it away), or another due date with the reason. */
    public function assign(Request $request, RecordResource $record): RedirectResponse
    {
        $this->may($request, $record);
        $data = $request->validate(['disposal_class_id' => ['nullable', 'uuid'], 'event_date' => ['nullable', 'date_format:Y-m-d'], 'override_due_on' => ['nullable', 'date_format:Y-m-d'],
            'override_reason' => ['nullable', 'string', 'max:2000'], 'override' => ['nullable', 'boolean']]);

        return $this->attempt(function () use ($request, $record, $data) {
            if ($request->boolean('override')) {
                $assignment = RecordDisposalClass::where('record_resource_id', $record->id)->firstOrFail();
                $this->retention->override($assignment, $data['override_due_on'] ?? null, $data['override_reason'] ?? null, $request->user());
            } elseif (($data['disposal_class_id'] ?? '') === '') {
                $this->retention->unassign($record, $request->user());
            } else {
                $this->retention->assign($record, DisposalClass::findOrFail($data['disposal_class_id']), $data['event_date'] ?? null, $request->user());
            }
        }, __('Saved.'));
    }

    public function scheduleReview(Request $request, RecordResource $record): RedirectResponse
    {
        $this->may($request, $record);
        $data = $request->validate(['due_on' => ['required', 'date_format:Y-m-d'], 'assigned_to_id' => ['nullable', 'uuid']]);
        $assignee = ($data['assigned_to_id'] ?? null) ? User::where(['id' => $data['assigned_to_id'], 'institution_id' => $record->institution_id])->firstOrFail() : null;
        $this->retention->scheduleReview($record, RecordDisposalClass::where('record_resource_id', $record->id)->first()?->disposalClass, $data['due_on'], $assignee, $request->user());

        return back()->with('status', __('Review scheduled.'));
    }

    public function decide(Request $request, RetentionReview $review): RedirectResponse
    {
        $this->may($request, $review->record);
        $data = $request->validate(['decision' => ['required', 'string'], 'decision_notes' => ['nullable', 'string', 'max:5000'], 'next_due_on' => ['nullable', 'date_format:Y-m-d']]);

        return $this->attempt(fn () => $this->retention->decide($review, $data['decision'], $data['decision_notes'] ?? null, $data['next_due_on'] ?? null, $request->user()), __('Decided.'));
    }

    public function initiate(Request $request, RecordResource $record): RedirectResponse
    {
        $this->may($request, $record);
        $data = $request->validate(['action' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:5000']]);
        try {
            $disposal = $this->disposals->initiate($record, $data['action'], $data['reason'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['retention' => $e->getMessage()]);
        }

        return redirect()->route('disposals.show', $disposal)->with('status', __('Disposal initiated.'));
    }

    public function disposal(Request $request, DisposalAction $disposal): View
    {
        $this->own($request, $disposal->institution_id);
        abort_unless($request->user()->mayAnywhere('delete', 'description'), 403);

        return view('retention.disposal', ['disposal' => $disposal->load('record', 'action', 'status', 'disposalClass.schedule', 'initiatedBy', 'recommendedBy', 'approvedBy', 'clearedBy', 'executedBy', 'certificate'),
            'mayDestroy' => $request->user()->hasPermission(Vocab::permission('manage-institution'))]);
    }

    /** A step of the disposal: recommend, approve, clear, reject, cancel, carry out or verify. */
    public function step(Request $request, DisposalAction $disposal, string $step): RedirectResponse
    {
        $this->own($request, $disposal->institution_id);
        $record = RecordResource::find($disposal->record_resource_id);
        abort_unless($request->user()->may('delete', 'description', $record), 403);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000'], 'method' => ['nullable', 'string', 'max:500'], 'witness' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:500']]);
        $comment = $data['comment'] ?? null;
        $actor = $request->user();
        $needsReason = fn () => trim((string) $comment) !== '' ? $comment : throw new InvalidArgumentException(__('Give the reason.'));

        return $this->attempt(fn () => match ($step) {
            'recommend' => $this->disposals->recommend($disposal, $comment, $actor),
            'approve' => $this->disposals->approve($disposal, $comment, $actor),
            'clear' => $this->disposals->clear($disposal, $comment, $actor),
            'reject' => $this->disposals->reject($disposal, $needsReason(), $actor),
            'cancel' => $this->disposals->cancel($disposal, $needsReason(), $actor),
            'execute' => $this->disposals->execute($disposal, $actor, ['method' => $data['method'] ?? '', 'witness' => $data['witness'] ?? null, 'destination' => $data['destination'] ?? '', 'reason' => $comment]),
            'verify' => $this->disposals->verify($disposal),
        }, __('Done.'));
    }

    public function certificate(Request $request, DestructionCertificate $certificate): View
    {
        $this->own($request, $certificate->institution_id);
        abort_unless($request->user()->mayAnywhere('delete', 'description'), 403);

        return view('retention.certificate', ['certificate' => $certificate, 'institution' => Institution::findOrFail($certificate->institution_id),
            'disposal' => DisposalAction::where('certificate_id', $certificate->id)->first()]);
    }

    private function attempt(callable $work, string $status): RedirectResponse
    {
        try {
            $work();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['retention' => $e->getMessage()]);
        }

        return back()->with('status', $status);
    }

    private function manages(Request $request): bool
    {
        return $request->user()->hasPermission(Vocab::permission('manage-institution'));
    }

    private function own(Request $request, string $institutionId): void
    {
        abort_unless($institutionId === $request->user()->institution_id, 404);
    }

    private function may(Request $request, RecordResource $record): void
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
        abort_unless($request->user()->may('delete', 'description', $record), 403);
    }
}
