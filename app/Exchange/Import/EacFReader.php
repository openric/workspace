<?php

// OpenRiC Workspace - read activities from EAC-F, the TS-EAS Encoded Archival Context - Functions schema of the EAS
// 2026-07 release (roadmap phase 11a; openric/service #14).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The reverse of App\Exchange\EacF, so an activity the Workspace exported reads back as it was: its names in each
// language and type, its type and classification, identifier, date, description, history and legislation, and its
// relations by the IRIs of the other end. Elements are found by their local names. Every element the import does not
// read is listed in the report.

namespace App\Exchange\Import;

use DOMElement;

class EacFReader
{
    use ReadsXmlRecords;

    /** nameEntry localType (or, without one, its status) and the name element each is read into. */
    private const NAMES = ['authorised' => 'activity-name', 'authorized' => 'activity-name', 'parallel' => 'activity-parallel-name', 'other' => 'activity-other-name', 'alternative' => 'activity-other-name'];

    /** @return list<EntityDraft> */
    public function read(string $content, string $language, Report $report): array
    {
        $drafts = [];
        foreach ($this->documents($content, 'eaf', 'EAC-F', $report) as $name => $root) {
            $drafts[] = $this->record($root, $name, $language);
        }

        return $drafts;
    }

    private function record(DOMElement $root, string $file, string $language): EntityDraft
    {
        $draft = new EntityDraft;
        $draft->location = $file;
        $draft->nameElement = 'activity-name';
        $this->begin($file, $root);
        $language = $this->lang($root) ?? $language;

        if ($control = $this->child($root, 'control')) {
            $this->mark($control);
            $draft->descriptionIdentifier = $this->text($this->child($control, 'recordId'));
            foreach ($this->children($control, 'otherRecordId') as $other) {
                $this->mark($other);
                if ($other->getAttribute('localType') === 'persistentIri') {
                    $draft->iri = trim($other->textContent);
                }
            }
            // The maintenance agency and history describe the file: the audit log keeps the Workspace's own.
            foreach (['maintenanceAgency', 'maintenanceHistory', 'conventionDeclaration', 'languageDeclaration', 'localTypeDeclaration', 'rightsDeclaration'] as $kept) {
                foreach ($this->children($control, $kept) as $element) {
                    $this->markAll($element);
                }
            }
            foreach ($this->children($control, 'sources') as $sources) {
                $this->markAll($sources);
                $draft->value($language, 'activity-sources', implode("\n\n", array_map(fn ($s) => trim($s->textContent), $this->children($sources, 'source'))));
            }
        }

        $description = $this->child($root, 'functionsDescription');
        if ($description) {
            $this->mark($description);
            if ($identity = $this->child($description, 'identity')) {
                $this->mark($identity);
                foreach ($this->children($identity, 'nameEntry') as $entry) {
                    $this->markAll($entry);
                    $element = self::NAMES[$entry->getAttribute('localType')] ?? self::NAMES[$entry->getAttribute('status')] ?? 'activity-other-name';
                    $name = implode(', ', array_filter(array_map(fn ($p) => trim($p->textContent), $this->children($entry, 'part'))));
                    $nameLanguage = $this->lang($entry) ?? $language;
                    $existing = $draft->values[$nameLanguage][$element] ?? null;
                    $draft->value($nameLanguage, $element, $existing === null ? $name : $existing."\n".$name);
                }
                foreach ($this->children($identity, 'classifications') as $classifications) {
                    $this->mark($classifications);
                    foreach ($this->children($classifications, 'classification') as $classification) {
                        $this->mark($classification);
                        if ($classification->getAttribute('valueURI') !== '' && $draft->typeIri === null && $classification->getAttribute('localType') !== 'classification') {
                            $draft->typeIri = $classification->getAttribute('valueURI');
                        } else {
                            $draft->value($language, 'activity-classification', trim($classification->textContent));
                        }
                    }
                }
                foreach ($this->children($identity, 'identityId') as $id) {
                    $this->mark($id);
                    ($id->getAttribute('localType') ?: 'local') === 'local' ? $draft->identifier = trim($id->textContent)
                        : $draft->identifiers[] = ['type' => $id->getAttribute('localType'), 'value' => trim($id->textContent)];
                }
            }
            if ($body = $this->child($description, 'description')) {
                $this->mark($body);
                if ($dates = $this->child($body, 'functionDates')) {
                    $this->markAll($dates);
                    $draft->dates[] = $this->dates($dates);
                }
                foreach (['functionDescription' => 'activity-description', 'functionHistory' => 'activity-history', 'legislations' => 'activity-legislation'] as $element => $notation) {
                    foreach ($this->children($body, $element) as $narrative) {
                        $this->markAll($narrative);
                        $draft->value($language, $notation, $this->paragraphs($narrative));
                    }
                }
                foreach ($this->children($body, 'functionAgents') as $agents) {
                    $this->markAll($agents);
                    foreach ($this->children($agents, 'functionAgent') as $agent) {
                        // An activity is performed by its agents: the activity is the source.
                        $draft->relations[] = ['type' => 'performance', 'iri' => $agent->getAttribute('valueURI') ?: null, 'label' => trim($agent->textContent), 'end' => 'source',
                            'from' => null, 'to' => null, 'description' => null];
                    }
                }
            }
            if ($relations = $this->child($description, 'relations')) {
                $this->mark($relations);
                foreach ($this->children($relations, 'relation') as $relation) {
                    $this->markAll($relation);
                    $draft->relations[] = $this->relation($relation, 'label');
                }
            }
        }
        // Its key in the source: its persistent IRI, else the record's own identifier.
        $draft->key = $draft->iri ?? $draft->descriptionIdentifier ?? $draft->location;
        $this->end();

        return $draft;
    }

    /** functionDates: a date or a range, as EDTF, with the date as written. */
    private function dates(DOMElement $dates): array
    {
        if ($range = $this->child($dates, 'dateRange')) {
            [$from, $to] = [$this->child($range, 'fromDate'), $this->child($range, 'toDate')];
            $normalized = ($from ? ($this->standard($from) ?? '..') : '..').'/'.($to ? ($this->standard($to) ?? '..') : '..');
            $written = $from && trim($from->textContent) !== $this->standard($from) ? trim($from->textContent) : null;

            return ['property' => 'associated', 'normalized' => $normalized === '../..' ? null : $normalized, 'expressed' => $written];
        }
        $date = $this->child($dates, 'date');

        return ['property' => 'associated', 'normalized' => $date ? $this->standard($date) : null,
            'expressed' => $date && trim($date->textContent) !== $this->standard($date) ? trim($date->textContent) : null];
    }
}
