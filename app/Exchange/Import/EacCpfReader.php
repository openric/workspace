<?php

// OpenRiC Workspace - read authority records from EAC-CPF (roadmap phase 11a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace from the EAC-CPF 2010 tag library, the reverse of App\Description\EacCpf, so an authority
// record the Workspace exported reads back as it was: its names in each language and type, identifiers, dates of
// existence, texts, places, occupations, and its relations by the IRIs of the other end and of the relation type.
// Elements are found by their local names, so a file with or without the EAC-CPF namespace reads the same. Every
// element the import does not read is listed in the report, so nothing is lost silently.

namespace App\Exchange\Import;

use DOMElement;

class EacCpfReader
{
    use ReadsXmlRecords;

    /** EAC-CPF entity types and the agent type (notation) each is. */
    private const TYPES = ['person' => 'person', 'family' => 'family', 'corporateBody' => 'corporate-body'];

    /** nameEntry localType and the name element each is read into. */
    private const NAMES = ['authorised' => 'agent-authorised-name', 'parallel' => 'agent-parallel-name', 'standardised' => 'agent-standardised-name', 'other' => 'agent-other-name'];

    /** Narrative elements and the ISAAR(CPF) element each is read into. */
    private const NARRATIVE = ['biogHist' => 'agent-history', 'structureOrGenealogy' => 'agent-internal-structure', 'generalContext' => 'agent-general-context', 'mandates' => 'agent-mandates', 'functions' => 'agent-functions'];

    /** @return list<EntityDraft> */
    public function read(string $content, string $language, Report $report): array
    {
        $drafts = [];
        foreach ($this->documents($content, 'eac-cpf', 'EAC-CPF', $report) as $name => $root) {
            $drafts[] = $this->record($root, $name, $language);
        }

        return $drafts;
    }

