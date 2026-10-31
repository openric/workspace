<?php

// OpenRiC Workspace - retention schedules, disposal classes and reviews (roadmap phase 13b, ADR 0015), after Heratio's
// ahg-records-manage RetentionScheduleService (create, update, approve, supersede), DisposalClassService (create,
// update, assignToRecord, unassignFromRecord, calculateDisposalDate, getAssignmentForRecord) and ReviewScheduleService
// (schedule, complete, assign, counts).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A record is given one disposal class. Its retention runs from the class's trigger: the end of the record's dates of
// creation or accumulation, the day the class is given, or a day given by hand for an event, such as a file's closing.
// The due date is that day plus the class's years and months, unless a reason is given for another. A record falls due
// on that date; a class that needs a review sends it to review first, and a review's decision to dispose or transfer
// starts the disposal, as Heratio's does. Heratio's retention policies of ahg-integrity, which queue records by their
// ingest date, are not carried: the class is the policy.

namespace App\Retention;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\DisposalAction;
use App\Models\DisposalClass;
use App\Models\Institution;
use App\Models\RecordDisposalClass;
use App\Models\RecordResource;
use App\Models\RetentionReview;
use App\Models\RetentionSchedule;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RetentionService
{
    public static function concept(string $vocabulary, string $notation): Concept
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->first() ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }

    /** @param array{reference: string, title: string, description?: ?string, authority?: ?string, jurisdiction?: ?string, effective_on?: ?string, review_on?: ?string, expires_on?: ?string} $data */
    public function saveSchedule(Institution $institution, array $data, ?RetentionSchedule $schedule, User $actor): RetentionSchedule
    {
        $fields = ['reference' => trim($data['reference']), 'title' => trim($data['title']), 'description' => $this->text($data, 'description'), 'authority' => $this->text($data, 'authority'),
            'jurisdiction' => $this->text($data, 'jurisdiction'), 'effective_on' => $this->text($data, 'effective_on'), 'review_on' => $this->text($data, 'review_on'),
            'expires_on' => $this->text($data, 'expires_on')];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $schedule ? tap($schedule)->update($fields)
            : RetentionSchedule::create([...$fields, 'institution_id' => $institution->id, 'status_id' => self::concept('retention-schedule-status', 'draft')->id, 'created_by_id' => $actor->id])));
    }

    /** Approve a draft under its authority, by the name of who approved it, as Heratio records it. */
    public function approve(RetentionSchedule $schedule, string $approvedBy, User $actor): void
    {
        $schedule->status->notation === 'draft' || throw new InvalidArgumentException(__('Only a draft is approved.'));
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $schedule->update(['status_id' => self::concept('retention-schedule-status', 'approved')->id,
            'approved_by' => trim($approvedBy), 'approved_at' => now()])));
    }

    /** A new draft version of an approved schedule, with its classes; the old one is superseded once the new is approved. */
    public function newVersion(RetentionSchedule $schedule, User $actor): RetentionSchedule
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($schedule, $actor) {
            $next = RetentionSchedule::create([...$schedule->only(['institution_id', 'reference', 'title', 'description', 'authority', 'jurisdiction', 'effective_on', 'review_on', 'expires_on']),
                'status_id' => self::concept('retention-schedule-status', 'draft')->id, 'version' => $schedule->version + 1, 'previous_id' => $schedule->id, 'created_by_id' => $actor->id]);
            foreach ($schedule->classes as $class) {
                DisposalClass::create([...$class->only(['reference', 'title', 'description', 'retention_years', 'retention_months', 'trigger_id', 'action_id', 'review_required', 'citation', 'is_active', 'position']),
                    'retention_schedule_id' => $next->id]);
            }

            return $next;
        }));
    }

    /** Approving a new version supersedes the one before. */
    public function approveVersion(RetentionSchedule $schedule, string $approvedBy, User $actor): void
    {
        $this->approve($schedule, $approvedBy, $actor);
        if ($schedule->previous) {
            AuditLog::actingAs($actor, fn () => $schedule->previous->update(['status_id' => self::concept('retention-schedule-status', 'superseded')->id]));
        }
    }

    /** @param array{reference: string, title: string, description?: ?string, retention_years?: ?int, retention_months?: ?int, trigger: string, action: string, review_required?: bool, citation?: ?string, is_active?: bool} $data */
    public function saveClass(RetentionSchedule $schedule, array $data, ?DisposalClass $class, User $actor): DisposalClass
    {
        $fields = ['reference' => trim($data['reference']), 'title' => trim($data['title']), 'description' => $this->text($data, 'description'),
            'retention_years' => ($data['retention_years'] ?? '') === '' || $data['retention_years'] === null ? null : (int) $data['retention_years'],
            'retention_months' => ($data['retention_months'] ?? '') === '' || $data['retention_months'] === null ? null : (int) $data['retention_months'],
            'trigger_id' => self::concept('retention-trigger', $data['trigger'])->id, 'action_id' => self::concept('disposal-action-type', $data['action'])->id,
            'review_required' => (bool) ($data['review_required'] ?? false), 'citation' => $this->text($data, 'citation'), 'is_active' => (bool) ($data['is_active'] ?? true)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $class ? tap($class)->update($fields)
            : DisposalClass::create([...$fields, 'retention_schedule_id' => $schedule->id, 'position' => (int) $schedule->classes()->max('position') + 1])));
    }

    /** Give a record its class, and work out when its retention ends. */
    public function assign(RecordResource $unit, DisposalClass $class, ?string $eventDate, User $actor): RecordDisposalClass
    {
        $class->schedule->institution_id === $unit->institution_id || throw new InvalidArgumentException(__('Choose a class of the institution\'s schedules.'));
        $class->schedule->status->notation === 'approved' || throw new InvalidArgumentException(__('Only a class of an approved schedule is given to records.'));
        $starts = $this->start($unit, $class, $eventDate);

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => RecordDisposalClass::updateOrCreate(['record_resource_id' => $unit->id], [
            'disposal_class_id' => $class->id, 'assigned_by_id' => $actor->id, 'retention_starts_on' => $starts?->toDateString(),
            'disposal_due_on' => $starts ? $this->due($class, $starts)?->toDateString() : null, 'override_due_on' => null, 'override_reason' => null])));
    }

    public function unassign(RecordResource $unit, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => RecordDisposalClass::where('record_resource_id', $unit->id)->get()->each->delete()));
    }

    /** Another due date than the class gives, with the reason; none clears it. */
    public function override(RecordDisposalClass $assignment, ?string $date, ?string $reason, User $actor): void
    {
        ($date === null || trim((string) $reason) !== '') || throw new InvalidArgumentException(__('Say why the record is due on another day.'));
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $assignment->update(['override_due_on' => $date, 'override_reason' => $date ? trim((string) $reason) : null])));
    }

    /** The day the retention ends: the class's period after its start. */
    public function due(DisposalClass $class, CarbonImmutable $starts): ?CarbonImmutable
    {
        if (! $class->retention_years && ! $class->retention_months) {
            return null;
        }

        return $starts->addYears((int) $class->retention_years)->addMonths((int) $class->retention_months);
    }

    /**
     * Records whose retention has ended and that are not already in a disposal or a review, oldest due first.
     *
     * @return Collection<int, RecordDisposalClass>
     */
    public function dueForDisposal(string $institutionId, ?CarbonImmutable $on = null): Collection
    {
        $day = ($on ?? CarbonImmutable::now(config('workspace.timezone')))->toDateString();
        $open = DisposalAction::whereHas('status', fn ($q) => $q->whereIn('iri', array_map(fn ($s) => Vocab::BASE.'disposal-status/'.$s, ['pending', 'recommended', 'approved', 'cleared', 'executed', 'retained'])))
            ->select('record_resource_id');

        return RecordDisposalClass::whereHas('record', fn ($q) => $q->where('institution_id', $institutionId)->whereNull('deleted_at'))
            ->whereRaw('coalesce(override_due_on, disposal_due_on) <= ?::date', [$day])->whereNotIn('record_resource_id', $open)
            ->whereNotIn('record_resource_id', RetentionReview::whereNull('completed_on')->select('record_resource_id'))
            ->with('record', 'disposalClass.action', 'disposalClass.schedule')->get()->sortBy(fn ($a) => ($a->override_due_on ?? $a->disposal_due_on)->toDateString())->values();
    }

    public function scheduleReview(RecordResource $unit, ?DisposalClass $class, string $dueOn, ?User $assignee, User $actor): RetentionReview
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => RetentionReview::create(['institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id,
            'disposal_class_id' => $class?->id, 'due_on' => $dueOn, 'assigned_to_id' => $assignee?->id, 'created_by_id' => $actor->id])));
    }

    /**
     * Decide a review: keep for now (with the next review's date), retain permanently, or start a disposal to
     * destroy or transfer, as Heratio's complete does.
     */
    public function decide(RetentionReview $review, string $decision, ?string $notes, ?string $nextDueOn, User $actor): RetentionReview
    {
        $review->completed_on === null || throw new InvalidArgumentException(__('This review is decided.'));
        ($decision !== 'retain' || $nextDueOn) || throw new InvalidArgumentException(__('Give the date of the next review.'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($review, $decision, $notes, $nextDueOn, $actor) {
            $action = in_array($decision, ['dispose', 'transfer'], true)
                ? app(DisposalService::class)->initiate($review->record, $decision === 'transfer' ? 'transfer' : 'destroy', __('Decided in the review of :date.', ['date' => $review->due_on->format('Y-m-d')]).($notes ? ' '.$notes : ''), $actor, $review->disposalClass)
                : null;
            $review->update(['completed_on' => now()->toDateString(), 'decision_id' => self::concept('review-decision', $decision)->id, 'decision_notes' => $notes ? trim($notes) : null,
                'next_due_on' => $decision === 'retain' ? $nextDueOn : null, 'disposal_action_id' => $action?->id]);
            if ($decision === 'retain') {
                $this->scheduleReview($review->record, $review->disposalClass, $nextDueOn, $review->assignedTo, $actor);
            }
            if ($decision === 'retain-permanently' && ($assignment = RecordDisposalClass::where('record_resource_id', $review->record_resource_id)->first())) {
                $assignment->update(['override_due_on' => null, 'disposal_due_on' => null, 'override_reason' => __('Retained permanently in the review of :date.', ['date' => $review->due_on->format('Y-m-d')])]);
            }

            return $review;
        }));
    }

    /** When the retention starts, by the class's trigger; null when the record gives no such date. */
    private function start(RecordResource $unit, DisposalClass $class, ?string $eventDate): ?CarbonImmutable
    {
        $latest = fn (string $property) => $unit->dates()->whereHas('property', fn ($q) => $q->where('notation', $property))->whereNotNull('latest')->max('latest');

        return match ($class->trigger->notation) {
            'creation-end' => ($d = $latest('creation')) ? CarbonImmutable::parse($d) : null,
            'accumulation-end' => ($d = $latest('accumulation')) ? CarbonImmutable::parse($d) : null,
            'assignment' => CarbonImmutable::now(config('workspace.timezone'))->startOfDay(),
            'event' => $eventDate ? CarbonImmutable::parse($eventDate) : throw new InvalidArgumentException(__('Give the day of the event the retention runs from.')),
            default => null,
        };
    }

    private function text(array $data, string $key): ?string
    {
        return ($v = trim((string) ($data[$key] ?? ''))) === '' ? null : $v;
    }
}
