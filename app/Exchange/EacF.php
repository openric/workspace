<?php

// OpenRiC Workspace - export an activity as EAC-F, the TS-EAS Encoded Archival Context - Functions schema of the EAS
// 2026-07 release (roadmap phase 11a; openric/service #14).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace from the EAF 1.0.0 schema, valid against it (tests/Fixtures/eas-2026-07), not from
// Heratio's EacFSerializer, which wrote a draft namespace. An activity is ISDF's function, and EAC-F serialises ISDF:
// its names in each language and type, its type as a classification, its identifiers, its date, its description,
// history and legislation, and its relations to agents, records, places and other activities by their IRIs. EAS
// gives relation and target types as open tokens: the Workspace says so (otherRelationTypeEncoding) and writes its
// own relation type notations, and, as EAC-F has no attribute for it, puts which end of the relation the other is
// (source or target) on the label's localType, so a relation reads back the right way round.

namespace App\Exchange;

use App\Models\Activity;
use App\Models\Institution;
use App\Models\Relation;
use App\Models\RicDate;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use XMLWriter;

class EacF
{
    public const NAMESPACE = 'https://standards.openpreservation.org/eaf/v1';

    /** Name element (ISDF) and the nameEntry status and localType it is written with. */
    private const NAMES = ['activity-name' => ['authorized', 'authorised'], 'activity-parallel-name' => ['alternative', 'parallel'], 'activity-other-name' => ['alternative', 'other']];

    public function __construct(private RelationService $relations) {}

