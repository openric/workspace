<?php

// OpenRiC Workspace - rules that place records and captured e-mail in the file plan (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ClassificationRuleService. Rules are tried highest priority first; the first that matches places
// the record in its node, with its disposal class when it names one, and every placement it makes is logged. A rule
// matches: a regular expression against the titles of the record's parents, written /Fonds/Series/File (folder
// path); the repository's name, exactly, ignoring case (repository, Heratio's workspace); one of the record's subjects,
// given as words separated by commas (subject, Heratio's tag); a regular expression against its files' types (file
// type); field=value against the record's identifier or level (field value); and, for captured e-mail, its
// sender's domain or the department named at import (department). A rule is tried on declaration, on capture, or
// both. A regular expression that does not compile is refused when the rule is saved, not met when it is tried.

namespace App\Retention;

use App\Audit\AuditLog;
use App\Models\ClassificationLogEntry;
use App\Models\ClassificationRule;
use App\Models\Concept;
use App\Models\EmailCapture;
use App\Models\FilePlanNode;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClassificationRules
{
    private const REGEX_TYPES = ['folder-path', 'mime-type'];

    public function save(User $actor, array $data, ?ClassificationRule $rule = null): ClassificationRule
    {
        foreach (['name' => __('Give the rule a name.'), 'match_pattern' => __('Say what the rule matches.')] as $k => $message) {
            if (trim((string) ($data[$k] ?? '')) === '') {
                throw new InvalidArgumentException($message);
            }
        }
        $type = (string) ($data['rule_type'] ?? 'folder-path');
        $pattern = trim($data['match_pattern']);
        if (in_array($type, self::REGEX_TYPES, true) && @preg_match($this->regex($pattern), '') === false) {
            throw new InvalidArgumentException(__('That is not a regular expression the Workspace can use.'));
        }
        if ($type === 'metadata' && ! str_contains($pattern, '=')) {
            throw new InvalidArgumentException(__('Write a field value rule as field=value.'));
        }
        $node = FilePlanNode::where('institution_id', $actor->institution_id)->find($data['file_plan_node_id'] ?? null) ?? throw new InvalidArgumentException(__('Choose a node of this file plan.'));
        $class = ($data['disposal_class_id'] ?? '') ? DB::table('disposal_classes as c')->join('retention_schedules as s', 's.id', '=', 'c.retention_schedule_id')
            ->where(['c.id' => $data['disposal_class_id'], 's.institution_id' => $actor->institution_id])->value('c.id') ?? throw new InvalidArgumentException(__('Choose a disposal class of this institution.')) : null;
        $fields = ['name' => mb_substr(trim($data['name']), 0, 255), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'rule_type_id' => $this->concept('classification-rule-type', $type),
            'match_pattern' => mb_substr($pattern, 0, 1024), 'file_plan_node_id' => $node->id, 'disposal_class_id' => $class, 'priority' => (int) ($data['priority'] ?? 0),
            'is_active' => ! empty($data['is_active']), 'apply_on_id' => $this->concept('classification-apply-on', (string) ($data['apply_on'] ?? 'declare'))];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $rule ? tap($rule)->update($fields)
            : ClassificationRule::create($fields + ['institution_id' => $actor->institution_id, 'created_by_id' => $actor->id])));
    }

    public function delete(User $actor, ClassificationRule $rule): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $rule->delete()));
    }

    /** The first active rule for the use that matches: [rule, detail], or null. */
    public function match(string $institutionId, array $meta, string $use): ?array
    {
        $rules = ClassificationRule::where(['institution_id' => $institutionId, 'is_active' => true])
            ->whereHas('applyOn', fn ($q) => $q->whereIn('iri', [Vocab::BASE.'classification-apply-on/'.$use, Vocab::BASE.'classification-apply-on/both']))
            ->with('ruleType', 'node.status')->orderByDesc('priority')->orderBy('created_at')->get();
        foreach ($rules as $rule) {
            if ($rule->node->status->notation === 'active' && ($detail = $this->test($rule, $meta)) !== null) {
                return ['rule' => $rule, 'detail' => $detail];
            }
        }

        return null;
    }

    /** What matched, or null (Heratio's evaluateRule). */
    public function test(ClassificationRule $rule, array $meta): ?string
    {
        $p = $rule->match_pattern;
        $same = fn (?string $a) => $a !== null && $a !== '' && mb_strtolower(trim($a)) === mb_strtolower(trim($p));

        return match ($rule->ruleType->notation) {
            'folder-path' => @preg_match($this->regex($p), (string) ($meta['folder_path'] ?? '')) === 1 ? __('the path :path', ['path' => $meta['folder_path']]) : null,
            'repository' => $same($meta['repository'] ?? null) ? __('the repository :name', ['name' => $meta['repository']]) : null,
            'department' => $same($meta['department'] ?? null) ? __('the department :name', ['name' => $meta['department']]) : null,
            'tag' => ($hit = collect(explode(',', $p))->map(fn ($t) => mb_strtolower(trim($t)))->filter()->intersect(array_map('mb_strtolower', $meta['tags'] ?? []))->first()) ? __('the subject :name', ['name' => $hit]) : null,
            'mime-type' => ($hit = collect($meta['mime_types'] ?? [])->first(fn ($m) => @preg_match($this->regex($p), $m) === 1)) ? __('a file of type :type', ['type' => $hit]) : null,
            'metadata' => (function () use ($p, $meta) {
                [$key, $value] = array_map('trim', explode('=', $p, 2));

                return isset($meta['custom'][$key]) && mb_strtolower((string) $meta['custom'][$key]) === mb_strtolower($value) ? "$key=$value" : null;
            })(),
            default => null,
        };
    }

    /** Try the rules on a record that has no place yet; the placement made, if any. */
    public function classify(User $actor, RecordResource $record, string $use = 'declare'): ?array
    {
        if ($record->filePlanPlacement()->exists() || ! ($match = $this->match($record->institution_id, $this->metaForRecord($record), $use))) {
            return null;
        }
        DB::transaction(function () use ($actor, $record, $match) {
            FilePlan::giveClass($actor, $record, $match['rule']->disposalClass);
            app(FilePlan::class)->place($actor, $record, $match['rule']->node, $match['rule']->id);
            $this->log($match, $record, null);
        });

        return $match;
    }

    /** Try the rules on every record of the institution with no place yet (Heratio's classifyBatch); the number placed. */
    public function classifyAll(User $actor, int $limit = 1000): int
    {
        $placed = 0;
        RecordResource::where('institution_id', $actor->institution_id)->whereNull('deleted_at')->whereDoesntHave('filePlanPlacement')->limit($limit)->get()
            ->each(function ($record) use ($actor, &$placed) {
                $placed += $this->classify($actor, $record, 'declare') ? 1 : 0;
            });

        return $placed;
    }

    public function log(array $match, ?RecordResource $record, ?EmailCapture $capture): void
    {
        ClassificationLogEntry::create(['record_resource_id' => $record?->id, 'email_capture_id' => $capture?->id, 'rule_id' => $match['rule']->id,
            'file_plan_node_id' => $match['rule']->file_plan_node_id, 'match_detail' => mb_substr($match['detail'], 0, 500), 'classified_at' => now()]);
    }

    /** What a rule may match on a record (Heratio's buildMetaForIO). */
    public function metaForRecord(RecordResource $record): array
    {
        $record->loadMissing('repository', 'instantiations.files');
        $titles = collect([...$record->ancestors(), $record])->map(fn ($r) => EntityKinds::label($r))->filter()->all();
        $subjects = DB::table('relations as r')->join('concepts as t', 't.id', '=', 'r.relation_type_id')->where('t.iri', Vocab::BASE.'relation-type/subject')
            ->where(['r.source_table' => 'record_resources', 'r.source_id' => $record->id, 'r.target_table' => 'concepts'])->pluck('r.target_id');
        $tags = Concept::whereIn('id', $subjects)->get()->map(fn ($c) => $c->label(['en']))->filter()->values()->all();
        $repository = $record->repository ? EntityKinds::label($record->repository) : null;

        return ['folder_path' => '/'.implode('/', $titles), 'repository' => $repository, 'department' => $repository, 'tags' => $tags,
            'mime_types' => $record->instantiations->flatMap(fn ($i) => $i->files->pluck('mime_type'))->unique()->values()->all(),
            'custom' => ['identifier' => (string) $record->identifier, 'level' => $record->level?->notation]];
    }

    public function metaForEmail(EmailCapture $c): array
    {
        $domain = str_contains($c->from_address, '@') ? mb_strtolower(trim(substr($c->from_address, strrpos($c->from_address, '@') + 1), ' >')) : null;

        return ['folder_path' => '/'.(string) $c->subject, 'department' => $domain, 'tags' => [], 'mime_types' => collect($c->attachments ?? [])->pluck('mime_type')->all(),
            'custom' => ['from' => $c->from_address, 'subject' => (string) $c->subject]];
    }

    /** A pattern as written, or between slashes when it has none, matched without regard to case. */
    private function regex(string $pattern): string
    {
        return preg_match('#^/.*/[a-z]*$#s', $pattern) ? $pattern : '#'.str_replace('#', '\#', $pattern).'#iu';
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
