<?php

// OpenRiC Workspace - export an authority record as EAC-CPF 2010 (revised), as Heratio does.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace from the EAC-CPF tag library, not from Heratio's
// code. Texts are given in the language of the authorised name's original;
// names, dates, places, occupations, legal statuses and relations come from the
// entities and relations of the store, not from the narrative. EAC-CPF knows
// three entity types (person, family, corporate body); a position or a
// mechanism has no EAC-CPF form and is not exported.

namespace App\Description;

use App\Models\Agent;
use App\Models\Institution;
use App\Models\Relation;
use App\Models\RicDate;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use XMLWriter;

class EacCpf
{
    public const ENTITY_TYPES = ['person' => 'person', 'family' => 'family', 'corporate-body' => 'corporateBody'];

    /** cpfRelationType for each relation type between agents; any other is associative. */
    private const CPF_RELATION_TYPES = [
        'agent-hierarchical' => 'hierarchical', 'group-subdivision' => 'hierarchical', 'membership' => 'hierarchical', 'leadership' => 'hierarchical',
        'position-holding' => 'hierarchical', 'position-in-group' => 'hierarchical',
        'agent-temporal' => 'temporal', 'sequential' => 'temporal',
        'family' => 'family', 'child' => 'family', 'descendance' => 'family', 'sibling' => 'family', 'spouse' => 'family',
        'guardianship' => 'family', 'adoption' => 'family', 'fostering' => 'family',
    ];

    /** resourceRelationType for each relation type with a unit of description; any other is other. */
    private const RESOURCE_RELATION_TYPES = ['creation' => 'creatorOf', 'accumulation' => 'creatorOf', 'subject' => 'subjectOf'];

    /** Narrative elements (ISAAR(CPF) notation) and the EAC-CPF element each is written into. */
    private const NARRATIVE = ['agent-mandates' => 'mandates', 'agent-internal-structure' => 'structureOrGenealogy', 'agent-general-context' => 'generalContext', 'agent-history' => 'biogHist'];

    public function __construct(private RelationService $relations) {}

    public static function exportable(Agent $agent): bool
    {
        return isset(self::ENTITY_TYPES[$agent->typeNotation()]);
    }

