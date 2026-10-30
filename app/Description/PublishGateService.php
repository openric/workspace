<?php

/**
 * PublishGateService - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from PSIS's atom-ahg-plugins/ahgWorkflowPlugin/lib/Services/PublishGateService.php and
 * database/migrations/004_publish_gates.sql at 15fc8f64, with Heratio's ahg-workflow evaluateGates,
 * isWorkflowRequiredForPublish and isWorkflowApprovedForPublish at 7663253e9 (governance/CODE_PROVENANCE.md, roadmap
 * phase 10b): the rules and their seeds, evaluate, canPublish, getBlockers, the result history and simulatePublish.
 * Changed: the Workspace's description elements for the field rules; rights in force and files on instantiations;
 * security clearance and IIIF pass until phases 18 and 14 bring them; Heratio's custom SQL rule is not carried, as
 * it ran SQL typed into a form; the seeded rules start switched off.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Institution;
use App\Models\PublishGateResult;
use App\Models\PublishGateRule;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Models\WorkflowTask;
use App\Rights\RightsService;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PublishGateService
{
    /** PSIS's seeded rules: name, type, element, message, severity. */
    public const SEEDS = [
        ['Title required', 'field-required', 'title', 'A title is required before publishing', 'blocker'],
        ['Scope and content not empty', 'field-not-empty', 'scope-and-content', 'Scope and content should be filled in before publishing', 'warning'],
        ['At least one digital object', 'has-digital-object', null, 'At least one digital object should be attached', 'warning'],
        ['Rights statement assigned', 'has-rights', null, 'A rights statement should be assigned before publishing', 'warning'],
        ['Access conditions set', 'has-access-condition', 'conditions-governing-access', 'Access conditions must be defined before publishing', 'blocker'],
        ['Security clearance passed', 'security-cleared', null, 'Security clearance check must pass before publishing', 'blocker'],
    ];

    /** The institution's rules; one that has none is given PSIS's six, switched off. */
    public function rules(string $institutionId): Collection
    {
        if (! PublishGateRule::where('institution_id', $institutionId)->exists()) {
            foreach (self::SEEDS as $i => [$name, $type, $field, $message, $severity]) {
                PublishGateRule::create(['institution_id' => $institutionId, 'name' => $name, 'rule_type_id' => $this->term('publish-gate-rule-type', $type)->id,
                    'field_name' => $field, 'error_message' => $message, 'severity_id' => $this->term('publish-gate-severity', $severity)->id, 'is_active' => false,
                    'position' => ($i + 1) * 10]);
            }
        }

        return PublishGateRule::where('institution_id', $institutionId)->with('ruleType.labels', 'severity.labels', 'level.labels', 'repository.names.nameType')
            ->orderBy('position')->orderBy('name')->get();
    }

    /**
     * Add or change a rule. Fields: name, rule_type and severity (notations), level (a key), repository (a key),
     * field_name (an element notation), min_length, error_message, position, is_active.
     */
    public function saveRule(Institution $institution, ?PublishGateRule $rule, array $fields, User $actor): PublishGateRule
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $type = $this->term('publish-gate-rule-type', (string) $text('rule_type'));
        $field = $text('field_name');
        if (in_array($type->notation, ['field-required', 'field-not-empty', 'min-description-length'], true)) {
            $field !== null && in_array($field, Elements::notations(Elements::ISAD_G), true) || throw new InvalidArgumentException(__('choose the element it checks'));
        }
        $min = $text('min_length');
        $type->notation !== 'min-description-length' || ($min !== null && ctype_digit($min) && (int) $min > 0) || throw new InvalidArgumentException(__('give the least number of characters'));
        $values = ['name' => $text('name') ?? throw new InvalidArgumentException(__('give the rule a name')), 'rule_type_id' => $type->id,
            'severity_id' => $this->term('publish-gate-severity', $text('severity') ?? 'blocker')->id, 'field_name' => $field, 'min_length' => $min === null ? null : (int) $min,
            'level_id' => $text('level') === null ? null : Concept::findOrFail($text('level'))->id,
            'repository_id' => $text('repository') === null ? null : Repository::where('institution_id', $institution->id)->findOrFail($text('repository'))->id,
            'error_message' => $text('error_message') ?? throw new InvalidArgumentException(__('give the message shown when it fails')),
            'position' => (int) ($text('position') ?? 0), 'is_active' => ! array_key_exists('is_active', $fields) || (bool) $fields['is_active']];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $rule ? tap($rule)->update($values)
            : PublishGateRule::create([...$values, 'institution_id' => $institution->id])));
    }

    /**
     * PSIS's evaluate: each active rule that applies to the description (its level and repository), passed, failed or a
     * warning, with the institution's requirement of an approved workflow as one more blocker; each result is kept.
     *
     * @return list<array{rule: ?PublishGateRule, name: string, status: string, severity: string, message: ?string}>
     */
    public function evaluate(RecordResource $record, ?User $actor = null): array
    {
        $results = [];
        foreach ($this->rules($record->institution_id)->where('is_active', true) as $rule) {
            if (($rule->level_id && $rule->level_id !== $record->level_id) || ($rule->repository_id && $rule->repository_id !== $record->repository_id)) {
                continue;
            }
            $passed = $this->check($rule, $record);
            $severity = $rule->severity->notation;
            $status = $passed ? 'passed' : ($severity === 'warning' ? 'warning' : 'failed');
            PublishGateResult::create(['institution_id' => $record->institution_id, 'record_resource_id' => $record->id, 'rule_id' => $rule->id, 'status' => $status,
                'details' => $passed ? null : $rule->error_message, 'evaluated_at' => now(), 'evaluated_by_id' => $actor?->id]);
            $results[] = ['rule' => $rule, 'name' => $rule->name, 'status' => $status, 'severity' => $severity, 'message' => $passed ? null : $rule->error_message];
        }
        if (Institution::whereKey($record->institution_id)->value('workflow_required_for_publish')) {
            $approved = $this->workflowApproved($record);
            $results[] = ['rule' => null, 'name' => __('An approved workflow'), 'status' => $approved ? 'passed' : 'failed', 'severity' => 'blocker',
                'message' => $approved ? null : __('The institution publishes a description only once a workflow for it is approved')];
        }

        return $results;
    }

    /** PSIS's getBlockers: the failed blockers. */
    public function blockers(RecordResource $record, ?User $actor = null): array
    {
        return array_values(array_filter($this->evaluate($record, $actor), fn ($r) => $r['status'] === 'failed'));
    }

    /**
     * Heratio's isWorkflowApprovedForPublish: a workflow was started for it, no task is open, and the last task decided
     * was approved.
     */
    public function workflowApproved(RecordResource $record): bool
    {
        $tasks = WorkflowTask::where(['subject_table' => 'record_resources', 'subject_id' => $record->id])->with('status', 'decision')->orderByDesc('updated_at')->get();

        return $tasks->isNotEmpty() && $tasks->every(fn ($t) => ! $t->isOpen()) && $tasks->first()->decision->notation === 'approved';
    }

    /** PSIS's getResultHistory: the latest evaluations of the description. */
    public function history(RecordResource $record, int $limit = 50): Collection
    {
        return PublishGateResult::where('record_resource_id', $record->id)->with('rule')->orderByDesc('evaluated_at')->limit($limit)->get();
    }

    /**
     * PSIS's simulatePublish: what the public would see of the description once published, in the language given:
     * its title, reference code, dates, scope and content, access conditions, repository, the files it would offer and
     * its rights in force.
     */
    public function simulate(RecordResource $record, string $language): array
    {
        $languages = [$language, 'en'];

        return ['title' => $record->described('title', $languages), 'reference' => $record->referenceCode() ?? $record->identifier,
            'dates' => $record->dates->map(fn ($d) => $d->display())->filter()->values()->all(), 'scope' => $record->described('scope-and-content', $languages),
            'access' => $record->described('conditions-governing-access', $languages),
            'repository' => $record->repository?->described('authorised-name', $languages),
            'files' => DigitalFile::whereIn('instantiation_id', $record->instantiations()->whereNull('deleted_at')->select('id'))->count(),
            'rights' => app(RightsService::class)->of($record)->filter(fn ($r) => $r->inForce())->map(fn ($r) => $r->basis->label($languages))->values()->all()];
    }

    private function check(PublishGateRule $rule, RecordResource $record): bool
    {
        $raw = fn (?string $notation) => $notation === null ? null : $record->described($notation, ['en', ...Vocab::languages()]);
        $value = fn (?string $notation) => trim((string) $raw($notation));

        return match ($rule->ruleType->notation) {
            // PSIS: required is a value given at all; not empty is a value that is not blank.
            'field-required' => $raw($rule->field_name) !== null,
            'field-not-empty' => $value($rule->field_name) !== '',
            'min-description-length' => mb_strlen($value($rule->field_name)) >= (int) $rule->min_length,
            'has-digital-object' => DigitalFile::whereIn('instantiation_id', $record->instantiations()->whereNull('deleted_at')->select('id'))->exists(),
            'has-rights' => app(RightsService::class)->of($record)->contains(fn ($r) => $r->inForce()),
            'has-access-condition' => $value($rule->field_name ?? 'conditions-governing-access') !== '',
            'workflow-completed' => ! app(WorkflowService::class)->open()->where(['subject_table' => 'record_resources', 'subject_id' => $record->id])->exists(),
            // ponytail: security clearance (phase 18) and IIIF (phase 14) are not in the Workspace yet; their rules pass until they are.
            'security-cleared', 'iiif-ready' => true,
            default => true,
        };
    }

    private function term(string $vocabulary, string $notation): Concept
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