    public function export(Activity $activity): string
    {
        $activity->loadMissing('names.nameType', 'identifiers.identifierType', 'descriptionValues.element', 'dates.property', 'activityType.labels', 'descriptionStatus');
        $language = $activity->originalLanguage('activity-name');
        $text = fn (string $notation) => $activity->descriptionValues->first(fn ($v) => $v->element->notation === $notation && $v->language === $language)?->value;
        $institution = Institution::findOrFail($activity->institution_id);

        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('eaf');
        $x->writeAttribute('xmlns', self::NAMESPACE);
        $x->writeAttribute('languageOfElement', $language);

        $x->startElement('control');
        $x->writeAttribute('maintenanceStatus', $activity->created_at == $activity->updated_at ? 'new' : 'revised');
        $x->writeAttribute('languageEncoding', 'ietf-bcp-47');
        $x->writeAttribute('dateEncoding', 'iso8601');
        $x->writeAttribute('relationTypeEncoding', 'otherRelationTypeEncoding');
        $x->writeAttribute('targetTypeEncoding', 'otherTargetTypeEncoding');
        $x->writeElement('recordId', $activity->description_identifier ?? $activity->id);
        $x->startElement('maintenanceAgency');
        $x->writeElement('agencyName', $institution->name);
        $x->endElement();
        $x->startElement('maintenanceHistory');
        foreach ($this->history($activity) as [$type, $at, $who]) {
            $x->startElement('maintenanceEvent');
            $x->writeAttribute('maintenanceEventType', $type);
            $x->startElement('agent');
            $x->writeAttribute('agentType', $who ? 'human' : 'machine');
            $x->writeElement('label', $who ?? 'OpenRiC Workspace');
            $x->endElement();
            $x->startElement('eventDateTime');
            $x->writeAttribute('standardDateTime', $at->toAtomString());
            $x->text($at->format('Y-m-d H:i'));
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
        if ($sources = $text('activity-sources')) {
            $x->startElement('sources');
            $x->startElement('source');
            $x->writeElement('reference', $sources);
            $x->endElement();
            $x->endElement();
        }
        $x->startElement('otherRecordId');
        $x->writeAttribute('localType', 'persistentIri');
        $x->text($activity->iri);
        $x->endElement();
        $x->endElement();

        $x->startElement('functionsDescription');
        $x->startElement('identity');
        $names = $activity->names->sortBy(fn ($n) => [$n->nameType->notation !== 'authorised', ! $n->is_original]);
        foreach ($names as $name) {
            $element = ['authorised' => 'activity-name', 'parallel' => 'activity-parallel-name'][$name->nameType->notation] ?? 'activity-other-name';
            [$status, $localType] = self::NAMES[$element];
            $x->startElement('nameEntry');
            $x->writeAttribute('status', $status);
            $x->writeAttribute('localType', $localType);
            $x->writeAttribute('languageOfElement', $name->language);
            $x->writeElement('part', $name->value);
            $x->endElement();
        }
        $classification = $text('activity-classification');
        if ($activity->activityType || $classification) {
            $x->startElement('classifications');
            if ($activity->activityType) {
                $x->startElement('classification');
                $x->writeAttribute('localType', 'activityType');
                $x->writeAttribute('valueURI', $activity->activityType->iri);
                $x->text((string) $activity->activityType->label(['en', 'und']));
                $x->endElement();
            }
            if ($classification) {
                $x->startElement('classification');
                $x->writeAttribute('localType', 'classification');
                $x->text($classification);
                $x->endElement();
            }
            $x->endElement();
        }
        if ($local = $activity->identifiers->first(fn ($i) => $i->identifierType->notation === 'local')) {
            $x->startElement('identityId');
            $x->writeAttribute('localType', 'local');
            $x->text($local->value);
            $x->endElement();
        }
        $x->endElement();

        $date = $activity->dates->first();
        $paragraphs = fn (string $element, ?string $value) => $value === null ? null : [$element, $value];
        $narrative = array_filter([$paragraphs('functionDescription', $text('activity-description')), $paragraphs('functionHistory', $text('activity-history'))]);
        $legislation = $text('activity-legislation');
        if ($date || $narrative || $legislation) {
            $x->startElement('description');
            if ($date) {
                $x->startElement('functionDates');
                $this->date($x, $date);
                $x->endElement();
            }
            foreach ($narrative as [$element, $value]) {
                $x->startElement($element);
                foreach (preg_split('/\R{2,}/', $value) as $p) {
                    $x->writeElement('p', $p);
                }
                $x->endElement();
            }
            if ($legislation) {
                $x->startElement('legislations');
                foreach (preg_split('/\R{2,}/', $legislation) as $law) {
                    $x->startElement('legislation');
                    $x->writeElement('p', $law);
                    $x->endElement();
                }
                $x->endElement();
            }
            $x->endElement();
        }

        $relations = $this->relations->of($activity)->filter(fn (Relation $r) => in_array($r->standing(), ['accepted', 'disputed'], true) && $r->other?->getAttribute('iri'));
        if ($relations->isNotEmpty()) {
            $x->startElement('relations');
            foreach ($relations as $relation) {
                $x->startElement('relation');
                $x->writeAttribute('relationType', $relation->relationType->notation ?? $relation->kind());
                $x->writeAttribute('targetType', EntityKinds::of($relation->other));
                $x->startElement('label');
                $x->writeAttribute('valueURI', $relation->other->getAttribute('iri'));
                $x->writeAttribute('localType', $relation->fromSource ? 'target' : 'source');
                $x->text((string) EntityKinds::label($relation->other));
                $x->endElement();
                [$from, $to] = [$relation->datedAs('beginning'), $relation->datedAs('end')];
                if ($from || $to) {
                    $x->startElement('dateRange');
                    $this->bound($x, 'fromDate', $from);
                    $this->bound($x, 'toDate', $to);
                    $x->endElement();
                }
                if ($relation->description) {
                    $x->startElement('descriptiveNote');
                    $x->writeElement('p', $relation->description);
                    $x->endElement();
                }
                $x->endElement();
            }
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    /** A date: its EDTF as the standard date, written as a range when it is one. */
    private function date(XMLWriter $x, RicDate $date): void
    {
        $value = (string) $date->normalized_value;
        if (str_contains($value, '/')) {
            [$from, $to] = explode('/', $value, 2);
            $x->startElement('dateRange');
            foreach (['fromDate' => $from, 'toDate' => $to] as $element => $part) {
                if ($part !== '' && $part !== '..') {
                    $x->startElement($element);
                    $x->writeAttribute('standardDate', $part);
                    $x->text($element === 'fromDate' && $date->expressed ? $date->expressed : $part);
                    $x->endElement();
                }
            }
            $x->endElement();
        } else {
            $x->startElement('date');
            if ($value !== '') {
                $x->writeAttribute('standardDate', $value);
            }
            $x->text($date->expressed ?? $value);
            $x->endElement();
        }
    }

    private function bound(XMLWriter $x, string $element, ?RicDate $date): void
    {
        if ($date?->normalized_value) {
            $x->startElement($element);
            $x->writeAttribute('standardDate', $date->normalized_value);
            $x->text($date->expressed ?? $date->normalized_value);
            $x->endElement();
        }
    }

    /** @return list<array{0: string, 1: CarbonImmutable, 2: ?string}> created, then each revision, from the audit log */
    private function history(Activity $activity): array
    {
        $rows = DB::table('audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where(['a.subject_table' => 'activities', 'a.subject_id' => $activity->id])->orderBy('a.occurred_at')->get(['a.occurred_at', 'u.name']);
        $events = [];
        foreach ($rows as $i => $row) {
            $events[] = [$i === 0 ? 'created' : 'revised', CarbonImmutable::parse($row->occurred_at), $row->name];
        }

        return $events ?: [['created', CarbonImmutable::parse($activity->created_at), null]];
    }
}
