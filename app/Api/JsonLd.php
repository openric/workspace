<?php

// OpenRiC Workspace - units of description, agents, repositories, places, rules and activities as OpenRiC JSON-LD (RiC-O 1.1).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The shapes of the Core Discovery profile (0.6.0) and the OpenRiC mapping
// (spec 0.53.0, sections 7.1 to 7.4), read from the store: names and values
// with their language tags, dates as openricx:DateRange with literal
// endpoints and a date-type concept, holders and creators as stubs with their
// persistent IRIs. What may be said is decided by ExportView, which for the
// API is the public: published values of entities the Public role may read.
// Every relation the reader may follow is written by its RiC-O property
// (mapping section 8), which Core Discovery allows for subjects once Graph
// Traversal is declared (roadmap phase 23b); instantiations, which need the
// Digital Object Linkage profile, and organic provenance, which needs
// Provenance & Event, are never written.

namespace App\Api;

use App\Exchange\ExportView;
use App\Iiif\Presentation;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Place;
use App\Models\ProvenanceEntry;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\RicDate;
use App\Models\Rule;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Relations\RelationTypes;
use App\Rights\PublicRights;
use App\Support\Vocab;
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
        'owl' => 'http://www.w3.org/2002/07/owl#',
        'dcterms' => 'http://purl.org/dc/terms/',
        'prov' => 'http://www.w3.org/ns/prov#',
    ];

    /** The Authority & Context schema gives a rule's type as a string; RiC-O makes it a rico:RuleType, so in a rule's document the string is its IRI. */
    private const RULE_TYPE_AS_IRI = ['rico:hasOrHadRuleType' => ['@type' => '@id']];

    /** The same for an instantiation's carrier type (Digital Object Linkage 3.1; RiC-O rico:CarrierType). */
    private const CARRIER_TYPE_AS_IRI = ['rico:hasCarrierType' => ['@type' => '@id']];

    /** An instantiation's values written as literals (Digital Object Linkage 3.1). */
    private const INSTANTIATION_TEXT = ['instantiation-description' => 'openricx:description', 'instantiation-production-technique' => 'rico:productionTechnique',
        'instantiation-physical-characteristics' => 'rico:physicalCharacteristicsNote', 'instantiation-quality' => 'rico:qualityOfRepresentationNote',
        'instantiation-conditions-of-access' => 'rico:conditionsOfAccess'];

    /** ISAD(G) elements written as literals (mapping 7.1), by notation. */
    public const RECORD_TEXT = [
        'scope-and-content' => 'rico:scopeAndContent', 'archival-history' => 'rico:history', 'arrangement' => 'rico:structure',
        'accruals' => 'rico:accruals', 'appraisal' => 'openricx:hasAppraisalInformation', 'conditions-governing-access' => 'rico:conditionsOfAccess',
        'conditions-governing-reproduction' => 'rico:conditionsOfUse', 'note' => 'rico:generalDescription', 'archivists-note' => 'openricx:descriptiveNote',
        'publication-note' => 'openricx:publicationInformation',
    ];

    /** ISAAR(CPF) elements written as literals (mapping 7.3). */
    public const AGENT_TEXT = [
        'agent-history' => 'rico:history', 'agent-general-context' => 'openricx:generalContext', 'agent-internal-structure' => 'openricx:hasInternalStructure',
        'agent-mandates' => 'rico:authorizingMandate', 'agent-dates-of-existence' => 'rico:date',
    ];

    /** ISDIAH elements written as literals (mapping 7.4). */
    /** ISDIAH elements RiC-O 1.1 has no property of their own for, written as notes, as their scope notes say. */
    public const REPOSITORY_NOTES = ['buildings', 'holdings', 'finding-aids', 'research-services', 'reproduction-services', 'public-areas'];

    /** ISDIAH control and AtoM access point elements written as general description, as their scope notes say. */
    public const REPOSITORY_GENERAL = ['description-sources', 'maintenance-notes', 'repository-institution-identifier', 'repository-languages', 'repository-scripts',
        'geographic-subregion', 'thematic-area'];

    public const REPOSITORY_TEXT = [
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
        // Each title as its own rico:Title as well, so a second title and a translation can be told apart: two language-tagged
        // rico:title values cannot be taken as one title in two languages (Richard Williamson, RiC users list, 3 November 2026).
        if ($titles = $this->appellations($unit, 'rico:Title')) {
            $out['rico:hasOrHadTitle'] = $titles;
        }
        if (str_ends_with($out['@type'], 'RecordSet')) {
            $out['rico:hasRecordSetType'] = ['@id' => $unit->level->iri, 'rdfs:label' => (string) $unit->level->label(['en', 'und'])];
        } elseif ($unit->level) {
            // RiC-O gives only a record set a type; a record's level (item) goes as the implementation's own type, so it
            // can be made again elsewhere (gap assessment scenario S5).
            $out['openric:localType'] = (string) $unit->level->notation;
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
        if (($custody = $this->custody($unit)) !== []) {
            $out['rico:isOrWasAffectedBy'] = $custody;
        }
        $creators = $this->view->creators($unit)->map(fn ($r) => $this->stub($r->other))->values()->all();
        if ($creators !== []) {
            $out['rico:hasCreator'] = $creators;
        }
        // Its carriers (Digital Object Linkage, roadmap phase 24), by IRI: each is described at its own address. With RiC-O
        // 1.1's specific properties beside the broad one, digital or analogue by the carrier (roadmap step 12c), as the
        // Archives nationales de France's RiC-O converter gives them.
        $readable = $unit->instantiations->filter(fn ($i) => $this->view->readable($i))->values();
        if ($readable->isNotEmpty()) {
            $out['rico:hasOrHadInstantiation'] = $readable->map(fn ($i) => ['@id' => $i->iri])->all();
            foreach (['digital' => 'rico:hasOrHadDigitalInstantiation', 'analogue' => 'rico:hasOrHadAnalogueInstantiation'] as $kind => $property) {
                if (($these = $readable->filter(fn ($i) => self::carrierKind($i) === $kind))->isNotEmpty()) {
                    $out[$property] = $these->map(fn ($i) => ['@id' => $i->iri])->values()->all();
                }
            }
        }
        // Its place in the file plan, when that node is a function or activity of the institution (roadmap phase 13c).
        $documents = $unit->filePlanPlacement?->node?->activity;
        if ($documents && $documents->deleted_at === null && $this->view->readable($documents)) {
            $out['rico:documents'] = collect([...(array) ($out['rico:documents'] ?? []), $this->stub($documents)])->unique('@id')->values()->all();
        }
        // Where it is arranged, then each record set it is also in (rico:Proxy; roadmap phase 24).
        $in = array_filter([$unit->parent && $this->view->readable($unit->parent) ? $this->stub($unit->parent) : null,
            ...$unit->memberships->filter(fn ($m) => $this->view->readable($m->recordSet))->map(fn ($m) => $this->stub($m->recordSet))->all()]);
        if ($in !== []) {
            $out['rico:isOrWasIncludedIn'] = count($in) === 1 ? reset($in) : array_values($in);
        }
        // Where it is arranged now, directly, as RiC-O 1.1 says it of a record set (rico:isDirectlyIncludedIn; step 12c).
        if ($unit->parent && $this->view->readable($unit->parent) && $this->isRecordSet($unit->parent)) {
            $out['rico:isDirectlyIncludedIn'] = $this->stub($unit->parent);
        }
        $direct = $unit->children->filter(fn ($c) => $this->view->readable($c))->values();
        if ($direct->isNotEmpty() && str_ends_with((string) $out['@type'], 'RecordSet')) {
            $out['rico:directlyIncludes'] = $direct->map(fn ($c) => $this->stub($c))->all();
        }
        // Its place among the units arranged beside it, in their order (rico:directlyPrecedesInSequence; step 12c).
        if ($unit->parent_id) {
            $siblings = RecordResource::where('parent_id', $unit->parent_id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')->with(PublicCatalogue::WITH)->get()
                ->filter(fn ($r) => $this->view->readable($r))->values();
            $at = $siblings->search(fn ($r) => $r->id === $unit->id);
            if ($at !== false && $at > 0) {
                $out['rico:directlyFollowsInSequence'] = $this->stub($siblings[$at - 1]);
            }
            if ($at !== false && $at < $siblings->count() - 1) {
                $out['rico:directlyPrecedesInSequence'] = $this->stub($siblings[$at + 1]);
            }
        }
        $children = $unit->children->filter(fn ($c) => $this->view->readable($c))->map(fn ($c) => $this->stub($c))->values()->all();
        $proxies = $unit->memberProxies->filter(fn ($m) => $this->view->readable($m->record))->values();
        $children = [...$children, ...$proxies->map(fn ($m) => $this->stub($m->record))->all()];
        if ($children !== []) {
            $out['rico:includesOrIncluded'] = $children;
        }
        // The units also in it, each by its proxy here, in this record set's order.
        if ($proxies->isNotEmpty()) {
            $out['rico:includesProxy'] = $proxies->map(fn ($m, $i) => array_filter(['@id' => $m->iri, '@type' => 'rico:Proxy',
                'rico:proxyFor' => ['@id' => $m->record->iri], 'rico:proxyIn' => ['@id' => $unit->iri],
                'rico:proxyDirectlyPrecedesProxyInSequence' => isset($proxies[$i + 1]) ? ['@id' => $proxies[$i + 1]->iri] : null]))->all();
        }
        $out += $this->relations($unit, ['rico:hasCreator', 'rico:hasOrHadHolder']);
        // Its images, sound and video as a IIIF manifest, for the public (ADR 0014): RiC describes, IIIF presents.
        if ($this->view->user === null && (new Presentation(new PublicCatalogue(Institution::findOrFail($unit->institution_id))))->hasManifest($unit)) {
            $out['rdfs:seeAlso'] = ['@id' => route('iiif.manifest', $unit->id)];
        }

        return $out;
    }

    public function agent(Agent $agent, bool $withContext = true): array
    {
        $out = $this->head($agent, $withContext);
        $out += $this->strings('rico:name', $agent->namesOfType('authorised'));
        $out += $this->strings('openricx:alternativeForm', $agent->namesOfType('parallel')->merge($agent->namesOfType('standardised')));
        $out += $this->strings('openricx:otherName', $agent->namesOfKind('other'));
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
        if ($names = $this->appellations($agent, 'rico:AgentName')) {
            $out['rico:hasOrHadAgentName'] = $names;
        }
        if ($record = $this->authorityRecord($agent, $out['rico:name'] ?? null)) {
            $out['rico:isOrWasDescribedBy'] = $record;
        }

        return $out + $this->relations($agent);
    }

    /**
     * The authority record that describes an agent, its ISAAR(CPF) control area (5.4), as a rico:Record beside the
     * network of entities the agent is: of the documentary form Authority record, resulting from the activity of
     * describing it, which its maintaining repository performed under its rules and conventions. Richard Williamson
     * (EGAD), Records in Contexts users list, 6 November 2026: an authority record may be judged a record in its own
     * right, its documentary form set and the activity it documents named. Nothing where the control area is empty.
     */
    private function authorityRecord(Agent $agent, mixed $title): ?array
    {
        $notes = collect(['agent-institution-identifiers', 'agent-languages-scripts', 'agent-sources', 'agent-maintenance-notes'])
            ->map(fn ($n) => $this->text($agent, $n))->filter()->flatMap(fn ($v) => isset($v['@value']) ? [$v] : $v);
        if ($agent->descriptionDetail) {
            $notes->push(['@value' => 'Level of detail: '.$agent->descriptionDetail->label(['en']), '@language' => 'en']);
        }
        $rules = $this->text($agent, 'agent-rules-conventions');
        $dates = $this->text($agent, 'agent-description-dates');
        $maintainer = $agent->maintainer && $this->view->readable($agent->maintainer) ? ['@id' => $agent->maintainer->iri] : null;
        if ($notes->isEmpty() && ! $rules && ! $dates && ! $maintainer && ! $agent->description_identifier && ! $agent->descriptionStatus) {
            return null;
        }
        $iri = $agent->iri.'#authority-record';
        $activity = array_filter(['@id' => $agent->iri.'#description', '@type' => 'rico:Activity',
            'rico:name' => ['@value' => 'Description of the agent', '@language' => 'en'], 'rico:isOrWasPerformedBy' => $maintainer,
            // The rules or conventions it followed: a rule titled as written, as the OpenRiC shapes ask of a rule.
            'rico:isOrWasRegulatedBy' => $rules ? ['@type' => 'rico:Rule', 'rico:title' => $rules, 'rico:name' => $rules,
                'openricx:descriptiveNote' => ['@value' => 'The rules or conventions the description of the agent followed (ISAAR(CPF) 5.4.3).', '@language' => 'en']] : null,
            // When it was described: the dates as written, else the day the Workspace's record of it was made.
            'rico:isAssociatedWithDate' => ['@type' => 'rico:Date'] + ($dates ? ['rico:expressedDate' => $dates] : ['rico:normalizedDateValue' => $agent->created_at?->toDateString()])]);

        return array_filter(['@id' => $iri, '@type' => 'rico:Record', 'rico:title' => $title,
            'rico:hasDocumentaryFormType' => ['@id' => Vocab::BASE.'record-documentary-form/authority-record'],
            'rico:describesOrDescribed' => ['@id' => $agent->iri], 'rico:identifier' => $agent->description_identifier,
            'rico:hasRecordState' => $agent->descriptionStatus ? ['@id' => $agent->descriptionStatus->iri] : null,
            'rico:hasOrHadHolder' => $maintainer, 'rico:isOrWasRegulatedBy' => $activity['rico:isOrWasRegulatedBy'] ?? null,
            'rico:hasModificationDate' => $dates ? ['@type' => 'rico:Date', 'rico:expressedDate' => $dates] : null,
            'rico:note' => $notes->isEmpty() ? null : ($notes->count() === 1 ? $notes->first() : $notes->values()->all()),
            'rico:resultsOrResultedFrom' => $activity]);
    }

    public function repository(Repository $repository, bool $withContext = true): array
    {
        $out = $this->head($repository, $withContext);
        $out += $this->strings('rico:name', $repository->namesOfType('authorised'));
        // A repository is a rico:CorporateBody; this says it is one that holds records here, so it is read back as one.
        $out['openric:localType'] = 'repository';
        $out += $this->strings('openricx:alternativeForm', $repository->namesOfType('parallel'));
        $out += $this->strings('openricx:otherName', $repository->namesOfKind('other'));
        if ($repository->identifier) {
            $out['rico:identifier'] = $repository->identifier;
        }
        foreach (self::REPOSITORY_TEXT as $notation => $property) {
            if ($value = $this->text($repository, $notation)) {
                $out[$property] = $value;
            }
        }
        foreach (['rico:note' => self::REPOSITORY_NOTES, 'rico:generalDescription' => self::REPOSITORY_GENERAL] as $property => $notations) {
            $values = collect($notations)->map(fn ($n) => $this->text($repository, $n))->filter()->flatMap(fn ($v) => isset($v['@value']) ? [$v] : $v)->values()->all();
            if ($values !== []) {
                $out[$property] = count($values) === 1 ? $values[0] : $values;
            }
        }
        if ($repository->repositoryType) {
            $out['rico:hasOrHadCorporateBodyType'] = ['@id' => $repository->repositoryType->iri];
        }
        $out += $this->dates($repository->dates);
        if ($names = $this->appellations($repository, 'rico:AgentName')) {
            $out['rico:hasOrHadAgentName'] = $names;
        }
        // Who to contact and when it is open are the Workspace's own (openricx:), not RiC-O.
        $contact = $repository->contactPoints->sortByDesc('is_primary')->first();
        if ($contact) {
            $out['openricx:contact'] = array_filter(['@type' => 'openricx:ContactPoint', 'openricx:streetAddress' => $contact->street_address,
                'openricx:postalCode' => $contact->postal_code, 'openricx:city' => $contact->city, 'openricx:country' => $contact->country_code,
                'openricx:telephone' => $contact->telephone, 'openricx:email' => $contact->email], fn ($v) => $v !== null && $v !== '');
        }

        return $out + $this->relations($repository);
    }

    /**
     * A record with the authorities it reaches in one hop, each described in full (Export-Only 3.2): the record first,
     * then its holding repository and its creators.
     */
    /**
     * A place (Authority & Context 3.1; RiC-CM 1.0.1 section 4.8): its names, description, identifiers, location, type,
     * coordinates, the place it lies in, its dates and relations. RiC-O 1.1 puts latitude, longitude and altitude on a
     * rico:Coordinates node; the profile's schema puts latitude and longitude on the place, so both are written.
     * History is not: RiC-O 1.1 gives rico:history to agents only.
     */
    public function place(Place $place, bool $withContext = true): array
    {
        $out = $this->head($place, $withContext);
        $out += $this->strings('rico:name', $place->namesOfType('authorised'));
        $out += $this->strings('openricx:alternativeForm', $place->namesOfType('parallel'));
        $out += $this->strings('openricx:otherName', $place->namesOfKind('other'));
        if ($names = $this->appellations($place, 'rico:PlaceName')) {
            $out['rico:hasOrHadPlaceName'] = $names;
        }
        if ($place->identifier) {
            $out['rico:identifier'] = $place->identifier;
        }
        if ($description = $this->text($place, 'place-description')) {
            $out += ['rico:generalDescription' => $description, 'openricx:description' => $description];
        }
        if ($location = $this->text($place, 'place-location')) {
            $out += ['rico:location' => $location, 'openricx:streetAddress' => (string) ($location[0]['@value'] ?? $location['@value'] ?? '')];
        }
        if ($place->placeType) {
            $out['rico:hasOrHadPlaceType'] = ['@id' => $place->placeType->iri];
            $out['openric:localType'] = (string) $place->placeType->notation;
        }
        if ($place->latitude !== null && $place->longitude !== null) {
            $out += ['rico:latitude' => (float) $place->latitude, 'rico:longitude' => (float) $place->longitude];
            $out['rico:hasOrHadCoordinates'] = array_filter(['@id' => $place->iri.'#coordinates', '@type' => 'rico:Coordinates', 'rico:latitude' => (float) $place->latitude,
                'rico:longitude' => (float) $place->longitude, 'rico:altitude' => $place->altitude !== null ? (float) $place->altitude : null], fn ($v) => $v !== null);
        }
        if ($place->parent && $this->view->readable($place->parent)) {
            $out['rico:isOrWasPartOf'] = $this->stub($place->parent);
        }

        return $out + $this->dates($place->dates) + $this->relations($place);
    }

    /**
     * A rule or mandate (Authority & Context 3.3): its title, what it says, where it is published, its type, the same
     * rule elsewhere and its dates. A mandate is typed both, so the Rule shape still applies to it.
     */
    public function rule(Rule $rule, bool $withContext = true): array
    {
        $out = $this->head($rule, $withContext);
        if ($withContext) {
            $out['@context'] += self::RULE_TYPE_AS_IRI;
        }
        $names = $this->strings('rico:title', $rule->namesOfType('authorised'));
        $out += $names + ($names ? ['rico:name' => $names['rico:title']] : []);
        // Each title as its own rico:Title as well, so a second title and a translation can be told apart: two language-tagged
        // rico:title values cannot be taken as one title in two languages (Richard Williamson, RiC users list, 3 November 2026).
        if ($titles = $this->appellations($rule, 'rico:Title')) {
            $out['rico:hasOrHadTitle'] = $titles;
        }
        if ($description = $this->text($rule, 'rule-description')) {
            $out['openricx:description'] = $description;
        }
        if ($sources = $this->text($rule, 'rule-sources')) {
            $out['dcterms:source'] = $sources;
        }
        if ($rule->ruleType) {
            // A string the rule's own context makes an IRI; without that context, the link written out.
            $out['rico:hasOrHadRuleType'] = $withContext ? $rule->ruleType->iri : ['@id' => $rule->ruleType->iri];
            $out['openric:localType'] = (string) $rule->ruleType->notation;
        }
        if ($rule->authority_iri) {
            $out['owl:sameAs'] = $rule->authority_iri;
        }
        // Whether it is in force, and how it came about and changed (step 12d).
        if ($rule->status) {
            $out['openricx:hasRuleStatus'] = ['@id' => $rule->status->iri];
        }
        if ($history = $this->text($rule, 'rule-history')) {
            $out['rico:history'] = $history;
        }

        return $out + $this->dates($rule->dates) + $this->relations($rule);
    }

    /**
     * An activity (Authority & Context 3.4; Provenance & Event 3.1): its name, description, type, dates and who and what
     * it involved. Provenance & Event checks the class of what it resulted in and who took part, so those are stubs
     * with their class and names; who performed it took part in it, so is named a participant too.
     */
    public function activity(Activity $activity, bool $withContext = true): array
    {
        $out = $this->head($activity, $withContext);
        $out += $this->strings('rico:name', $activity->namesOfType('authorised'));
        if ($description = $this->text($activity, 'activity-description')) {
            $out['openricx:description'] = $description;
        }
        if ($activity->activityType) {
            $out['rico:hasActivityType'] = ['@id' => $activity->activityType->iri];
            $out['openric:localType'] = (string) $activity->activityType->notation;
        }
        if ($dates = $this->dates($activity->dates)['openricx:hasDateRangeSet'] ?? null) {
            $out['rico:isAssociatedWithDate'] = $dates;
        }
        $out += $this->relations($activity, [], ['rico:resultsOrResultedIn', 'rico:hasOrHadParticipant', 'rico:isOrWasPerformedBy']);
        if (isset($out['rico:isOrWasPerformedBy'])) {
            $out['rico:hasOrHadParticipant'] = collect([...($out['rico:hasOrHadParticipant'] ?? []), ...$out['rico:isOrWasPerformedBy']])->unique('@id')->values()->all();
        }

        return $out;
    }

    /**
     * An instantiation (Digital Object Linkage 3.1): its title, identifier, carrier type, the master file's MIME type,
     * size and SHA-256, what its values say, the unit it carries and its relations, such as what it was derived from.
     */
    public function instantiation(Instantiation $instantiation, bool $withContext = true): array
    {
        $out = $this->head($instantiation, $withContext);
        if ($withContext) {
            $out['@context'] += self::CARRIER_TYPE_AS_IRI;
        }
        $out += $this->strings('rico:title', $instantiation->namesOfType('authorised'));
        // Each title as its own rico:Title as well, so a second title and a translation can be told apart: two language-tagged
        // rico:title values cannot be taken as one title in two languages (Richard Williamson, RiC users list, 3 November 2026).
        if ($titles = $this->appellations($instantiation, 'rico:Title')) {
            $out['rico:hasOrHadTitle'] = $titles;
        }
        $file = $instantiation->files->firstWhere(fn ($f) => $f->use?->notation === 'master') ?? $instantiation->files->first();
        if ($file?->original_name) {
            $out['rico:identifier'] = $file->original_name;
        }
        foreach (self::INSTANTIATION_TEXT as $notation => $property) {
            if ($value = $this->text($instantiation, $notation)) {
                $out[$property] = $value;
            }
        }
        if ($instantiation->carrierType) {
            $out['rico:hasCarrierType'] = $withContext ? $instantiation->carrierType->iri : ['@id' => $instantiation->carrierType->iri];
            $out['openric:localType'] = (string) $instantiation->carrierType->notation;
        }
        if ($file) {
            $out += array_filter(['openricx:hasMimeType' => $file->mime_type,
                'rico:hasExtent' => $file->size_bytes !== null ? ['@id' => $instantiation->iri.'#extent', '@type' => 'rico:InstantiationExtent', 'rico:quantity' => (int) $file->size_bytes,
                    'rico:hasUnitOfMeasurement' => ['@id' => $instantiation->iri.'#bytes', '@type' => 'rico:UnitOfMeasurement', 'rico:name' => 'bytes']] : null,
                'openricx:technicalCharacteristics' => $file->sha256 ? 'Checksum (sha256): '.$file->sha256 : null]);
        }
        if ($instantiation->availability) {
            $out['rico:conditionsOfAccess'] ??= (string) $instantiation->availability->anyLabel(['en']);
        }
        if ($instantiation->record && $this->view->readable($instantiation->record)) {
            $out['rico:isOrWasInstantiationOf'] = $this->stub($instantiation->record);
            // RiC-O 1.1's specific inverse, digital or analogue by the carrier (step 12c).
            if ($kind = self::carrierKind($instantiation)) {
                $out[$kind === 'digital' ? 'rico:isOrWasDigitalInstantiationOf' : 'rico:isOrWasAnalogueInstantiationOf'] = $this->stub($instantiation->record);
            }
        }

        return $out + $this->dates($instantiation->dates) + $this->relations($instantiation);
    }

    /** An activity described as an ISDF function (Digital Object Linkage 3.2): its name, history and classification. */
    public function function(Activity $function, bool $withContext = true): array
    {
        $out = [...($withContext ? ['@context' => self::CONTEXT] : []), '@id' => $function->iri, '@type' => 'openricx:Function'];
        $out += $this->strings('rico:name', $function->namesOfType('authorised'));
        foreach (['activity-history' => 'rico:history', 'activity-classification' => 'rico:classification', 'activity-description' => 'openricx:description'] as $notation => $property) {
            if ($value = $this->text($function, $notation)) {
                $out[$property] = $value;
            }
        }
        if ($function->activityType) {
            $out['openric:localType'] = (string) $function->activityType->notation;
        }

        return $out + $this->relations($function);
    }

    /**
     * Each published name as its own RiC-O appellation (RiC-CM 1.0.1 RiC-E07, Name): its text in its language and the
     * dates it was used (rico:usedFromDate, rico:usedToDate), so a place's or a body's names over time survive export,
     * as the design review of 2 November 2026 asks (URS-005, URS-006). The shorthand properties above still give the
     * names by type, as the profiles' schemas expect.
     */
    private function appellations(Model $entity, string $class): array
    {
        return $entity->names->filter(fn ($n) => $this->view->visible($n))->sortBy(fn ($n) => [! $n->is_original, $n->language, $n->id])->values()
            ->map(function ($name) use ($entity, $class) {
                $dated = fn (string $property) => $name->dates->first(fn ($d) => $d->property?->notation === $property);
                [$from, $to] = [$dated('used-from'), $dated('used-to')];

                return array_filter(['@id' => $entity->getAttribute('iri').'#name-'.$name->id, '@type' => $class,
                    'rico:textualValue' => ['@value' => $name->value, '@language' => $name->language],
                    'rico:usedFromDate' => $from ? $this->literal($from, true) : null, 'rico:usedToDate' => $to ? $this->literal($to, false) : null]);
            })->all();
    }

    /**
     * Every relation of the entity the reader may follow, by its RiC-O property (relation_types.ric_property from its
     * source, ric_inverse from its target; mapping section 8): relations that stand (accepted or disputed), to entities
     * the reader may read. $already names the properties the caller writes itself; the other end is its IRI, or, for the
     * properties in $typed, a stub with its class and names.
     *
     * @param  list<string>  $already
     * @param  list<string>  $typed
     */
    private function relations(Model $entity, array $already = [], array $typed = []): array
    {
        $out = [];
        foreach (app(RelationService::class)->of($entity) as $relation) {
            if (! in_array($relation->standing(), ['accepted', 'disputed'], true) || ! $this->view->readable($relation->other)) {
                continue;
            }
            $type = RelationTypes::of($relation);
            $property = $relation->fromSource ? $type['ric_property'] ?? null : $type['ric_inverse'] ?? null;
            if ($property === null) {
                continue;
            }
            // Its IRI only, which dereferences to its description: a name here would be a second literal for the same
            // entity wherever documents are merged, and a class without the name its shape requires fails that shape. A
            // property the caller writes itself is not written twice, but its qualified node still is.
            if (! in_array($property, $already, true)) {
                $out[$property][$relation->other->getAttribute('iri')] = in_array($property, $typed, true) ? $this->stub($relation->other) : ['@id' => $relation->other->getAttribute('iri')];
            }
            if ($relation->fromSource && ($node = $this->qualified($entity, $relation, $type)) !== null) {
                $out['rico:thingIsSourceOfRelation'][$relation->iri] = $node;
            }
        }

        return array_map('array_values', $out);
    }

    /**
     * The relation as its own node (mapping section 8: "when date-scoping, certainty, or provenance are required"),
     * written from its source only: its class, both ends, its dates, certainty, the published units that are its
     * evidence and the agent's role. Null when it carries none of these, as the property alone then says it all.
     */
    private function qualified(Model $source, Relation $relation, array $type): ?array
    {
        $evidence = $relation->evidence->filter(fn ($e) => $e->record && $this->view->readable($e->record))->map(fn ($e) => ['@id' => $e->record->iri])->values()->all();
        // Its beginning and end as one range (rico:beginningDate, rico:endDate), or the span it was given as one date.
        $span = $relation->datedAs('relation');
        [$begin, $end] = [$relation->datedAs('beginning'), $relation->datedAs('end')];
        $range = $begin || $end ? array_filter(['@type' => 'openricx:DateRange', 'rico:beginningDate' => $begin ? $this->literal($begin, true) : null,
            'rico:endDate' => $end ? $this->literal($end, false) : null]) : ($span ? ($this->dates(collect([$span]))['openricx:hasDateRangeSet'][0] ?? null) : null);
        $qualifiers = array_filter([
            'openricx:hasDateRangeSet' => $range ? [$range] : null,
            // A relation whose assertions disagree is disputed, not flattened into a fact (gap assessment scenario S3).
            'rico:relationCertainty' => $relation->standing() === 'disputed' ? 'disputed' : $relation->certainty?->notation,
            'rico:isEvidencedBy' => $evidence ?: null,
            'openricx:relationHasAgentRole' => $relation->role ? ['@id' => $relation->role->iri] : null,
            'prov:wasGeneratedBy' => $this->inferredBy($relation),
        ]);
        if ($qualifiers === []) {
            return null;
        }

        return ['@id' => $relation->iri, '@type' => $type['ric_class'] ?? 'rico:Relation', 'rico:relationHasSource' => ['@id' => $source->getAttribute('iri')],
            'rico:relationHasTarget' => ['@id' => $relation->other->getAttribute('iri')]] + $qualifiers;
    }

    /**
     * The Inferred-Provenance profile (roadmap phase 17a), after Heratio's RicProvenanceService: a relation a model
     * asserted says so, as the prov:Activity it was generated by, associated with the model as a prov:SoftwareAgent.
     * A relation no model asserted says nothing, and by that absence is documented fact.
     */
    private function inferredBy(Relation $relation): ?array
    {
        $models = $relation->currentAssertions()->filter(fn ($a) => $a->origin?->notation === 'model');

        return $models->isEmpty() ? null : $models->map(fn ($a) => ['@id' => $a->iri.'#generation', '@type' => 'prov:Activity',
            'prov:wasAssociatedWith' => ['@type' => 'prov:SoftwareAgent', 'rdfs:label' => (string) ($a->origin_label ?: 'model')],
            'prov:endedAtTime' => ['@value' => $a->asserted_at?->toIso8601String(), '@type' => 'xsd:dateTime']])->values()->all();
    }

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

    /**
     * The unit's provenance chain (roadmap phase 9e), unless its overview keeps it from the public: each step a
     * rico:Activity of its transfer type, with its owner taking part (the authority record when the reader may see it,
     * unnamed when it is not published, the name alone when there is none) and its start and end each a Date of its own,
     * as RiC-AG treats dates.
     *
     * @return list<array<string, mixed>>
     */
    private function custody(RecordResource $unit): array
    {
        if ($unit->provenanceOverview && ! $unit->provenanceOverview->is_public) {
            return [];
        }
        $date = fn (ProvenanceEntry $step, string $end) => $step->{$end.'_date'} === null ? null : array_filter([
            '@id' => $step->iri.'#'.$end, '@type' => 'rico:Date', 'rico:normalizedDateValue' => $step->{$end.'_date'},
            'rico:dateQualifier' => $step->{$end === 'start' ? 'startQualifier' : 'endQualifier'}?->label(['en', 'und'])]);

        return $unit->provenanceEntries()->with('owner', 'transferType', 'startQualifier', 'endQualifier')->get()->map(fn (ProvenanceEntry $step) => array_filter([
            '@id' => $step->iri, '@type' => 'rico:Activity', 'rico:name' => (string) $step->transferType->label(['en', 'und']),
            'rico:hasActivityType' => ['@id' => $step->transferType->iri, 'rdfs:label' => (string) $step->transferType->label(['en', 'und'])],
            // An owner whose authority record is not published is not named, as a rights holder is not.
            'rico:hasOrHadParticipant' => $step->owner === null ? ['@type' => 'rico:Agent', 'rico:name' => $step->owner_name]
                : ($this->view->readable($step->owner) ? $this->stub($step->owner) : ['@type' => 'rico:Agent']),
            'rico:hasBeginningDate' => $date($step, 'start'), 'rico:hasEndDate' => $date($step, 'end'),
        ], fn ($v) => $v !== null))->values()->all();
    }

    public function stub(Model $entity): array
    {
        // A unit's name is its title (RiC-O 1.1; the OpenRiC shapes require rico:title of a rico:Record).
        $name = $entity instanceof RecordResource || $entity instanceof Rule || $entity instanceof Instantiation ? 'rico:title' : 'rico:name';
        // The same language-tagged names as its own description (roadmap phase 24): a plain string here would be a
        // second literal for the same entity wherever documents are merged, and break its one-title shapes.
        $names = method_exists($entity, 'namesOfType') ? $this->strings($name, $entity->namesOfType('authorised'))[$name] ?? null : null;

        return array_filter(['@id' => $entity->getAttribute('iri'), '@type' => $this->type($entity), $name => $names ?? $this->view->label($entity)], fn ($v) => $v !== null);
    }

    /** Digital (a digital-file carrier, or files kept), analogue (any other carrier), or null when neither is known. */
    public static function carrierKind(Instantiation $instantiation): ?string
    {
        $carrier = $instantiation->carrierType?->notation;

        return match (true) {
            $carrier === 'digital-file', $carrier === null && $instantiation->files()->exists() => 'digital',
            $carrier !== null => 'analogue',
            default => null,
        };
    }

    private function isRecordSet(Model $entity): bool
    {
        return in_array('rico:RecordSet', (array) $this->type($entity), true);
    }

    /** The RiC-O class: a unit's level, an agent's type (with rico:Agent where its own class is not one Core Discovery names), a repository's rico:CorporateBody. */
    public function type(Model $entity): string|array
    {
        $class = (string) EntityKinds::ricClass($entity);

        return match (true) {
            $entity instanceof Agent && ! in_array($class, ['rico:Person', 'rico:Family', 'rico:CorporateBody'], true) => [$class, 'rico:Agent'],
            // A mandate is a rico:Rule too, said outright, as SHACL infers no subclass: the Rule shape applies to it.
            $class === 'rico:Mandate' => ['rico:Rule', 'rico:Mandate'],
            default => $class,
        };
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
        $names = $names->filter(fn ($n) => $this->view->visible($n))->sortBy(fn ($n) => [! $n->is_original, $n->language, $n->id])->values();

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
