<?php

// OpenRiC Workspace - create and describe rules and mandates (rico:Rule, rico:Mandate; roadmap phase 23b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// What a rule gives authority to or regulates are relations (RelationService); this keeps the rule itself: its
// names, values, type, dates and the IRI an authority elsewhere gives it. After App\Description\ActivityService.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Rule;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RuleService
{
    public function __construct(private DescriptionService $descriptions) {}

    /** @param  ?string  $typeId  a concept id from types() */
    public function create(Institution $institution, string $name, string $language, ?string $typeId = null, ?User $actor = null): Rule
    {
        $type = $this->type($institution->id, $typeId);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $name, $language, $type) {
            $id = (string) Str::uuid7();
            $rule = new Rule(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Rule::IRI_SEGMENT.'/'.$id, 'rule_type_id' => $type?->id]);
            $rule->id = $id;
            $rule->save();
            $this->describe($rule, ['rule-name' => $name], $language);

            return $rule;
        }));
    }

    /**
     * Set the values of the rule's elements in one language; the authorised name must keep a value.
     *
     * @param  array<string, ?string>  $values  element notation => text
     */
    public function describe(Rule $rule, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($rule, Elements::RULE, $values, $language, 'rule-name', $actor);
    }

    /** The control area: description identifier, status and level of detail, as an activity has them. */
    public function updateControl(Rule $rule, ?string $descriptionIdentifier, ?string $statusIri, ?string $detailIri, ?User $actor = null): void
    {
        $id = fn (?string $iri) => $iri ? Concept::where('iri', $iri)->value('id') : null;
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $rule->update([
            'description_identifier' => ($descriptionIdentifier ?? '') === '' ? null : $descriptionIdentifier,
            'description_status_id' => $id($statusIri), 'description_detail_id' => $id($detailIri),
        ])));
    }

    /** The rule as an authority elsewhere publishes it (owl:sameAs), such as a law's entry in a legal database. */
    public function updateAuthority(Rule $rule, ?string $iri, ?User $actor = null): void
    {
        $iri = trim((string) $iri) ?: null;
        if ($iri !== null && ! preg_match('#^https?://#i', $iri)) {
            throw new InvalidArgumentException(__('Give the address of the rule elsewhere, beginning with http:// or https://.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $rule->update(['authority_iri' => $iri])));
    }

    public function updateType(Rule $rule, ?string $typeId, ?User $actor = null): void
    {
        $type = $this->type($rule->institution_id, $typeId);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $rule->update(['rule_type_id' => $type?->id])));
    }

    /**
     * The rule types the institution may give: the OpenRiC rule-type vocabulary, then its own
     * rule-type vocabularies, each by label.
     *
     * @return Collection<int, Concept>
     */
    public function types(string $institutionId): Collection
    {
        $sort = fn (Collection $c) => $c->sortBy(fn (Concept $t) => mb_strtolower($t->anyLabel(['en'])))->values();

        return $sort(Concept::choices(Rule::TYPES)->with('vocabulary')->get())->merge($sort(
            Concept::where('institution_id', $institutionId)->whereNull('deprecated_at')
                ->whereHas('vocabulary.kind', fn ($q) => $q->where('iri', Vocab::SCHEME_KINDS.'rule-type'))->with('labels', 'vocabulary')->get()));
    }

    private function type(string $institutionId, ?string $typeId): ?Concept
    {
        if ($typeId === null || $typeId === '') {
            return null;
        }

        return $this->types($institutionId)->firstWhere('id', $typeId)
            ?? throw new InvalidArgumentException(__('a rule type is a concept of the OpenRiC rule-type vocabulary or of one of the institution\'s rule-type vocabularies'));
    }
}
