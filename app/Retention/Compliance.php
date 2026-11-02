<?php

// OpenRiC Workspace - records management compliance assessments against ISO 15489, ISO 16175, MoReq2010, DoD 5015.2,
// ISO 30300 and ISO 23081 (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ComplianceReportService: its frameworks, its check references and labels, its weights, and its
// score (a pass counts in full, a warning half). Changed: most of Heratio's checks asked whether a table existed,
// which every installation passes; here each reads the institution's own records management, so a check passes only
// when the institution does what it names: an approved schedule, an active file plan with disposal classes, records
// declared in the period, disposals approved and cleared before they were carried out, a hash-chained audit log that
// verifies, vital records reviewed on time, creators and dates on records. Where the Workspace has nothing to check
// (legal holds, which it does not keep), the check says so and does not pass. An assessment is run again until it
// is signed off; then it is final.

namespace App\Retention;

use App\Audit\AuditLog;
use App\Models\ComplianceAssessment;
use App\Models\Concept;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Compliance
{
    public function create(User $actor, array $data): ComplianceAssessment
    {
        foreach (['reference' => __('Give the assessment a reference.'), 'title' => __('Give the assessment a title.')] as $k => $message) {
            if (trim((string) ($data[$k] ?? '')) === '') {
                throw new InvalidArgumentException($message);
            }
        }
        if (ComplianceAssessment::where(['institution_id' => $actor->institution_id, 'reference' => trim($data['reference'])])->exists()) {
            throw new InvalidArgumentException(__('Another assessment has that reference.'));
        }
        $from = ($data['period_start'] ?? '') ?: null;
        $to = ($data['period_end'] ?? '') ?: null;
        if ($from && $to && $to < $from) {
            throw new InvalidArgumentException(__('The end date comes before the start.'));
        }

        $assessment = DB::transaction(fn () => AuditLog::actingAs($actor, fn () => ComplianceAssessment::create(['institution_id' => $actor->institution_id,
            'framework_id' => $this->concept('compliance-framework', (string) ($data['framework'] ?? 'iso-15489')), 'reference' => mb_substr(trim($data['reference']), 0, 100),
            'title' => mb_substr(trim($data['title']), 0, 255), 'scope' => trim((string) ($data['scope'] ?? '')) ?: null, 'period_start' => $from, 'period_end' => $to,
            'status_id' => $this->concept('assessment-status', 'in-progress'), 'assessed_by_id' => $actor->id, 'assessed_at' => now()])));
        $this->run($actor, $assessment);

        return $assessment->fresh();
    }

    /** Run the framework's checks and keep the findings, recommendations and score. */
    public function run(User $actor, ComplianceAssessment $a): void
    {
        if ($a->status->notation === 'finalised') {
            throw new InvalidArgumentException(__('It is signed off; it no longer changes.'));
        }
        $checks = $this->checks($a);
        $max = array_sum(array_column($checks, 'weight'));
        $score = array_sum(array_map(fn ($c) => $c['status'] === 'pass' ? $c['weight'] : ($c['status'] === 'warn' ? $c['weight'] / 2 : 0), $checks));
        $recommendations = collect($checks)->filter(fn ($c) => $c['status'] !== 'pass' && $c['recommendation'])->map(fn ($c) => ['ref' => $c['ref'], 'recommendation' => $c['recommendation']])->values()->all();
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $a->update(['findings' => $checks, 'recommendations' => $recommendations, 'score_total' => $score, 'score_max' => $max,
            'assessed_by_id' => $actor->id, 'assessed_at' => now()])));
    }

    public function signOff(User $actor, ComplianceAssessment $a, string $by): void
    {
        if ($a->status->notation === 'finalised') {
            throw new InvalidArgumentException(__('It is signed off already.'));
        }
        if (trim($by) === '') {
            throw new InvalidArgumentException(__('Name who signs it off.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $a->update(['status_id' => $this->concept('assessment-status', 'finalised'), 'signed_off_by' => mb_substr(trim($by), 0, 255),
            'signed_off_at' => now()])));
    }

    public static function percent(ComplianceAssessment $a): ?int
    {
        return $a->score_max > 0 ? (int) round($a->score_total / $a->score_max * 100) : null;
    }

    private function checks(ComplianceAssessment $a): array
    {
        $i = $a->institution_id;
        $from = $a->period_start?->startOfDay() ?? now()->subDays(90);
        $to = $a->period_end?->endOfDay() ?? now();
        $records = DB::table('record_resources')->where('institution_id', $i)->whereNull('deleted_at');
        $nodes = DB::table('file_plan_nodes')->where('institution_id', $i);
        $classes = DB::table('disposal_classes as c')->join('retention_schedules as s', 's.id', '=', 'c.retention_schedule_id')->where('s.institution_id', $i);
        $disposals = DB::table('disposal_actions as d')->join('concepts as st', 'st.id', '=', 'd.status_id')->where('d.institution_id', $i);
        $executed = (clone $disposals)->where('st.iri', Vocab::BASE.'disposal-status/executed');
        $status = fn ($q, string $vocabulary, string $notation) => $q->whereExists(fn ($e) => $e->from('concepts')->whereColumn('concepts.id', 'status_id')->where('iri', Vocab::BASE."$vocabulary/$notation"));
        $declared = $status(DB::table('record_declarations')->whereIn('record_resource_id', (clone $records)->select('id')), 'declaration-status', 'declared');
        $recordCount = (clone $records)->count();
        $classified = DB::table('record_disposal_classes')->whereIn('record_resource_id', (clone $records)->select('id'))->count();
        $placed = DB::table('file_plan_placements')->whereIn('record_resource_id', (clone $records)->select('id'))->count();
        $share = fn (int $n) => $recordCount ? $n / $recordCount : 0;
        $auditIntact = rescue(fn () => Artisan::call('workspace:audit:verify') === 0, false, false);
        $approvedSchedule = $status(DB::table('retention_schedules')->where('institution_id', $i), 'retention-schedule-status', 'approved')->exists();
        $vitalOverdue = DB::table('vital_records')->whereIn('record_resource_id', (clone $records)->select('id'))->where('is_active', true)->where('next_review_on', '<', now()->toDateString())->count();
        $withCreator = DB::table('relations as r')->join('concepts as t', 't.id', '=', 'r.relation_type_id')->where('r.institution_id', $i)->where('r.source_table', 'record_resources')
            ->whereIn('t.notation', ['creation', 'accumulation'])->distinct()->count('r.source_id');
        $withDates = DB::table('dates')->where('subject_table', 'record_resources')->whereIn('subject_id', (clone $records)->select('id'))->distinct()->count('subject_id');
        $languages = DB::table('description_values')->where('subject_table', 'record_resources')->whereIn('subject_id', (clone $records)->select('id'))->distinct()->count('language');
        $languages = $languages > 1 ? $languages : 0;
        $c = fn (string $ref, string $label, bool|string $result, string $finding, ?string $fix, int $weight) => ['ref' => $ref, 'label' => $label, 'weight' => $weight,
            'status' => is_string($result) ? $result : ($result ? 'pass' : 'fail'), 'finding' => $finding, 'recommendation' => $result === true ? null : $fix];

        return match ($a->framework->notation) {
            'iso-15489' => [
                $c('15489-5', __('An approved retention schedule'), $approvedSchedule, $approvedSchedule ? __('An approved schedule is in force.') : __('No retention schedule is approved.'), __('Approve the retention schedule under its disposal authority (ISO 15489-1, 9.9).'), 3),
                $c('15489-6', __('A file plan classifies the records'), $nodes->exists() && $placed > 0, __(':placed of :count records have a place in the file plan.', ['placed' => $placed, 'count' => $recordCount]), __('Build the file plan from the institution\'s functions and place records in it (ISO 15489-1, 8.3).'), 3),
                $c('15489-7', __('Disposal classes are defined'), $classes->where('c.is_active', true)->exists(), __(':n active disposal classes.', ['n' => $classes->where('c.is_active', true)->count()]), __('Define disposal classes in the schedule.'), 2),
                $c('15489-8', __('Records are declared in the period'), (clone $declared)->whereBetween('declared_at', [$from, $to])->exists(), __(':n records declared in the period.', ['n' => (clone $declared)->whereBetween('declared_at', [$from, $to])->count()]), __('Declare records as they are made or received, so they are captured while in use.'), 2),
                $c('15489-9', __('Disposals are carried out'), ! (clone $disposals)->exists() || $executed->exists() ? true : 'warn', __(':n disposals carried out.', ['n' => $executed->count()]), __('Carry out the approved disposals that are due.'), 1),
                $c('15489-10', __('An audit trail that can be trusted'), $auditIntact, $auditIntact ? __('The hash-chained audit log verifies.') : __('The audit log does not verify.'), __('Find why the audit chain is broken before anything else (ISO 15489-1, 5.2.2).'), 3),
            ],
            'iso-16175' => [
                $c('16175-1', __('The system has a classification scheme'), $nodes->exists(), __(':n file plan nodes.', ['n' => $nodes->count()]), __('Build the file plan.'), 2),
                $c('16175-2', __('Records carry retention and disposal metadata'), $share($classified) >= .9 ? true : ($classified > 0 ? 'warn' : false), __(':n of :count records have a disposal class.', ['n' => $classified, 'count' => $recordCount]), __('Give every record a disposal class, by its place in the file plan or by rules.'), 3),
                $c('16175-3', __('An audit trail of system events'), $auditIntact, $auditIntact ? __('The hash-chained audit log verifies.') : __('The audit log does not verify.'), __('Find why the audit chain is broken.'), 2),
            ],
            'moreq2010' => [
                $c('M2-CORE-1', __('Aggregations are organised hierarchically'), (clone $nodes)->whereNotNull('parent_id')->exists(), __('Nested file plan nodes: :n.', ['n' => (clone $nodes)->whereNotNull('parent_id')->count()]), __('Nest the file plan by function, activity and series.'), 2),
                $c('M2-DISP-1', __('Disposal schedules link to aggregations'), (clone $nodes)->whereNotNull('disposal_class_id')->exists(), __('File plan nodes with a disposal class: :n.', ['n' => (clone $nodes)->whereNotNull('disposal_class_id')->count()]), __('Give the file plan\'s nodes their disposal classes.'), 2),
                $c('M2-AUDIT-1', __('The audit trail is append-only and complete'), $auditIntact, $auditIntact ? __('The hash-chained audit log verifies.') : __('The audit log does not verify.'), __('Find why the audit chain is broken.'), 3),
                $c('M2-RETENT-1', __('Records carry retention metadata'), $classified > 0 ? ($share($classified) >= .9 ? true : 'warn') : false, __(':n of :count records have a disposal class.', ['n' => $classified, 'count' => $recordCount]), __('Give every record a disposal class.'), 2),
                $c('M2-DESTR-1', __('Destruction leaves a certificate'), ! $executed->exists() || DB::table('destruction_certificates')->where('institution_id', $i)->exists(), __(':n destruction certificates.', ['n' => DB::table('destruction_certificates')->where('institution_id', $i)->count()]), __('Carry out destruction through the disposal workflow, which issues the certificate.'), 2),
            ],
            'dod-5015-2' => [
                $c('DoD-C2.1', __('Records can be declared and are then protected'), (clone $declared)->exists(), __(':n declared records, none deletable outside disposal.', ['n' => (clone $declared)->count()]), __('Declare records; a declared record can then be removed only by its disposal.'), 3),
                $c('DoD-C2.2', __('Disposals track their approval chain'), ! (clone $executed)->whereNull('d.approved_by_id')->exists(), __('Disposals carried out without an approver: :n.', ['n' => (clone $executed)->whereNull('d.approved_by_id')->count()]), __('Approve every disposal before it is carried out.'), 3),
                $c('DoD-C2.3', __('A legal hold mechanism'), 'warn', __('The Workspace keeps no legal holds; a disposal is cleared by someone other than who approved it, who confirms that none applies.'), __('Record legal holds where the institution keeps them, and clear disposals against them.'), 2),
                $c('DoD-C2.4', __('Disposal waits for legal clearance'), ! (clone $executed)->whereNull('d.cleared_by_id')->exists(), __('Disposals carried out without clearance: :n.', ['n' => (clone $executed)->whereNull('d.cleared_by_id')->count()]), __('Clear every disposal before it is carried out.'), 3),
                $c('DoD-C2.5', __('Vital records are identified and reviewed'), DB::table('vital_records')->whereIn('record_resource_id', (clone $records)->select('id'))->where('is_active', true)->exists() ? ($vitalOverdue ? 'warn' : true) : false,
                    __('Vital records overdue for review: :n.', ['n' => $vitalOverdue]), __('Flag the records the institution needs to go on working, and review them on time.'), 2),
            ],
            'iso-30300' => [
                $c('30300-1', __('The management system states its scope'), trim((string) $a->scope) !== '', trim((string) $a->scope) !== '' ? __('The scope is stated.') : __('No scope is stated.'), __('State the scope of the management system for records (clause 4.3).'), 2),
                $c('30300-2', __('Evidence of periodic review'), ComplianceAssessment::where('institution_id', $i)->whereKeyNot($a->id)->where('assessed_at', '>=', now()->subYear())->exists(), __('Other assessments in the last twelve months.'), __('Assess at least once a year (clause 9).'), 2),
            ],
            'iso-23081' => [
                $c('23081-1', __('Records carry their creators'), $recordCount && $withCreator / $recordCount >= .5 ? true : ($withCreator ? 'warn' : false), __(':n of :count records name a creator or accumulator.', ['n' => $withCreator, 'count' => $recordCount]), __('Relate each record to the agent that created or accumulated it.'), 3),
                $c('23081-2', __('Records carry their dates'), $recordCount && $withDates / $recordCount >= .5 ? true : ($withDates ? 'warn' : false), __(':n of :count records have dates.', ['n' => $withDates, 'count' => $recordCount]), __('Give each record its dates of creation or accumulation.'), 2),
                $c('23081-3', __('Descriptions in more than one language are kept'), $languages > 1 ? true : 'warn', __('Languages of description: :n.', ['n' => max(1, $languages)]), __('Describe in the institution\'s languages where it has more than one.'), 1),
            ],
        };
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
