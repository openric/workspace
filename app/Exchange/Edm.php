<?php

// OpenRiC Workspace - one unit of description in the Europeana Data Model (roadmap phase 16d), after Heratio's
// ahg-federation EdmSerializer: an edm:ProvidedCHO (title, identifiers, description, creators, subjects, genres,
// languages, dates, places, its EDM type, rights, extent and holding repository), its ore:Aggregation (data provider,
// isShownAt its persistent IRI, isShownBy its reference copy, object its thumbnail, edm:rights), an edm:WebResource for
// each file the public may receive, and the edm:Agent, edm:Place (with its WGS84 position) and edm:TimeSpan it names.
// What may be said is decided by ExportView, as for the public; edm:rights is the RightsStatements.org or Creative
// Commons IRI of the right in force (ADR 0008), else the institution's default.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Exchange;

use App\Description\InstantiationService;
use App\Models\DigitalFile;
use App\Models\FederationShareSetting;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Rights\PublicRights;
use XMLWriter;

class Edm
{
    public const NS = ['rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'edm' => 'http://www.europeana.eu/schemas/edm/', 'ore' => 'http://www.openarchives.org/ore/terms/',
        'dc' => 'http://purl.org/dc/elements/1.1/', 'dcterms' => 'http://purl.org/dc/terms/', 'skos' => 'http://www.w3.org/2004/02/skos/core#',
        'wgs84_pos' => 'http://www.w3.org/2003/01/geo/wgs84_pos#'];

    public const DEFAULT_RIGHTS = 'http://rightsstatements.org/vocab/InC/1.0/';

