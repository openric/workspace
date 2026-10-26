<?php

// OpenRiC Workspace - one unit of description as simple Dublin Core (oai_dc).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The ISAD(G) to Dublin Core crosswalk AtoM and Heratio follow: title,
// creators, subjects and names as dc:subject, places as dc:coverage, scope
// and content as dc:description, the repository as dc:publisher, dates in
// ISO 8601, level as dc:type, extent as dc:format, the reference code and
// persistent IRI as dc:identifier, the unit above and associated units as
// dc:relation, access and reproduction conditions as dc:rights. What may be
// said is decided by ExportView.

namespace App\Exchange;

use App\Models\RecordResource;
use App\Rights\PublicRights;
use XMLWriter;

class DublinCore
{
    public function export(RecordResource $unit, ExportView $view): string
    {
        $language = $view->languageOf($unit);
        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
        $x->writeAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $x->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->writeAttribute('xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd');
        $element = function (string $name, ?string $text, ?string $lang = null) use ($x) {
            if ($text === null || trim($text) === '') {
                return;
            }
            $x->startElement("dc:$name");
            if ($lang) {
                $x->writeAttribute('xml:lang', $lang);
            }
            $x->text($text);
            $x->endElement();
        };
        $text = function (string $name, string $notation) use ($element, $view, $unit, $language) {
            if ($value = $view->valueWithLanguage($unit, $notation, $language)) {
                $element($name, $value[0], $value[1]);
            }
        };

        $text('title', 'title');
        foreach ($view->creators($unit) as $relation) {
            $element('creator', $view->label($relation->other));
        }
        $points = $view->accessPoints($unit);
        foreach ($points['subjects']->merge($points['names']) as $relation) {
            $element('subject', $view->label($relation->other));
        }
        $text('description', 'scope-and-content');
        if ($unit->repository) {
            $element('publisher', $view->label($unit->repository));
        }
        foreach ($unit->dates as $date) {
            $element('date', ExportView::isoInterval($date) ?? $date->display());
        }
        $element('type', $unit->level->label(['en', 'und']));
        foreach ($points['genres'] as $relation) {
            $element('type', $view->label($relation->other));
        }
        $text('format', 'extent');
        $element('identifier', $unit->referenceCode());
        $element('identifier', $unit->iri);
        $text('language', 'languages-of-material');
        if ($unit->parent && $view->may('read', 'description', $unit->parent)) {
            $element('relation', $unit->parent->iri);
        }
        foreach ($view->relations($unit, 'associated-record') as $relation) {
            $element('relation', $relation->other->iri);
        }
        foreach ($points['places'] as $relation) {
            $element('coverage', $view->label($relation->other));
        }
        $text('rights', 'conditions-governing-access');
        $text('rights', 'conditions-governing-reproduction');
        // Rights in force, by their RightsStatements.org and Creative Commons IRIs (ADR 0008).
        foreach ((new PublicRights($view))->rights($unit) as $right) {
            foreach ([$right->statement, $right->licence] as $concept) {
                if ($concept) {
                    $element('rights', $concept->iri);
                }
            }
        }
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }
}
