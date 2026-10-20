<?php

// OpenRiC Workspace - export a unit of description and every unit inside it as EAD 2002 or EAD3.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace from the EAD 2002 and EAD3 tag libraries and the
// ISAD(G) crosswalk they carry, not from Heratio's code. Every element gives
// its ISAD(G) number as encodinganalog, as AtoM does, so an import can tell
// the elements apart. Creators, access points and related units come from the
// relations of the store, each with its persistent IRI. What may be said is
// decided by ExportView. Both are valid against the schemas in
// resources/schemas (tests/Feature/ExportTest).

namespace App\Exchange;

use App\Description\Elements;
use App\Description\RecordService;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\RicDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use XMLWriter;

class Ead
{
    public const EAD2002 = 'urn:isbn:1-931666-22-9';

    public const EAD3 = 'http://ead3.archivists.org/schema/';

    /** ISAD(G) narrative elements (notation) and the EAD element each is written as, the same in both versions. */
    public const NARRATIVE = [
        'creator-history' => 'bioghist', 'archival-history' => 'custodhist', 'acquisition' => 'acqinfo', 'scope-and-content' => 'scopecontent',
        'appraisal' => 'appraisal', 'accruals' => 'accruals', 'arrangement' => 'arrangement', 'conditions-governing-access' => 'accessrestrict',
        'conditions-governing-reproduction' => 'userestrict', 'physical-characteristics' => 'phystech', 'finding-aids-note' => 'otherfindaid',
        'location-of-originals' => 'originalsloc', 'location-of-copies' => 'altformavail', 'related-units' => 'relatedmaterial',
        'publication-note' => 'bibliography', 'note' => 'odd', 'archivists-note' => 'processinfo',
        'rules-or-conventions' => 'processinfo', 'dates-of-descriptions' => 'processinfo',
    ];

    /** The levels EAD names; any other is otherlevel with its label. */
    public const LEVELS = ['collection', 'fonds', 'class', 'recordgrp', 'series', 'subfonds', 'subgrp', 'subseries', 'file', 'item'];

    /** Level notations EAD spells otherwise. */
    public const LEVEL_NAMES = ['record-group' => 'recordgrp'];

    /** The EAD name element for each agent type; a repository is a corporate body. */
    private const NAME_ELEMENTS = ['person' => 'persname', 'family' => 'famname'];

    private bool $three = false;

    private ExportView $view;

    /** @var array<string, string> ISAD(G) notation => number */
    private array $numbers = [];

    public function __construct(private RecordService $records) {}