    public function export(RecordResource $unit, ExportView $view, Institution $institution): string
    {
        $share = FederationShareSetting::for($institution->id);
        $language = $view->languageOf($unit);
        $files = Instantiation::where('record_resource_id', $unit->id)->whereNull('deleted_at')->with('files.use', 'files.instantiation.record', 'files.instantiation.availability')->get()
            ->filter(fn ($i) => $view->readable($i))->flatMap->files->filter(fn (DigitalFile $f) => app(InstantiationService::class)->deliverable(null, $f))->values();
        $url = fn (DigitalFile $f) => route('discover.file', $f->id);
        $reference = $files->first(fn ($f) => in_array($f->use->notation, ['reference', 'access'], true));
        $thumbnail = $files->first(fn ($f) => $f->use->notation === 'thumbnail');
        $rights = collect((new PublicRights($view))->rights($unit))->flatMap(fn ($r) => [$r->statement?->iri, $r->licence?->iri])->filter()->first()
            ?? ($share->europeana_default_rights ?: self::DEFAULT_RIGHTS);
        $points = $view->accessPoints($unit);
        $creators = collect($view->creators($unit))->map->other;
        $places = $points['places']->map->other;

        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElementNs('rdf', 'RDF', self::NS['rdf']);
        foreach (self::NS as $prefix => $ns) {
            $prefix === 'rdf' || $x->writeAttribute("xmlns:$prefix", $ns);
        }
        $element = function (string $name, ?string $text, ?string $lang = null, ?string $resource = null) use ($x) {
            if ($resource === null && ($text === null || trim($text) === '')) {
                return;
            }
            $x->startElement($name);
            $lang && $x->writeAttribute('xml:lang', $lang);
            $resource !== null ? $x->writeAttribute('rdf:resource', $resource) : $x->text($text);
            $x->endElement();
        };
        $text = function (string $name, string $notation) use ($element, $view, $unit, $language) {
            if ($value = $view->valueWithLanguage($unit, $notation, $language)) {
                $element($name, $value[0], $value[1]);
            }
        };

        $x->startElement('edm:ProvidedCHO');
        $x->writeAttribute('rdf:about', $unit->iri.'#cho');
        $text('dc:title', 'title');
        $element('dc:identifier', $unit->referenceCode());
        $element('dc:identifier', $unit->iri);
        $text('dc:description', 'scope-and-content');
        foreach ($creators as $agent) {
            $element('dc:creator', null, null, $agent->iri);
        }
        foreach ($points['subjects']->merge($points['names']) as $relation) {
            $element('dc:subject', $view->label($relation->other), $language);
        }
        foreach ($points['genres'] as $relation) {
            $element('dc:type', $view->label($relation->other), $language);
        }
        $text('dc:language', 'languages-of-material');
        foreach ($unit->dates as $date) {
            $element('dc:date', ExportView::isoInterval($date) ?? $date->display());
        }
        foreach ($places as $place) {
            $element('dcterms:spatial', null, null, $place->iri);
        }
        $element('edm:type', self::type($files));
        $view->valueWithLanguage($unit, 'conditions-governing-reproduction', $language) ? $text('dc:rights', 'conditions-governing-reproduction') : $text('dc:rights', 'conditions-governing-access');
        $text('dc:format', 'extent');
        if ($unit->repository && $view->readable($unit->repository)) {
            $element('dc:contributor', $view->label($unit->repository), $language);
        }
        foreach ($unit->dates as $i => $date) {
            ($date->earliest || $date->latest) && $element('dcterms:temporal', null, null, $unit->iri.'#timespan-'.$i);
        }
        $x->endElement();

        $x->startElement('ore:Aggregation');
        $x->writeAttribute('rdf:about', $unit->iri.'#aggregation');
        $element('edm:aggregatedCHO', null, null, $unit->iri.'#cho');
        $element('edm:dataProvider', $share->europeana_data_provider ?: $institution->name);
        $element('edm:provider', $share->europeana_data_provider ?: $institution->name);
        $element('edm:isShownAt', null, null, $unit->iri);
        $reference && $element('edm:isShownBy', null, null, $url($reference));
        $thumbnail && $element('edm:object', null, null, $url($thumbnail));
        $element('edm:rights', null, null, $rights);
        $x->endElement();

        foreach ($files as $file) {
            $x->startElement('edm:WebResource');
            $x->writeAttribute('rdf:about', $url($file));
            $element('dc:format', $file->mime_type);
            $element('edm:rights', null, null, $rights);
            $x->endElement();
        }
        foreach ($creators as $agent) {
            $x->startElement('edm:Agent');
            $x->writeAttribute('rdf:about', $agent->iri);
            $element('skos:prefLabel', $view->label($agent), $language);
            $x->endElement();
        }
        foreach ($places as $place) {
            $x->startElement('edm:Place');
            $x->writeAttribute('rdf:about', $place->iri);
            $element('skos:prefLabel', $view->label($place), $language);
            $place->latitude !== null && $element('wgs84_pos:lat', (string) (float) $place->latitude);
            $place->longitude !== null && $element('wgs84_pos:long', (string) (float) $place->longitude);
            $x->endElement();
        }
        foreach ($unit->dates as $i => $date) {
            if (! $date->earliest && ! $date->latest) {
                continue;
            }
            $x->startElement('edm:TimeSpan');
            $x->writeAttribute('rdf:about', $unit->iri.'#timespan-'.$i);
            $element('edm:begin', $date->earliest ? substr((string) $date->earliest, 0, 10) : null);
            $element('edm:end', $date->latest ? substr((string) $date->latest, 0, 10) : null);
            $element('skos:prefLabel', $date->display(), $date->expressed_language);
            $x->endElement();
        }
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    /** Heratio's deriveEdmType: IMAGE, SOUND, VIDEO or 3D by the files' types, else TEXT. */
    public static function type($files): string
    {
        foreach ($files as $file) {
            $mime = (string) $file->mime_type;
            foreach (['image/' => 'IMAGE', 'audio/' => 'SOUND', 'video/' => 'VIDEO', 'model/' => '3D'] as $prefix => $type) {
                if (str_starts_with($mime, $prefix)) {
                    return $type;
                }
            }
        }

        return 'TEXT';
    }
}
