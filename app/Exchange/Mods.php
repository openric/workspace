<?php

// OpenRiC Workspace - one unit of description as MODS 3.8.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace from the MODS 3.8 schema and the ISAD(G) to MODS
// mapping Heratio's export follows. Dates go out as EDTF (MODS 3.8 knows the
// encoding); creators, subjects, places and names carry their persistent IRI
// as valueURI; the unit above is the host item. What may be said is decided
// by ExportView.

namespace App\Exchange;

use App\Models\Agent;
use App\Models\Institution;
use App\Models\RecordResource;
use Illuminate\Database\Eloquent\Model;
use XMLWriter;

class Mods
{
    /** ISAD(G) narrative elements (notation) and the MODS note type each is written as; null for a plain note. */
    private const NOTES = [
        'creator-history' => 'biographical/historical', 'archival-history' => 'ownership', 'acquisition' => 'acquisition', 'appraisal' => 'appraisal',
        'accruals' => 'accruals', 'arrangement' => 'arrangement', 'languages-of-material' => 'language', 'finding-aids-note' => 'finding aids',
        'location-of-originals' => 'original location', 'location-of-copies' => 'additional physical form', 'related-units' => 'related material',
        'publication-note' => 'publications', 'note' => null, 'archivists-note' => 'admin', 'dates-expressed' => 'date',
    ];

    private const NAME_TYPES = ['person' => 'personal', 'family' => 'family'];

    private XMLWriter $x;

    private ExportView $view;

