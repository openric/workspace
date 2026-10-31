<?php

// OpenRiC Workspace - POPIA governance (roadmap phase 18a, ADR 0016), after Heratio's ahg-privacy PrivacyService and
// PrivacyController: entries of the registers saved with their references and due dates, the processing register's
// submission and decision, an impact assessment's sign-off, consent withdrawn, a request's identity verified, the
// notices of a security compromise, and the POPIA report.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A data subject request is due the jurisdiction's days after it is received (POPIA: 30, as PAIA s.25); a PAIA request
// 30 days after (PAIA ss.25 and 56), and 30 more once extended (s.26). A security compromise's Regulator must be told as
// soon as reasonably possible (POPIA s.22(2)), within the jurisdiction's hours where it sets them. Statuses start at
// their register's start: received, draft or detected.

namespace App\Privacy;

use App\Audit\AuditLog;
use App\Models\CompromiseNotice;
use App\Models\Concept;
use App\Models\ConsentRecord;
use App\Models\ImpactAssessment;
use App\Models\Institution;
use App\Models\PaiaRequest;
use App\Models\PrivacyJurisdiction;
use App\Models\PrivacyOfficer;
use App\Models\PrivacyRequest;
use App\Models\ProcessingActivity;
use App\Models\SecurityCompromise;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PrivacyService
{
    public const PAIA_DAYS = 30;

    public static function concept(string $vocabulary, string $notation): Concept
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->first() ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }

    /** Save an entry of a register: its fields checked against the definition, with its reference, first status and due date when new. */
    public function save(string $register, Institution $institution, array $input, ?Model $entry, User $actor): Model
    {
        $definition = Registers::get($register);
        $values = [];
        foreach ($definition['fields'] as $field => $spec) {
            [$type, $label] = $spec;
            $raw = $input[$field] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;
            if (($spec[2] ?? null) === 'required' && ($raw === null || $raw === '')) {
                throw new InvalidArgumentException(__(':field is required.', ['field' => __($label)]));
            }
            $values[$field] = match ($type) {
                'bool' => (bool) $raw,
                'vocab' => $raw ? self::concept($spec[3], $raw)->id : null,
                'jurisdiction' => $raw ? PrivacyJurisdiction::where('code', $raw)->value('id') ?? throw new InvalidArgumentException(__('Choose a jurisdiction.')) : null,
                'user' => $raw ? User::where(['id' => $raw, 'institution_id' => $institution->id])->value('id') ?? throw new InvalidArgumentException(__('Choose an account of the institution.')) : null,
                'officer' => $raw ? PrivacyOfficer::where(['id' => $raw, 'institution_id' => $institution->id])->value('id') : null,
                'processing' => $raw ? ProcessingActivity::where(['id' => $raw, 'institution_id' => $institution->id])->value('id') : null,
                'number' => $raw === null || $raw === '' ? null : (int) $raw,
                'money' => $raw === null || $raw === '' ? null : round((float) $raw, 2),
                default => $raw === '' ? null : $raw,
            };
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($register, $definition, $institution, $values, $entry, $actor) {
            if ($entry) {
                $values += $this->due($register, $values, $entry);
                $entry->update($values);

                return $entry;
            }
            $class = $definition['model'];
            $values['institution_id'] = $institution->id;
            if (isset($definition['prefix'])) {
                $values['reference'] = $this->reference($class, $definition['prefix']);
            }
            if (isset($definition['status'])) {
                $values['status_id'] = self::concept($definition['status'], $definition['start'])->id;
            }
            foreach (['created_by_id', 'recorded_by_id'] as $by) {
                if (in_array($by, (new $class)->getFillable(), true)) {
                    $values[$by] = $actor->id;
                }
            }

            return $class::create($values + $this->due($register, $values, null));
        }));
    }

    /** Move an entry to a status of its vocabulary; completing a request dates it. */
    public function status(Model $entry, string $register, string $status, User $actor): void
    {
        $definition = Registers::get($register);
        isset($definition['status']) || throw new InvalidArgumentException(__('This register has no status.'));
        $fields = ['status_id' => self::concept($definition['status'], $status)->id];
        if ($entry instanceof PaiaRequest && $status === 'extended') {
            $fields['due_on'] = $entry->due_on->addDays(self::PAIA_DAYS)->toDateString();
        }
        if (in_array($status, ['granted', 'partly-granted', 'refused', 'completed', 'transferred'], true) && in_array('completed_on', $entry->getFillable(), true) && ! $entry->completed_on) {
            $fields['completed_on'] = now(config('workspace.timezone'))->toDateString();
        }
        if ($entry instanceof SecurityCompromise && $status === 'contained' && ! $entry->contained_at) {
            $fields['contained_at'] = now();
        }
        if ($entry instanceof SecurityCompromise && $status === 'resolved' && ! $entry->resolved_at) {
            $fields['resolved_at'] = now();
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $entry->update($fields)));
    }

    /** The processing register's workflow: submit a draft; the Information Officer approves or rejects it with the reason. */
    public function submit(ProcessingActivity $activity, User $actor): void
    {
        in_array($activity->status->notation, ['draft', 'rejected'], true) || throw new InvalidArgumentException(__('Only a draft is submitted.'));
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $activity->update(['status_id' => self::concept('processing-register-status', 'submitted')->id,
            'submitted_at' => now(), 'submitted_by_id' => $actor->id, 'rejection_reason' => null])));
    }

    public function decide(ProcessingActivity $activity, bool $approve, ?string $reason, User $actor): void
    {
        $activity->status->notation === 'submitted' || throw new InvalidArgumentException(__('Only a submitted entry is decided.'));
        $approve || trim((string) $reason) !== '' || throw new InvalidArgumentException(__('Give the reason.'));
        $actor->id !== $activity->submitted_by_id || throw new InvalidArgumentException(__('Someone other than who submitted it decides it.'));
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $activity->update(['status_id' => self::concept('processing-register-status', $approve ? 'approved' : 'rejected')->id,
            'decided_at' => now(), 'decided_by_id' => $actor->id, 'rejection_reason' => $approve ? null : trim((string) $reason)])));
    }

    public function signOff(ImpactAssessment $assessment, User $actor): void
    {
        in_array($assessment->status->notation, ['draft', 'in-review'], true) || throw new InvalidArgumentException(__('Only an assessment not yet signed off is signed off.'));
        trim((string) $assessment->measures) !== '' || throw new InvalidArgumentException(__('Record the measures before signing it off.'));
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $assessment->update(['status_id' => self::concept('dpia-status', 'signed-off')->id,
            'signed_off_by_id' => $actor->id, 'signed_off_at' => now()])));
    }

    public function withdraw(ConsentRecord $consent, User $actor): void
    {
        $consent->withdrawn_at === null || throw new InvalidArgumentException(__('Already withdrawn.'));
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $consent->update(['withdrawn_at' => now()])));
    }

    public function verify(PrivacyRequest $request, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $request->update(['verified_at' => now(), 'verified_by_id' => $actor->id])));
    }

    /** @param array{recipient: string, method: string, recipient_name?: ?string, sent_at?: ?string, content?: ?string} $data */
    public function notify(SecurityCompromise $compromise, array $data, User $actor): CompromiseNotice
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => CompromiseNotice::create(['security_compromise_id' => $compromise->id,
            'recipient_id' => self::concept('notification-recipient', $data['recipient'])->id, 'method_id' => self::concept('notification-method', $data['method'])->id,
            'recipient_name' => trim((string) ($data['recipient_name'] ?? '')) ?: null, 'sent_at' => ($data['sent_at'] ?? '') ?: now(),
            'content' => trim((string) ($data['content'] ?? '')) ?: null, 'created_by_id' => $actor->id])));
    }

    /**
     * When a compromise's Regulator must be told: the jurisdiction's hours after detection, or null where POPIA sets
     * none (as soon as reasonably possible). Whether the Regulator has been told.
     *
     * @return array{deadline: ?CarbonImmutable, told: bool}
     */
    public function regulatorNotice(SecurityCompromise $compromise): array
    {
        $hours = $compromise->jurisdiction->breach_notice_hours;
        $told = $compromise->notices()->whereHas('recipient', fn ($q) => $q->where('iri', Vocab::BASE.'notification-recipient/regulator'))->exists();

        return ['deadline' => $hours ? $compromise->detected_at->addHours($hours) : null, 'told' => $told];
    }

    /**
     * The POPIA report: what the Information Regulator, an auditor or the board would ask, each with its evidence.
     *
     * @return list<array{check: string, ok: bool, detail: string}>
     */
    public function report(Institution $institution): array
    {
        $id = $institution->id;
        $today = now(config('workspace.timezone'))->toDateString();
        $open = fn ($q) => $q->whereHas('status', fn ($s) => $s->whereNotIn('iri', array_map(fn ($n) => Vocab::BASE.'privacy-request-status/'.$n, ['granted', 'partly-granted', 'refused', 'completed', 'transferred', 'withdrawn'])));
        $officer = PrivacyOfficer::where(['institution_id' => $id, 'is_active' => true, 'is_deputy' => false])->first();
        $activities = ProcessingActivity::where('institution_id', $id)->with('status')->get();
        $needAssessment = $activities->filter(fn ($a) => $a->impact_assessment_required || $a->special_information || $a->children);
        $assessed = ImpactAssessment::where('institution_id', $id)->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'dpia-status/signed-off'))->pluck('processing_activity_id')->filter()->all();
        $overdueRequests = PrivacyRequest::where('institution_id', $id)->where('due_on', '<', $today)->where($open)->count();
        $overduePaia = PaiaRequest::where('institution_id', $id)->where('due_on', '<', $today)->where($open)->count();
        $untold = SecurityCompromise::where(['institution_id' => $id, 'notification_required' => true])->get()->reject(fn ($c) => $this->regulatorNotice($c)['told'])->count();
        $reviewDue = $activities->filter(fn ($a) => $a->next_review_on && $a->next_review_on->toDateString() < $today)->count();

        return [
            ['check' => 'An Information Officer is appointed and registered with the Regulator (POPIA s.55, PAIA s.17)', 'ok' => $officer !== null && $officer->registration_number !== null,
                'detail' => $officer ? $officer->name.($officer->registration_number ? ', '.$officer->registration_number : ', '.__('not registered')) : __('none appointed')],
            ['check' => 'The processing of personal information is documented (POPIA s.17)', 'ok' => $activities->isNotEmpty() && $activities->every(fn ($a) => $a->status->notation === 'approved'),
                'detail' => __(':approved of :total approved', ['approved' => $activities->filter(fn ($a) => $a->status->notation === 'approved')->count(), 'total' => $activities->count()])],
            ['check' => 'Processing with special or children\'s information, or marked for it, has a signed-off impact assessment', 'ok' => $needAssessment->every(fn ($a) => in_array($a->id, $assessed, true)),
                'detail' => __(':done of :total', ['done' => $needAssessment->filter(fn ($a) => in_array($a->id, $assessed, true))->count(), 'total' => $needAssessment->count()])],
            ['check' => 'Processing entries are reviewed on time', 'ok' => $reviewDue === 0, 'detail' => __(':count past their review date', ['count' => $reviewDue])],
            ['check' => 'Data subject requests are answered in time (POPIA s.23, s.24)', 'ok' => $overdueRequests === 0, 'detail' => __(':count overdue', ['count' => $overdueRequests])],
            ['check' => 'PAIA requests are answered in time (PAIA ss.25, 56)', 'ok' => $overduePaia === 0, 'detail' => __(':count overdue', ['count' => $overduePaia])],
            ['check' => 'Every security compromise that must be reported has been reported to the Regulator (POPIA s.22)', 'ok' => $untold === 0, 'detail' => __(':count not yet reported', ['count' => $untold])],
        ];
    }

    /** Received on plus the jurisdiction's days (a data subject request) or 30 (PAIA); kept when the entry has one. */
    private function due(string $register, array $values, ?Model $entry): array
    {
        if (! in_array($register, ['requests', 'paia'], true) || empty($values['received_on']) || ($entry && $entry->received_on?->toDateString() === $values['received_on'])) {
            return [];
        }
        $days = $register === 'paia' ? self::PAIA_DAYS : PrivacyJurisdiction::findOrFail($values['jurisdiction_id'])->request_days;

        return ['due_on' => CarbonImmutable::parse($values['received_on'])->addDays($days)->toDateString()];
    }

    /** PREFIX-YYYY-NNNN, numbered within the year. */
    private function reference(string $class, string $prefix): string
    {
        $start = $prefix.'-'.now(config('workspace.timezone'))->format('Y').'-';
        $last = $class::where('reference', 'like', $start.'%')->lockForUpdate()->orderByDesc('reference')->value('reference');

        return $start.str_pad((string) ($last ? (int) substr($last, strlen($start)) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