    private function record(DOMElement $root, string $file, string $language): EntityDraft
    {
        $draft = new EntityDraft;
        $draft->location = $file;
        $draft->nameElement = 'agent-authorised-name';
        $this->begin($draft->location, $root);
        $language = $this->lang($root) ?? $language;

        $control = $this->child($root, 'control');
        if ($control) {
            $this->mark($control);
            $draft->descriptionIdentifier = $this->text($this->child($control, 'recordId'));
            foreach ($this->children($control, 'otherRecordId') as $other) {
                $this->mark($other);
                if ($other->getAttribute('localType') === 'persistentIri') {
                    $draft->iri = trim($other->textContent);
                }
            }
            // The maintenance agency, status and history describe the file: the audit log keeps the Workspace's own.
            foreach (['maintenanceStatus', 'maintenanceAgency', 'maintenanceHistory', 'publicationStatus', 'languageDeclaration', 'conventionDeclaration'] as $kept) {
                foreach ($this->children($control, $kept) as $element) {
                    $this->markAll($element);
                }
            }
            foreach ($this->children($control, 'sources') as $sources) {
                $this->markAll($sources);
                $draft->value($language, 'agent-sources', implode("\n\n", array_map(fn ($s) => trim($s->textContent), iterator_to_array($sources->getElementsByTagNameNS('*', 'source')))));
            }
        }

        $description = $this->child($root, 'cpfDescription');
        if ($description) {
            $this->mark($description);
            $identity = $this->child($description, 'identity');
            if ($identity) {
                $this->mark($identity);
                foreach ($this->children($identity, 'entityId') as $id) {
                    $this->mark($id);
                    $type = $id->getAttribute('localType') ?: 'other';
                    $type === 'local' ? $draft->identifier = trim($id->textContent) : $draft->identifiers[] = ['type' => $type, 'value' => trim($id->textContent)];
                }
                if ($type = $this->child($identity, 'entityType')) {
                    $this->mark($type);
                    $draft->type = self::TYPES[trim($type->textContent)] ?? trim($type->textContent);
                }
                foreach ($this->children($identity, 'nameEntry') as $entry) {
                    $this->mark($entry);
                    $element = self::NAMES[$entry->getAttribute('localType')]
                        ?? ($this->child($entry, 'authorizedForm') ? 'agent-authorised-name' : 'agent-other-name');
                    $parts = array_map(fn ($p) => trim($p->textContent), $this->children($entry, 'part', true));
                    $name = implode(', ', array_filter($parts));
                    $nameLanguage = $this->lang($entry) ?? $language;
                    $existing = $draft->values[$nameLanguage][$element] ?? null;
                    $draft->value($nameLanguage, $element, $existing === null ? $name : $existing."\n".$name);
                    foreach (['authorizedForm', 'alternativeForm', 'preferredForm'] as $form) {
                        foreach ($this->children($entry, $form) as $f) {
                            $this->mark($f);
                            $draft->value($language, 'agent-rules-conventions', $draft->values[$language]['agent-rules-conventions'] ?? trim($f->textContent));
                        }
                    }
                    if ($use = $this->child($entry, 'useDates')) {
                        $this->markAll($use);
                        $this->report->issue($this->location, 'useDates', trim($use->textContent), 'the dates a name was used are kept on its name in the Workspace, not read from EAC-CPF', 'left-out');
                    }
                }
            }
            $body = $this->child($description, 'description');
            if ($body) {
                $this->mark($body);
                if ($exist = $this->child($body, 'existDates')) {
                    $this->mark($exist);
                    $this->existence($draft, $exist, $language);
                }
                foreach ($this->children($body, 'places') as $places) {
                    $this->mark($places);
                    foreach ($this->children($places, 'place') as $place) {
                        $this->markAll($place);
                        $entry = $this->child($place, 'placeEntry');
                        if ($entry && trim($entry->textContent) !== '') {
                            $draft->places[] = trim($entry->textContent);
                        }
                    }
                }
                foreach ($this->children($body, 'occupations') as $occupations) {
                    $this->mark($occupations);
                    foreach ($this->children($occupations, 'occupation') as $occupation) {
                        $this->markAll($occupation);
                        $term = $this->child($occupation, 'term');
                        if ($term) {
                            $draft->occupations[] = ['name' => trim($term->textContent), 'note' => null];
                        }
                    }
                }
                foreach ($this->children($body, 'legalStatuses') as $statuses) {
                    $this->markAll($statuses);
                    $draft->value($language, 'agent-legal-status', implode("\n", array_map(fn ($t) => trim($t->textContent), iterator_to_array($statuses->getElementsByTagNameNS('*', 'term')))));
                }
                foreach (self::NARRATIVE as $element => $notation) {
                    foreach ($this->children($body, $element) as $narrative) {
                        $this->markAll($narrative);
                        $draft->value($language, $notation, $this->paragraphs($narrative));
                    }
                }
            }
            if ($relations = $this->child($description, 'relations')) {
                $this->mark($relations);
                foreach ([...$this->children($relations, 'cpfRelation'), ...$this->children($relations, 'resourceRelation')] as $relation) {
                    $this->markAll($relation);
                    $draft->relations[] = $this->relation($relation, 'relationEntry');
                }
            }
        }
        // Its key in the source: its persistent IRI, else the record's own identifier.
        $draft->key = $draft->iri ?? $draft->descriptionIdentifier ?? $draft->location;
        $this->end();

        return $draft;
    }

    /** existDates: a person's birth and death, anything else's existence, and the dates as written. */
    private function existence(EntityDraft $draft, DOMElement $exist, string $language): void
    {
        $range = $this->child($exist, 'dateRange');
        $single = $this->child($exist, 'date');
        if ($range) {
            $this->markAll($range);
            [$from, $to] = [$this->child($range, 'fromDate'), $this->child($range, 'toDate')];
            // The text is the date as written only where it is not the standard date itself.
            $written = fn ($d) => ($t = trim($d->textContent)) === '' || $t === $this->standard($d) ? null : $t;
            if ($draft->type === 'person') {
                $from && $draft->dates[] = ['property' => 'birth', 'normalized' => $this->standard($from), 'expressed' => $written($from)];
                $to && $draft->dates[] = ['property' => 'death', 'normalized' => $this->standard($to), 'expressed' => $written($to)];
            } else {
                $normalized = implode('/', [$from ? ($this->standard($from) ?? '..') : '..', $to ? ($this->standard($to) ?? '..') : '..']);
                $draft->dates[] = ['property' => 'existence', 'normalized' => $normalized === '../..' ? null : $normalized, 'expressed' => $from ? $written($from) : null];
            }
        } elseif ($single) {
            $this->markAll($single);
            $draft->dates[] = ['property' => 'existence', 'normalized' => $this->standard($single),
                'expressed' => ($t = trim($single->textContent)) === '' || $t === $this->standard($single) ? null : $t];
        }
        if ($note = $this->child($exist, 'descriptiveNote')) {
            $this->markAll($note);
            $draft->value($language, 'agent-dates-of-existence', $this->paragraphs($note));
        }
    }
}
