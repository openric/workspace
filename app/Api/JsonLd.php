<?php

// OpenRiC Workspace - units of description, agents and repositories as OpenRiC JSON-LD (RiC-O 1.1).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The shapes of the Core Discovery profile (0.6.0) and the OpenRiC mapping
// (spec 0.53.0, sections 7.1 to 7.4), read from the store: names and values
// with their language tags, dates as openricx:DateRange with literal
// endpoints and a date-type concept, holders and creators as stubs with their
// persistent IRIs. What may be said is decided by ExportView, which for the
// API is the public: published values of entities the Public role may read.
// Predicates the profile forbids without other claims (subjects,
// instantiations, organic provenance) are never written.

namespace App\Api;

use App\Exchange\ExportView;
use App\Models\Agent;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\RicDate;
use App\Relations\EntityKinds;
use App\Rights\PublicRights;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class JsonLd
{
    public const CONTEXT = [
        'rico' => 'https://www.ica.org/standards/RiC/ontology#',
        'openric' => 'https://openric.org/ns/v1#',
        'openricx' => 'https://openric.org/ns/ext/v1#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
    ];

    /** ISAD(G) elements written as literals (mapping 7.1), by notation. */
    private const RECORD_TEXT = [
        'scope-and-content' => 'rico:scopeAndContent', 'archival-history' => 'rico:history', 'arrangement' => 'rico:structure',
        'accruals' => 'rico:accruals', 'appraisal' => 'openricx:hasAppraisalInformation', 'conditions-governing-access' => 'rico:conditionsOfAccess',
        'conditions-governing-reproduction' => 'rico:conditionsOfUse', 'note' => 'rico:generalDescription', 'archivists-note' => 'openricx:descriptiveNote',
        'publication-note' => 'openricx:publicationInformation',
    ];

    /** ISAAR(CPF) elements written as literals (mapping 7.3). */
    private const AGENT_TEXT = [
        'agent-history' => 'rico:history', 'agent-general-context' => 'openricx:generalContext', 'agent-internal-structure' => 'openricx:hasInternalStructure',
        'agent-mandates' => 'rico:authorizingMandate', 'agent-dates-of-existence' => 'rico:date',
    ];

    /** ISDIAH elements written as literals (mapping 7.4). */
    private const REPOSITORY_TEXT = [
        'history' => 'rico:history', 'geocultural-context' => 'openricx:generalContext', 'mandates' => 'rico:authorizingMandate',
        'administrative-structure' => 'openricx:hasInternalStructure', 'records-policies' => 'openricx:hasOrHadPolicy', 'opening-times' => 'openricx:openingHours',
        'access-conditions' => 'rico:conditionsOfAccess', 'accessibility' => 'openricx:accessibility',
    ];

    /** Date properties and the OpenRiC date type each is (mapping 7.2), when the date gives none of its own. */
    private const DATE_TYPES = ['creation' => 'creation', 'accumulation' => 'accumulation', 'existence' => 'existence', 'birth' => 'existence',
        'death' => 'existence', 'modification' => 'descriptive'];

    private const DATE_TYPE = 'https://openric.org/vocab/date-type/';

    public function __construct(private ExportView $view) {}

    public function record(RecordResource $unit, bool $withContext = true): array
    {
        $out = $this->head($unit, $withContext);
        $out['rico:identifier'] = $unit->referenceCode() ?? $unit->identifier ?? $unit->description_identifier ?? $unit->id;
        $out += $this->strings('rico:title', $unit->namesOfType('authorised'));
        if (str_ends_with($out['@type'], 'RecordSet')) {
            $out['rico:hasRecordSetType'] = ['@id' => $unit->level->iri, 'rdfs:label' => (string) $unit->level->label(['en', 'und'])];
        }
        if ($extent = $this->text($unit, 'extent')) {
            // Its own node, so the text goes on rico:textualValue: the spec's schema puts it on rico:hasExtentType, an object property in RiC-O 1.1.
            $out['rico:hasExtent'] = ['@id' => $unit->iri.'#extent', '@type' => 'rico:RecordResourceExtent', 'rico:textualValue' => $extent];
        }
        foreach (self::RECORD_TEXT as $notation => $property) {
            if ($value = $this->text($unit, $notation)) {
                $out[$property] = $value;
            }
        }
        $out += $this->dates($unit->dates, $this->text($unit, 'dates-expressed'));
        if ($unit->repository && $this->view->readable($unit->repository)) {
            $out['rico:hasOrHadHolder'] = $this->stub($unit->repository);
        }
        // Rights in force and Local Contexts Labels, each a rico:Rule (ADR 0008); a holder the public may read.
        $public = new PublicRights($this->view);
        $rules = $this->rules($public, $unit);
        if ($rules !== []) {
            $out['rico:isOrWasRegulatedBy'] = $rules;
        }
        $holders = $public->rights($unit)->pluck('holder')->filter()->unique('id')->map(fn ($agent) => $this->stub($agent))->values()->all();
        if ($holders !== []) {
            $out['rico:hasOrHadIntellectualPropertyRightsHolder'] = $holders;
        }
        $creators = $this->view->creators($unit)->map(fn ($r) => $this->stub($r->other))->values()->all();
        if ($creators !== []) {
            $out['rico:hasCreator'] = $creators;
        }
        if ($unit->parent && $this->view->readable($unit->parent)) {
            $out['rico:isOrWasIncludedIn'] = $this->stub($unit->parent);
        }
        $children = $unit->children->filter(fn ($c) => $this->view->readable($c))->map(fn ($c) => $this->stub($c))->values()->all();
        if ($children !== []) {
            $out['rico:includesOrIncluded'] = $children;
        }

        return $out;
    }

    public function agent(Agent $agent, bool $withContext = true): array
    {
        $out = $this->head($agent, $withContext);
        $out += $this->strings('rico:name', $agent->namesOfType('authorised'));
        $out += $this->strings('openricx:alternativeForm', $agent->namesOfType('parallel')->merge($agent->namesOfType('standardised')));
        $out += $this->strings('openricx:otherName', $agent->namesOfType('other'));
        if ($agent->identifier) {
            $out['rico:identifier'] = $agent->identifier;
        }
        foreach (self::AGENT_TEXT as $notation => $property) {
            if ($value = $this->text($agent, $notation)) {
                $out[$property] = $value;
            }
        }
        // A person's birth and death are one range of existence.
        $dates = $agent->dates;
        $birth = $dates->first(fn ($d) => $d->property->notation === 'birth');
        $death = $dates->first(fn ($d) => $d->property->notation === 'death');
        $ranges = $this->dates($dates->reject(fn ($d) => in_array($d->property->notation, ['birth', 'death'], true)))['openricx:hasDateRangeSet'] ?? [];
        if ($birth || $death) {
            $range = ['@type' => 'openricx:DateRange'] + ($birth ? ['rico:beginningDate' => $this->literal($birth, true)] : []) + ($death ? ['rico:endDate' => $this->literal($death, false)] : []);
            $range['rico:hasDateType'] = ['@id' => self::DATE_TYPE.'existence'];
            $ranges[] = array_filter($range);
        }
        if ($ranges !== []) {
            $out['openricx:hasDateRangeSet'] = $ranges;
        }

        return $out;
    }

    public function repository(Repository $repository, bool $withContext = true): array
    {
        $out = $this->head($repository, $withContext);
        $out += $this->strings('rico:name', $repository->namesOfType('authorised'));
        $out += $this->strings('openricx:alternativeForm', $repository->namesOfType('parallel'));
        $out += $this->strings('openricx:otherName', $repository->namesOfType('other'));
        if ($repository->identifier) {
            $out['rico:identifier'] = $repository->identifier;
        }
        foreach (self::REPOSITORY_TEXT as $notation => $property) {
            if ($value = $this->text($repository, $notation)) {
                $out[$property] = $value;
            }
        }
        $contact = $repository->contactPoints->sortByDesc('is_primary')->first();
        if ($contact) {
            $out['openricx:contact'] = array_filter(['@type' => 'openricx:ContactPoint', 'openricx:streetAddress' => $contact->street_address,
                'openricx:postalCode' => $contact->postal_code, 'openricx:city' => $contact->city, 'openricx:country' => $contact->country_code,
                'openricx:telephone' => $contact->telephone, 'openricx:email' => $contact->email], fn ($v) => $v !== null && $v !== '');
        }

        return $out;
    }

    /**
     * A record with the authorities it reaches in one hop, each described in full (Export-Only 3.2): the record first,
     * then its holding repository and its creators.
     */
    public function dump(RecordResource $unit): array
    {
        $graph = [$this->record($unit, false)];
        if ($unit->repository && $this->view->readable($unit->repository)) {
            $graph[] = $this->repository($unit->repository->load('names.nameType', 'names.state', 'descriptionValues.element', 'descriptionValues.state', 'identifiers.identifierType', 'contactPoints'), false);
        }
        foreach ($this->view->creators($unit) as $relation) {
            $other = $relation->other->load('names.nameType', 'names.state', 'descriptionValues.element', 'descriptionValues.state', 'identifiers.identifierType', 'dates.property', 'dates.dateType');
            $graph[] = $other instanceof Agent ? $this->agent($other, false) : $this->repository($other->load('contactPoints'), false);
        }

        return ['@context' => self::CONTEXT, '@graph' => collect($graph)->unique('@id')->values()->all()];
    }

    /** A reference to an entity described elsewhere: its IRI, class and published name. */
    /**
     * The rules of a unit of description or an instantiation: each right in force, typed by its basis and associated
     * with its RightsStatements.org statement and Creative Commons licence by their own IRIs, and each Local Contexts Label.
     *
     * @return list<array<string, mixed>>
     */
    public function rules(PublicRights $public, Model $subject): array
    {
        $concept = fn ($c) => ['@id' => $c->iri, '@type' => 'rico:Rule', 'rico:name' => (string) $c->label(['en', 'und'])];
        $rules = $public->rights($subject)->map(fn ($right) => array_filter([
            '@id' => $right->iri, '@type' => 'rico:Rule', 'rico:name' => PublicRights::summary($right),
            'rico:hasOrHadRuleType' => ['@id' => $right->basis->iri, 'rdfs:label' => (string) $right->basis->label(['en', 'und'])],
            'rico:isAssociatedWithRule' => array_values(array_filter([$right->statement ? $concept($right->statement) : null, $right->licence ? $concept($right->licence) : null])) ?: null,
            'rico:beginningDate' => $right->starts_on ? ['@value' => $right->starts_on->toDateString(), '@type' => 'xsd:date'] : null,
            'rico:endDate' => $right->ends_on ? ['@value' => $right->ends_on->toDateString(), '@type' => 'xsd:date'] : null,
        ], fn ($v) => $v !== null))->values()->all();

        return [...$rules, ...$public->labels($subject)->map(fn ($label) => $concept($label->label))->values()->all()];
    }

    public function stub(Model $entity): array
    {
        return array_filter(['@id' => $entity->getAttribute('iri'), '@type' => $this->type($entity), 'rico:name' => $this->view->label($entity)], fn ($v) => $v !== null);
    }

    /** The RiC-O class: a unit's level, an agent's type (with rico:Agent where its own class is not one Core Discovery names), a repository's rico:CorporateBody. */
    public function type(Model $entity): string|array
    {
        $class = (string) EntityKinds::ricClass($entity);

        return $entity instanceof Agent && ! in_array($class, ['rico:Person', 'rico:Family', 'rico:CorporateBody'], true) ? [$class, 'rico:Agent'] : $class;
    }

    private function head(Model $entity, bool $withContext): array
    {
        return [...($withContext ? ['@context' => self::CONTEXT] : []), '@id' => $entity->getAttribute('iri'), '@type' => $this->type($entity)];
    }

    /** Every published value of the element, each with its language: one object, or several in an array. */
    private function text(Model $entity, string $notation): ?array
    {
        $values = $this->view->values($entity, $notation);

        return $values->isEmpty() ? null : $this->languageStrings($values);
    }

    /** @return array<string, mixed> the property with the published names, or nothing */
    private function strings(string $property, Collection $names): array
    {
        $names = $names->filter(fn ($n) => $this->view->visible($n))->sortBy(fn ($n) => [! $n->is_original, $n->language])->values();

        return $names->isEmpty() ? [] : [$property => $this->languageStrings($names)];
    }

    private function languageStrings(Collection $values): array
    {
        $strings = $values->map(fn ($v) => ['@value' => $v->value, '@language' => $v->language])->values()->all();

        return count($strings) === 1 ? $strings[0] : $strings;
    }

    /** @return array<string, list<array<string, mixed>>> openricx:hasDateRangeSet, the dates as written going with the first */
    private function dates(Collection $dates, ?array $written = null): array
    {
        $ranges = [];
        foreach ($dates->values() as $i => $date) {
            $type = $date->dateType?->iri ?? (isset(self::DATE_TYPES[$date->property->notation]) ? self::DATE_TYPE.self::DATE_TYPES[$date->property->notation] : null);
            $ranges[] = array_filter([
                '@type' => 'openricx:DateRange',
                'rico:beginningDate' => $this->literal($date, true),
                'rico:endDate' => $this->literal($date, false),
                'rico:normalizedDateValue' => $date->normalized_value,
                'rico:expressedDate' => $date->expressed !== null ? ['@value' => $date->expressed, '@language' => $date->expressed_language ?? 'und']
                    : ($i === 0 && $written !== null ? $written : null),
                'rico:hasDateType' => $type ? ['@id' => $type] : null,
            ], fn ($v) => $v !== null);
        }

        return $ranges === [] ? [] : ['openricx:hasDateRangeSet' => $ranges];
    }

    /** The beginning or end as a typed literal: a year, a year and month, or a day, as precise as the EDTF value is. */
    private function literal(RicDate $date, bool $beginning): ?array
    {
        $day = $beginning ? $date->earliest : $date->latest;
        if ($day === null) {
            return null;
        }
        $parts = explode('/', (string) $date->normalized_value);
        $part = $beginning ? $parts[0] : ($parts[1] ?? $parts[0]);

        return match (true) {
            preg_match('/^\d{4}$/', $part) === 1 => ['@value' => $part, '@type' => 'xsd:gYear'],
            preg_match('/^\d{4}-\d{2}$/', $part) === 1 => ['@value' => $part, '@type' => 'xsd:gYearMonth'],
            default => ['@value' => $day->format('Y-m-d'), '@type' => 'xsd:date'],
        };
    }
}