    public function export(RecordResource $unit, ExportView $view): string
    {
        $this->view = $view;
        $language = $view->languageOf($unit);
        $x = $this->x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('mods');
        $x->writeAttribute('xmlns', 'http://www.loc.gov/mods/v3');
        $x->writeAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $x->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->writeAttribute('xsi:schemaLocation', 'http://www.loc.gov/mods/v3 https://www.loc.gov/standards/mods/v3/mods-3-8.xsd');
        $x->writeAttribute('version', '3.8');

        if ($title = $view->valueWithLanguage($unit, 'title', $language)) {
            $x->startElement('titleInfo');
            $this->lang($title[1], $language);
            $x->writeElement('title', $title[0]);
            $x->endElement();
        }
        foreach (['parallel' => 'translated', 'other' => 'alternative'] as $nameType => $titleType) {
            foreach ($unit->namesOfType($nameType)->filter(fn ($n) => $view->visible($n)) as $name) {
                $x->startElement('titleInfo');
                $x->writeAttribute('type', $titleType);
                $x->writeAttribute('xml:lang', $name->language);
                $x->writeElement('title', $name->value);
                $x->endElement();
            }
        }
        foreach ($view->creators($unit) as $relation) {
            $this->name($relation->other, $relation->relationType->notation === 'accumulation' ? 'collector' : 'creator');
        }
        $x->startElement('typeOfResource');
        if ($unit->children()->exists()) {
            $x->writeAttribute('collection', 'yes');
        }
        $x->writeAttribute('manuscript', 'yes');
        $x->text('mixed material');
        $x->endElement();
        $x->startElement('genre');
        $x->writeAttribute('authority', 'local');
        $x->writeAttribute('valueURI', $unit->level->iri);
        $x->text((string) $unit->level->label(['en', 'und']));
        $x->endElement();
        if ($unit->dates->isNotEmpty()) {
            $x->startElement('originInfo');
            $x->writeAttribute('eventType', 'production');
            foreach ($unit->dates as $i => $date) {
                $element = $date->property->notation === 'creation' ? 'dateCreated' : 'dateOther';
                if ($date->normalized_value) {
                    $x->startElement($element);
                    $x->writeAttribute('encoding', 'edtf');
                    if ($i === 0) {
                        $x->writeAttribute('keyDate', 'yes');
                    }
                    if ($element === 'dateOther') {
                        $x->writeAttribute('type', $date->property->notation);
                    }
                    $x->text($date->normalized_value);
                    $x->endElement();
                }
                if ($date->expressed) {
                    $x->startElement($element);
                    if ($element === 'dateOther') {
                        $x->writeAttribute('type', $date->property->notation);
                    }
                    $x->text($date->expressed);
                    $x->endElement();
                }
            }
            $x->endElement();
        }
        $extent = $view->valueWithLanguage($unit, 'extent', $language);
        $physical = $view->valueWithLanguage($unit, 'physical-characteristics', $language);
        if ($extent || $physical) {
            $x->startElement('physicalDescription');
            if ($extent) {
                $x->writeElement('extent', $extent[0]);
            }
            if ($physical) {
                $x->startElement('note');
                $x->writeAttribute('type', 'physical characteristics');
                $x->text($physical[0]);
                $x->endElement();
            }
            $x->endElement();
        }
        if ($scope = $view->valueWithLanguage($unit, 'scope-and-content', $language)) {
            $x->startElement('abstract');
            $this->lang($scope[1], $language);
            $x->text($scope[0]);
            $x->endElement();
        }
        foreach (['conditions-governing-access' => 'restriction on access', 'conditions-governing-reproduction' => 'use and reproduction'] as $notation => $type) {
            if ($value = $view->valueWithLanguage($unit, $notation, $language)) {
                $x->startElement('accessCondition');
                $x->writeAttribute('type', $type);
                $this->lang($value[1], $language);
                $x->text($value[0]);
                $x->endElement();
            }
        }
        foreach (self::NOTES as $notation => $type) {
            if ($value = $view->valueWithLanguage($unit, $notation, $language)) {
                $x->startElement('note');
                if ($type) {
                    $x->writeAttribute('type', $type);
                }
                $this->lang($value[1], $language);
                $x->text($value[0]);
                $x->endElement();
            }
        }
        $points = $view->accessPoints($unit);
        foreach ($points['subjects'] as $relation) {
            $this->subject('topic', $relation->other);
        }
        foreach ($points['places'] as $relation) {
            $this->subject('geographic', $relation->other);
        }
        foreach ($points['names'] as $relation) {
            $x->startElement('subject');
            $this->name($relation->other, null);
            $x->endElement();
        }
        foreach ($points['genres'] as $relation) {
            if (($label = $view->label($relation->other)) !== null) {
                $x->startElement('genre');
                $x->writeAttribute('authority', 'local');
                $x->writeAttribute('type', 'documentary form');
                $x->writeAttribute('valueURI', $relation->other->iri);
                $x->text($label);
                $x->endElement();
            }
        }
        if ($unit->parent && $view->may('read', 'description', $unit->parent)) {
            $unit->parent->loadMissing('names.nameType', 'names.state');
            $this->related('host', $unit->parent);
        }
        foreach ($view->relations($unit, 'associated-record') as $relation) {
            $relation->other->loadMissing('names.state');
            $this->related(null, $relation->other);
        }
        foreach (array_filter(['local' => $unit->identifier, 'reference code' => $unit->referenceCode(), 'uri' => $unit->iri]) as $type => $value) {
            $x->startElement('identifier');
            $x->writeAttribute('type', $type);
            $x->text($value);
            $x->endElement();
        }
        foreach ($unit->identifiers->reject(fn ($i) => $i->identifierType->notation === 'local') as $identifier) {
            $x->startElement('identifier');
            $x->writeAttribute('type', $identifier->identifierType->notation);
            $x->text($identifier->value);
            $x->endElement();
        }
        if ($unit->repository && ($holder = $view->label($unit->repository))) {
            $x->startElement('location');
            $x->startElement('physicalLocation');
            $x->writeAttribute('valueURI', $unit->repository->iri);
            $x->text($holder);
            $x->endElement();
            $x->endElement();
        }

        $x->startElement('recordInfo');
        $x->writeElement('descriptionStandard', 'isadg');
        $x->writeElement('recordContentSource', ($unit->repository ? $view->label($unit->repository) : null) ?? (string) Institution::find($unit->institution_id)?->name);
        $x->startElement('recordCreationDate');
        $x->writeAttribute('encoding', 'w3cdtf');
        $x->text($unit->created_at->toAtomString());
        $x->endElement();
        $x->startElement('recordChangeDate');
        $x->writeAttribute('encoding', 'w3cdtf');
        $x->text($unit->updated_at->toAtomString());
        $x->endElement();
        $x->startElement('recordIdentifier');
        $x->writeAttribute('source', 'OpenRiC Workspace');
        $x->text($unit->description_identifier ?? $unit->id);
        $x->endElement();
        $x->writeElement('recordOrigin', 'Exported from OpenRiC Workspace.');
        $x->startElement('languageOfCataloging');
        $x->startElement('languageTerm');
        $x->writeAttribute('type', 'code');
        $x->writeAttribute('authority', 'rfc5646');
        $x->text($language);
        $x->endElement();
        $x->endElement();
        $x->endElement();

        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    private function name(Model $agent, ?string $role): void
    {
        $label = $this->view->label($agent);
        if ($label === null) {
            return;
        }
        $x = $this->x;
        $x->startElement('name');
        $x->writeAttribute('type', $agent instanceof Agent ? (self::NAME_TYPES[$agent->typeNotation()] ?? 'corporate') : 'corporate');
        $x->writeAttribute('valueURI', $agent->iri);
        $x->writeElement('namePart', $label);
        if ($role) {
            $x->startElement('role');
            $x->startElement('roleTerm');
            $x->writeAttribute('type', 'text');
            $x->writeAttribute('authority', 'marcrelator');
            $x->text($role);
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
    }

    private function subject(string $element, Model $entity): void
    {
        $label = $this->view->label($entity);
        if ($label === null) {
            return;
        }
        $this->x->startElement('subject');
        $this->x->startElement($element);
        $this->x->writeAttribute('valueURI', $entity->iri);
        $this->x->text($label);
        $this->x->endElement();
        $this->x->endElement();
    }

    private function related(?string $type, RecordResource $unit): void
    {
        $x = $this->x;
        $x->startElement('relatedItem');
        if ($type) {
            $x->writeAttribute('type', $type);
        }
        $x->writeAttribute('xlink:href', $unit->iri);
        if ($title = $this->view->value($unit, 'title')) {
            $x->startElement('titleInfo');
            $x->writeElement('title', $title);
            $x->endElement();
        }
        $x->startElement('identifier');
        $x->writeAttribute('type', 'uri');
        $x->text($unit->iri);
        $x->endElement();
        $x->endElement();
    }

    private function lang(string $valueLanguage, string $language): void
    {
        if ($valueLanguage !== $language) {
            $this->x->writeAttribute('xml:lang', $valueLanguage);
        }
    }
}