    public function export(Agent $agent): string
    {
        if (! self::exportable($agent)) {
            throw new InvalidArgumentException('EAC-CPF has no entity type for a '.$agent->typeNotation());
        }
        $agent->loadMissing('names.nameType', 'names.dates.property', 'identifiers.identifierType', 'descriptionValues.element', 'dates.property', 'descriptionStatus', 'maintainer.names.nameType', 'maintainer.descriptionValues.element');
        $language = $agent->originalLanguage('agent-authorised-name');
        $text = fn (string $notation) => $agent->descriptionValues->first(fn ($v) => $v->element->notation === $notation && $v->language === $language)?->value;
        $relations = $this->relations->of($agent);
        $institution = Institution::findOrFail($agent->institution_id);

        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('eac-cpf');
        $x->writeAttribute('xmlns', 'urn:isbn:1-931666-33-4');
        $x->writeAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $x->writeAttribute('xml:lang', $language);

        $x->startElement('control');
        $x->writeElement('recordId', $agent->description_identifier ?? $agent->id);
        $x->startElement('otherRecordId');
        $x->writeAttribute('localType', 'persistentIri');
        $x->text($agent->iri);
        $x->endElement();
        $x->writeElement('maintenanceStatus', $agent->deleted_at ? 'deleted' : ($agent->created_at == $agent->updated_at ? 'new' : 'revised'));
        $x->startElement('maintenanceAgency');
        $x->writeElement('agencyName', $agent->maintainer ? EntityKinds::label($agent->maintainer) : $institution->name);
        $x->endElement();
        $x->startElement('maintenanceHistory');
        foreach ($this->history($agent) as [$type, $at, $who]) {
            $x->startElement('maintenanceEvent');
            $x->writeElement('eventType', $type);
            $x->startElement('eventDateTime');
            $x->writeAttribute('standardDateTime', $at->toAtomString());
            $x->text($at->format('Y-m-d H:i'));
            $x->endElement();
            $x->writeElement('agentType', $who ? 'human' : 'machine');
            $x->writeElement('agent', $who ?? 'OpenRiC Workspace');
            $x->endElement();
        }
        $x->endElement();
        if ($sources = $text('agent-sources')) {
            $x->startElement('sources');
            $x->startElement('source');
            $x->writeElement('sourceEntry', $sources);
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();

        $x->startElement('cpfDescription');
        $x->startElement('identity');
        foreach ($agent->identifiers as $identifier) {
            $x->startElement('entityId');
            $x->writeAttribute('localType', $identifier->identifierType->notation);
            $x->text($identifier->value);
            $x->endElement();
        }
        $x->writeElement('entityType', self::ENTITY_TYPES[$agent->typeNotation()]);
        foreach ($agent->names->sortBy(fn ($n) => [$n->nameType->notation !== 'authorised', ! $n->is_original]) as $name) {
            $x->startElement('nameEntry');
            $x->writeAttribute('localType', $name->nameType->notation);
            $x->writeAttribute('xml:lang', $name->language);
            $x->writeElement('part', $name->value);
            $this->useDates($x, $name->usedDate('used-from'), $name->usedDate('used-to'));
            if ($name->nameType->notation === 'authorised') {
                $x->writeElement('authorizedForm', $text('agent-rules-conventions') ?? 'ISAAR(CPF)');
            } else {
                $x->writeElement('alternativeForm', $text('agent-rules-conventions') ?? 'ISAAR(CPF)');
            }
            $x->endElement();
        }
        $x->endElement();

        $x->startElement('description');
        $existence = $agent->dates->filter(fn (RicDate $d) => in_array($d->property->notation, ['existence', 'birth', 'death'], true));
        if ($existence->isNotEmpty() || $text('agent-dates-of-existence')) {
            $x->startElement('existDates');
            $from = $existence->first(fn ($d) => in_array($d->property->notation, ['birth', 'existence'], true));
            $to = $existence->first(fn ($d) => $d->property->notation === 'death') ?? ($from && $from->latest != $from->earliest ? $from : null);
            if ($from || $to) {
                $x->startElement('dateRange');
                $this->date($x, 'fromDate', $from, 'earliest');
                $this->date($x, 'toDate', $to, $to === $from ? 'latest' : 'earliest');
                $x->endElement();
            }
            if ($written = $text('agent-dates-of-existence')) {
                $x->startElement('descriptiveNote');
                $x->writeElement('p', $written);
                $x->endElement();
            }
            $x->endElement();
        }
        $ofType = fn (string $type) => $relations->filter(fn (Relation $r) => $r->relationType->notation === $type && in_array($r->standing(), ['accepted', 'disputed'], true));
        $this->terms($x, 'places', 'place', 'placeEntry', $ofType('place'));
        $this->terms($x, 'legalStatuses', 'legalStatus', 'term', $ofType('legal-status'));
        if ($functions = $text('agent-functions')) {
            $x->startElement('functions');
            $x->startElement('function');
            $x->startElement('descriptiveNote');
            $x->writeElement('p', $functions);
            $x->endElement();
            $x->endElement();
            $x->endElement();
        }
        $this->terms($x, 'occupations', 'occupation', 'term', $ofType('occupation'));
        foreach (self::NARRATIVE as $notation => $element) {
            if ($value = $text($notation)) {
                $x->startElement($element);
                if ($element === 'mandates') {
                    $x->startElement('mandate');
                    $x->startElement('descriptiveNote');
                }
                foreach (preg_split('/\R{2,}/', $value) as $paragraph) {
                    $x->writeElement('p', $paragraph);
                }
                if ($element === 'mandates') {
                    $x->endElement();
                    $x->endElement();
                }
                $x->endElement();
            }
        }
        $x->endElement();

        $toAgents = $relations->filter(fn (Relation $r) => in_array(EntityKinds::of($r->other), ['agent', 'repository'], true) && in_array($r->standing(), ['accepted', 'disputed'], true));
        $toUnits = $relations->filter(fn (Relation $r) => EntityKinds::of($r->other) === 'record' && in_array($r->standing(), ['accepted', 'disputed'], true));
        if ($toAgents->isNotEmpty() || $toUnits->isNotEmpty()) {
            $x->startElement('relations');
            foreach ($toAgents as $relation) {
                $this->relation($x, 'cpfRelation', 'cpfRelationType', self::CPF_RELATION_TYPES[$relation->relationType->notation] ?? 'associative', $relation);
            }
            foreach ($toUnits as $relation) {
                $this->relation($x, 'resourceRelation', 'resourceRelationType', self::RESOURCE_RELATION_TYPES[$relation->relationType->notation] ?? 'other', $relation);
            }
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    /** @return list<array{0: string, 1: CarbonInterface, 2: ?string}> created, then each update, from the audit log */
    private function history(Agent $agent): array
    {
        $rows = DB::table('audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where(['a.subject_table' => 'agents', 'a.subject_id' => $agent->id])->orderBy('a.occurred_at')
            ->get(['a.occurred_at', 'a.before', 'u.name']);
        $events = [];
        foreach ($rows as $i => $row) {
            $events[] = [$i === 0 ? 'created' : ($agent->deleted_at && $i === count($rows) - 1 ? 'deleted' : 'updated'), CarbonImmutable::parse($row->occurred_at), $row->name];
        }

        return $events ?: [['created', CarbonImmutable::parse($agent->created_at), null]];
    }

    private function useDates(XMLWriter $x, ?RicDate $from, ?RicDate $to): void
    {
        if ($from || $to) {
            $x->startElement('useDates');
            $x->startElement('dateRange');
            $this->date($x, 'fromDate', $from, 'earliest');
            $this->date($x, 'toDate', $to, 'latest');
            $x->endElement();
            $x->endElement();
        }
    }

    private function date(XMLWriter $x, string $element, ?RicDate $date, string $bound): void
    {
        if ($date === null) {
            return;
        }
        $x->startElement($element);
        if ($date->{$bound}) {
            $x->writeAttribute('standardDate', $date->{$bound}->format('Y-m-d'));
        }
        $x->text($date->expressed ?? $date->normalized_value ?? '');
        $x->endElement();
    }

    private function terms(XMLWriter $x, string $plural, string $singular, string $entry, $relations): void
    {
        if ($relations->isEmpty()) {
            return;
        }
        $x->startElement($plural);
        foreach ($relations as $relation) {
            $x->startElement($singular);
            $x->startElement($entry);
            $x->writeAttribute('vocabularySource', $relation->other->iri);
            $x->text(EntityKinds::label($relation->other));
            $x->endElement();
            $from = $relation->datedAs('beginning');
            $to = $relation->datedAs('end');
            if ($from || $to) {
                $x->startElement('dateRange');
                $this->date($x, 'fromDate', $from, 'earliest');
                $this->date($x, 'toDate', $to, 'latest');
                $x->endElement();
            }
            $x->endElement();
        }
        $x->endElement();
    }

    private function relation(XMLWriter $x, string $element, string $typeAttribute, string $type, Relation $relation): void
    {
        $x->startElement($element);
        $x->writeAttribute($typeAttribute, $type);
        $x->writeAttribute('xlink:type', 'simple');
        $x->writeAttribute('xlink:href', $relation->other->iri);
        $x->writeAttribute('xlink:arcrole', $relation->relationType->iri);
        $x->writeElement('relationEntry', EntityKinds::label($relation->other));
        $from = $relation->datedAs('beginning');
        $to = $relation->datedAs('end');
        if ($from || $to) {
            $x->startElement('dateRange');
            $this->date($x, 'fromDate', $from, 'earliest');
            $this->date($x, 'toDate', $to, 'latest');
            $x->endElement();
        }
        if ($relation->description) {
            $x->startElement('descriptiveNote');
            $x->writeElement('p', $relation->description);
            $x->endElement();
        }
        $x->endElement();
    }
}