    public function export(RecordResource $record, ExportView $view, bool $ead3 = false): string
    {
        $this->three = $ead3;
        $this->view = $view;
        $this->numbers = Elements::of(Elements::ISAD_G)->mapWithKeys(fn (Concept $e) => [$e->notation => Elements::number($e)])->filter()->all();
        $record->loadMissing([...RecordService::DESCRIBED, 'repository.names.nameType', 'repository.names.state', 'repository.identifiers.identifierType']);
        $byParent = $this->records->hierarchy($record, $view->user);
        $language = $view->languageOf($record);

        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('ead');
        $x->writeAttribute('xmlns', $ead3 ? self::EAD3 : self::EAD2002);
        if (! $ead3) {
            $x->writeAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        }
        $x->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->writeAttribute('xsi:schemaLocation', $ead3 ? self::EAD3.' https://www.loc.gov/ead/ead3.xsd' : self::EAD2002.' https://www.loc.gov/ead/ead.xsd');
        $ead3 ? $this->control($x, $record, $language) : $this->header($x, $record, $language);

        $x->startElement('archdesc');
        $this->level($x, $record);
        $x->writeAttribute('relatedencoding', 'ISAD(G)v2');
        $this->describe($x, $record, $language);
        $children = $byParent[$record->id] ?? collect();
        if ($children->isNotEmpty()) {
            $x->startElement('dsc');
            foreach ($children as $child) {
                $this->component($x, $child, $byParent, $language);
            }
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    /** EAD 2002: the header names the finding aid, who publishes it and its language. */
    private function header(XMLWriter $x, RecordResource $record, string $language): void
    {
        $x->startElement('eadheader');
        $x->writeAttribute('langencoding', 'rfc5646');
        $x->writeAttribute('dateencoding', 'iso8601');
        $x->writeAttribute('relatedencoding', 'ISAD(G)v2');
        $x->startElement('eadid');
        [$country, $agency] = $this->agency($record);
        if ($country) {
            $x->writeAttribute('countrycode', $country);
        }
        if ($agency) {
            $x->writeAttribute('mainagencycode', $agency);
        }
        $x->writeAttribute('identifier', $record->iri);
        $x->writeAttribute('url', $record->iri);
        $x->text($record->referenceCode() ?? $record->description_identifier ?? $record->id);
        $x->endElement();
        $x->startElement('filedesc');
        $x->startElement('titlestmt');
        $x->writeElement('titleproper', $this->view->value($record, 'title') ?? '');
        $x->endElement();
        $x->startElement('publicationstmt');
        $x->writeElement('publisher', $this->publisher($record));
        $x->endElement();
        $x->endElement();
        $x->startElement('profiledesc');
        $x->startElement('creation');
        $x->setIndent(false); // mixed content: no whitespace inside it
        $x->text('Exported from OpenRiC Workspace on ');
        $x->startElement('date');
        $x->writeAttribute('normal', now()->format('Y-m-d'));
        $x->text(now()->format('Y-m-d'));
        $x->endElement();
        $x->endElement();
        $x->setIndent(true);
        $x->startElement('langusage');
        $this->language($x, $language);
        $x->endElement();
        if ($rules = $this->view->value($record, 'rules-or-conventions')) {
            $x->startElement('descrules');
            $x->writeAttribute('encodinganalog', '3.7.2');
            $x->text($rules);
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
    }

    /** EAD3: the control section, with the record's identifiers and the export as its maintenance event. */
    private function control(XMLWriter $x, RecordResource $record, string $language): void
    {
        $x->startElement('control');
        // Languages are BCP 47 tags, which EAD3 can only call another encoding.
        $x->writeAttribute('langencoding', 'otherlangencoding');
        $x->writeAttribute('dateencoding', 'iso8601');
        $x->writeAttribute('relatedencoding', 'ISAD(G)v2');
        $x->startElement('recordid');
        $x->writeAttribute('instanceurl', $record->iri);
        $x->text($record->referenceCode() ?? $record->description_identifier ?? $record->id);
        $x->endElement();
        $x->startElement('otherrecordid');
        $x->writeAttribute('localtype', 'persistent-iri');
        $x->text($record->iri);
        $x->endElement();
        $x->startElement('filedesc');
        $x->startElement('titlestmt');
        $x->writeElement('titleproper', $this->view->value($record, 'title') ?? '');
        $x->endElement();
        $x->startElement('publicationstmt');
        $x->writeElement('publisher', $this->publisher($record));
        $x->endElement();
        $x->endElement();
        $x->startElement('maintenancestatus');
        $x->writeAttribute('value', 'derived');
        $x->endElement();
        $x->startElement('maintenanceagency');
        [$country, $agency] = $this->agency($record);
        if ($country) {
            $x->writeAttribute('countrycode', $country);
        }
        if ($agency) {
            $x->writeElement('agencycode', $agency);
        }
        $x->writeElement('agencyname', $this->publisher($record));
        $x->endElement();
        if ($rules = $this->view->value($record, 'rules-or-conventions')) {
            $x->startElement('conventiondeclaration');
            $x->writeAttribute('encodinganalog', '3.7.2');
            $x->writeElement('citation', $rules);
            $x->endElement();
        }
        $x->startElement('maintenancehistory');
        $x->startElement('maintenanceevent');
        $x->startElement('eventtype');
        $x->writeAttribute('value', 'derived');
        $x->endElement();
        $x->startElement('eventdatetime');
        $x->writeAttribute('standarddatetime', now()->toAtomString());
        $x->text(now()->format('Y-m-d H:i'));
        $x->endElement();
        $x->startElement('agenttype');
        $x->writeAttribute('value', 'machine');
        $x->endElement();
        $x->writeElement('agent', 'OpenRiC Workspace');
        $x->writeElement('eventdescription', 'Exported in '.$this->languageName($language).'.');
        $x->endElement();
        $x->endElement();
        $x->endElement();
    }

    private function component(XMLWriter $x, RecordResource $unit, Collection $byParent, string $language): void
    {
        $x->startElement('c');
        $x->writeAttribute('id', 'u'.$unit->id);
        $this->level($x, $unit);
        $this->describe($x, $unit, $language);
        foreach ($byParent[$unit->id] ?? [] as $child) {
            $this->component($x, $child, $byParent, $language);
        }
        $x->endElement();
    }

    private function level(XMLWriter $x, RecordResource $unit): void
    {
        $level = $unit->level;
        $name = self::LEVEL_NAMES[$level->notation] ?? $level->notation;
        if (in_array($name, self::LEVELS, true)) {
            $x->writeAttribute('level', $name);
        } else {
            $x->writeAttribute('level', 'otherlevel');
            $x->writeAttribute('otherlevel', (string) $level->label(['en', 'und']));
        }
    }

    /** The did, then the narrative elements, related units, access points. */
    private function describe(XMLWriter $x, RecordResource $unit, string $language): void
    {
        $x->startElement('did');
        if ($unit->identifier) {
            $x->startElement('unitid');
            $x->writeAttribute('encodinganalog', '3.1.1');
            [$country, $agency] = $unit->parent_id === null ? $this->agency($unit) : [null, null];
            if ($country) {
                $x->writeAttribute('countrycode', $country);
            }
            if ($agency) {
                $x->writeAttribute('repositorycode', $agency);
            }
            $x->text($unit->identifier);
            $x->endElement();
        }
        foreach ($unit->identifiers->reject(fn ($i) => $i->identifierType->notation === 'local') as $identifier) {
            $x->startElement('unitid');
            $x->writeAttribute($this->three ? 'localtype' : 'type', $identifier->identifierType->notation);
            $x->writeAttribute('label', (string) $identifier->identifierType->label(['en', 'und']));
            $x->text($identifier->value);
            $x->endElement();
        }
        $x->startElement('unitid');
        $x->writeAttribute($this->three ? 'localtype' : 'type', 'persistent-iri');
        $x->writeAttribute('label', 'Persistent IRI');
        $x->text($unit->iri);
        $x->endElement();
        if ($title = $this->view->valueWithLanguage($unit, 'title')) {
            $x->startElement('unittitle');
            $x->writeAttribute('encodinganalog', '3.1.2');
            $this->lang($x, $title[1], $language);
            $x->text($title[0]);
            $x->endElement();
        }
        $written = $this->view->value($unit, 'dates-expressed');
        foreach ($unit->dates as $i => $date) {
            $this->unitdate($x, $date, $date->expressed ?? ($i === 0 ? $written : null));
        }
        if ($unit->dates->isEmpty() && $written) {
            $x->startElement('unitdate');
            $x->writeAttribute('encodinganalog', '3.1.3');
            $x->text($written);
            $x->endElement();
        }
        if ($extent = $this->view->valueWithLanguage($unit, 'extent')) {
            $x->startElement('physdesc');
            $x->writeAttribute('encodinganalog', '3.1.5');
            $this->lang($x, $extent[1], $language);
            $x->text($extent[0]);
            $x->endElement();
        }
        $locations = [];
        foreach ($this->view->storage($unit) as $container) {
            $x->startElement('container');
            if ($container['type']) {
                $x->writeAttribute($this->three ? 'localtype' : 'type', $container['type']);
            }
            $x->text($container['name']);
            $x->endElement();
            $locations[] = $container['location'];
        }
        foreach (array_unique(array_filter($locations)) as $location) {
            $x->writeElement('physloc', $location);
        }
        if ($material = $this->view->valueWithLanguage($unit, 'languages-of-material')) {
            $x->startElement('langmaterial');
            $x->writeAttribute('encodinganalog', '3.4.3');
            if ($this->three) {
                $x->startElement('language');
                $this->lang($x, $material[1], $language);
                $x->text($material[0]);
                $x->endElement();
            } else {
                $x->text($material[0]);
            }
            $x->endElement();
        }
        if ($unit->parent_id === null && $unit->repository && ($holder = $this->view->label($unit->repository))) {
            $x->startElement('repository');
            $this->name($x, 'corpname', $holder, $unit->repository->iri);
            $x->endElement();
        }
        $creators = $this->view->creators($unit);
        if ($creators->isNotEmpty()) {
            $x->startElement('origination');
            $x->writeAttribute('encodinganalog', '3.2.1');
            $x->writeAttribute('label', 'Creator');
            foreach ($creators as $relation) {
                $this->name($x, $this->nameElement($relation->other), $this->view->label($relation->other), $relation->other->iri,
                    $relation->relationType->notation === 'accumulation' ? 'accumulator' : 'creator');
            }
            $x->endElement();
        }
        $x->endElement();

        foreach (self::NARRATIVE as $notation => $element) {
            if ($notation === 'rules-or-conventions' && $unit->parent_id === null) {
                continue; // the finding aid's own rules, in the header or control section
            }
            $value = $this->view->valueWithLanguage($unit, $notation);
            $related = $notation === 'related-units' ? $this->view->relations($unit, 'associated-record') : collect();
            if ($value === null && $related->isEmpty()) {
                continue;
            }
            $x->startElement($element);
            $x->writeAttribute('encodinganalog', $this->numbers[$notation] ?? '');
            if ($value) {
                $this->lang($x, $value[1], $language);
                if ($element === 'processinfo') {
                    $x->writeElement('head', (string) Elements::of(Elements::ISAD_G)->firstWhere('notation', $notation)?->label(['en', 'und']));
                }
                foreach (preg_split('/\R{2,}/', trim($value[0])) as $paragraph) {
                    $x->writeElement('p', $paragraph);
                }
            }
            foreach ($related as $relation) {
                $x->startElement('p');
                $x->setIndent(false);
                $this->ref($x, $relation->other->iri, $this->view->label($relation->other) ?? $relation->other->iri);
                $x->endElement();
                $x->setIndent(true);
            }
            $x->endElement();
        }

        $points = $this->view->accessPoints($unit);
        if ($points['subjects']->isNotEmpty() || $points['places']->isNotEmpty() || $points['names']->isNotEmpty() || $points['genres']->isNotEmpty()) {
            $x->startElement('controlaccess');
            foreach ($points['subjects'] as $relation) {
                $this->name($x, 'subject', $this->view->label($relation->other), $relation->other->iri);
            }
            foreach ($points['places'] as $relation) {
                $this->name($x, 'geogname', $this->view->label($relation->other), $relation->other->iri);
            }
            foreach ($points['names'] as $relation) {
                $this->name($x, $this->nameElement($relation->other), $this->view->label($relation->other), $relation->other->iri);
            }
            foreach ($points['genres'] as $relation) {
                $this->name($x, 'genreform', $this->view->label($relation->other), $relation->other->iri);
            }
            $x->endElement();
        }
    }

    private function unitdate(XMLWriter $x, RicDate $date, ?string $written): void
    {
        $x->startElement('unitdate');
        $x->writeAttribute('encodinganalog', '3.1.3');
        if ($normal = ExportView::isoInterval($date)) {
            $x->writeAttribute('normal', $normal);
        }
        $x->writeAttribute('datechar', $date->property->notation);
        if ($date->qualifier) {
            $x->writeAttribute('certainty', (string) $date->qualifier->notation);
        }
        $x->text($written ?? $date->display());
        $x->endElement();
    }

    /** A named entity: EAD 2002 gives the name as text and the IRI as authfilenumber, EAD3 the name as a part and the IRI as identifier. */
    private function name(XMLWriter $x, string $element, ?string $label, string $iri, ?string $role = null): void
    {
        if ($label === null) {
            return;
        }
        $x->startElement($element);
        $x->writeAttribute($this->three ? 'identifier' : 'authfilenumber', $iri);
        if ($role) {
            $x->writeAttribute($this->three ? 'relator' : 'role', $role);
        }
        $this->three ? $x->writeElement('part', $label) : $x->text($label);
        $x->endElement();
    }

    private function ref(XMLWriter $x, string $iri, string $label): void
    {
        if ($this->three) {
            $x->startElement('ref');
            $x->writeAttribute('href', $iri);
        } else {
            $x->startElement('extref');
            $x->writeAttribute('xlink:type', 'simple');
            $x->writeAttribute('xlink:href', $iri);
        }
        $x->text($label);
        $x->endElement();
    }

    private function nameElement(Model $agent): string
    {
        return $agent instanceof Agent ? (self::NAME_ELEMENTS[$agent->typeNotation()] ?? 'corpname') : 'corpname';
    }

    /** EAD3 marks a value in another language than the finding aid's; EAD 2002 has no attribute for it. */
    private function lang(XMLWriter $x, string $valueLanguage, string $language): void
    {
        if ($this->three && $valueLanguage !== $language) {
            $x->writeAttribute('lang', $valueLanguage);
        }
    }

    private function language(XMLWriter $x, string $language): void
    {
        $x->startElement('language');
        $x->writeAttribute('langcode', $language);
        $x->text($this->languageName($language));
        $x->endElement();
    }

    private function languageName(string $language): string
    {
        $name = \Locale::getDisplayLanguage($language, 'en');

        return $name !== '' && $name !== $language ? $name : $language;
    }

    private function publisher(RecordResource $record): string
    {
        return ($record->repository ? $this->view->label($record->repository) : null) ?? Institution::find($record->institution_id)?->name ?? '';
    }

    /**
     * The country and agency code of the holding repository, from its ISIL (ISO 15511), which is
     * what EAD's agency and repository codes are; a local identifier such as 2025-11-22/131 is not one.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function agency(RecordResource $record): array
    {
        $isil = $record->repository?->identifiers->first(fn ($i) => $i->identifierType->notation === 'isil')?->value;
        // EAD 2002 takes the code as a name token, so an ISIL with a slash in it is left out too.
        if ($isil === null || ! preg_match('/^[A-Za-z0-9:._-]+$/', $isil)) {
            return [null, null];
        }

        return [preg_match('/^([A-Z]{2})-/', $isil, $m) ? $m[1] : null, $isil];
    }
}
