<?php

// OpenRiC Workspace - read authority records (ISAAR(CPF)) and repositories (ISDIAH) from CSV in AtoM's columns (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The columns are those of AtoM's authority record and repository import
// templates, which AtoM's csv:authority-export and csv:repository-export
// write. A row whose legacyId was read already is a translation: its values
// are written in its culture. Every column the import does not read is
// listed in the report, once, where it holds something.

namespace App\Exchange\Import;

use App\Description\Bcp47;

class AtomEntityReader
{
    /** ISAAR(CPF) element for each AtoM authority record column. */
    public const AUTHORITY = [
        'authorizedFormOfName' => 'agent-authorised-name', 'parallelFormsOfName' => 'agent-parallel-name',
        'standardizedFormsOfName' => 'agent-standardised-name', 'otherFormsOfName' => 'agent-other-name',
        'datesOfExistence' => 'agent-dates-of-existence', 'history' => 'agent-history', 'places' => 'agent-places',
        'legalStatus' => 'agent-legal-status', 'functions' => 'agent-functions', 'mandates' => 'agent-mandates',
        'internalStructures' => 'agent-internal-structure', 'generalContext' => 'agent-general-context',
        'institutionIdentifier' => 'agent-institution-identifiers', 'rules' => 'agent-rules-conventions',
        'revisionHistory' => 'agent-description-dates', 'sources' => 'agent-sources', 'maintenanceNotes' => 'agent-maintenance-notes',
    ];

    /** ISDIAH element for each AtoM repository column. */
    public const REPOSITORY = [
        'authorizedFormOfName' => 'authorised-name', 'parallelFormsOfName' => 'parallel-name', 'otherFormsOfName' => 'other-name',
        'history' => 'history', 'geoculturalContext' => 'geocultural-context', 'mandates' => 'mandates',
        'internalStructures' => 'administrative-structure', 'collectingPolicies' => 'records-policies', 'buildings' => 'buildings',
        'holdings' => 'holdings', 'findingAids' => 'finding-aids', 'openingTimes' => 'opening-times', 'accessConditions' => 'access-conditions',
        'disabledAccess' => 'accessibility', 'researchServices' => 'research-services', 'reproductionServices' => 'reproduction-services',
        'publicFacilities' => 'public-areas', 'descriptionRules' => 'rules-conventions', 'descriptionRevisionHistory' => 'description-dates',
        'descriptionSources' => 'description-sources', 'maintenanceNote' => 'maintenance-notes',
    ];

    /** The contact point field for each AtoM repository contact column (ISDIAH 5.2). */
    private const CONTACT = [
        'contactPerson' => 'contact_person', 'streetAddress' => 'street_address', 'city' => 'city', 'region' => 'region',
        'postalCode' => 'postal_code', 'country' => 'country_code', 'telephone' => 'telephone', 'fax' => 'fax', 'email' => 'email', 'website' => 'website',
    ];

    /** Columns read apart from the element maps, by kind. */
    private const READ = [
        'authority' => ['legacyId', 'culture', 'typeOfEntity', 'corporateBodyIdentifiers', 'descriptionIdentifier', 'status', 'levelOfDetail',
            'actorOccupations', 'actorOccupationNotes', 'placeAccessPoints'],
        'repository' => ['legacyId', 'culture', 'identifier', 'descriptionIdentifier', 'descriptionStatus', 'levelOfDetail',
            'contactPerson', 'streetAddress', 'city', 'region', 'postalCode', 'country', 'telephone', 'fax', 'email', 'website'],
    ];

    /** Columns not read, and why. */
    private const NOT_READ = [
        'subjectAccessPoints' => 'an authority record has no subject access points in RiC-O or the Workspace: a subject is what a record is about; not read',
        'digitalObjectURI' => 'a file is kept for a unit of description, not for an authority record; not read',
        'digitalObjectPath' => 'a file is kept for a unit of description, not for an authority record; not read',
        'digitalObjectChecksum' => '',
        'language' => 'language and script codes of the description are not kept yet; not read',
        'script' => 'language and script codes of the description are not kept yet; not read',
        'institutionIdentifier' => 'ISDIAH 5.6.2, the institution responsible for the description, is the Workspace institution; not read',
        'types' => 'repository types (ISDIAH 5.1.5) are not kept yet; not read',
        'geographicSubregions' => 'AtoM\'s geographic subregions are not kept; not read',
        'thematicAreas' => 'AtoM\'s thematic areas are not kept; not read',
        'uploadLimit' => 'an AtoM setting, not a description; not read',
    ];

    /** @return list<EntityDraft> in the order of the file */
    public function read(string $kind, string $content, string $language, Report $report): array
    {
        $elements = $kind === 'authority' ? self::AUTHORITY : self::REPOSITORY;
        $report->once('publication', 'imported values are drafts until someone publishes them', 'note');

        /** @var array<string, EntityDraft> $drafts */
        $drafts = [];
        foreach (CsvReader::rows($content, ['authorizedFormOfName'], $kind === 'authority' ? 'authority records' : 'repositories', $report) as [$line, $cells]) {
            foreach ($cells as $column => $cell) {
                if ($cell === '' || isset($elements[$column]) || in_array($column, self::READ[$kind], true)) {
                    continue;
                }
                if (isset(self::NOT_READ[$column])) {
                    self::NOT_READ[$column] === '' || $report->once($column, self::NOT_READ[$column]);
                } else {
                    $report->once($column, 'not a column of AtoM\'s '.($kind === 'authority' ? 'authority record' : 'repository').' template; not read');
                }
            }
            $location = "row $line";
            $rowLanguage = ($cells['culture'] ?? '') ?: $language;
            if (! Bcp47::valid($rowLanguage)) {
                $report->issue($location, 'culture', $rowLanguage, "not a BCP 47 language tag; read as $language", 'note');
                $rowLanguage = $language;
            }
            $key = ($cells['legacyId'] ?? '') ?: null;
            $draft = $key !== null && isset($drafts[$key]) ? $drafts[$key] : null;
            if ($draft === null) {
                $draft = new EntityDraft;
                $draft->location = $location;
                $draft->key = $key ?? $location;
                $draft->nameElement = $elements['authorizedFormOfName'];
                if ($key === null) {
                    $report->issue($location, 'legacyId', null, 'no legacyId: importing the file again makes this entry again', 'note');
                }
                $draft->type = ($cells['typeOfEntity'] ?? '') ?: null;
                $draft->identifier = ($cells[$kind === 'authority' ? 'corporateBodyIdentifiers' : 'identifier'] ?? '') ?: null;
                $draft->descriptionIdentifier = ($cells['descriptionIdentifier'] ?? '') ?: null;
                $draft->status = ($cells[$kind === 'authority' ? 'status' : 'descriptionStatus'] ?? '') ?: null;
                $draft->detail = ($cells['levelOfDetail'] ?? '') ?: null;
                foreach (self::CONTACT as $column => $field) {
                    if (($cells[$column] ?? '') !== '' && $kind === 'repository') {
                        $draft->contact[$field] = $cells[$column];
                    }
                }
                $notes = $this->split($cells['actorOccupationNotes'] ?? '', false);
                foreach ($this->split($cells['actorOccupations'] ?? '', false) as $i => $occupation) {
                    if ($occupation !== '') {
                        $draft->occupations[] = ['name' => $occupation, 'note' => ($notes[$i] ?? '') ?: null];
                    }
                }
                $draft->places = $this->split($cells['placeAccessPoints'] ?? '');
                $drafts[$draft->key] = $draft;
            }
            foreach ($elements as $column => $notation) {
                // Names are one per line, as the description form takes them; AtoM separates them with |.
                $text = str_ends_with($notation, '-name') && ! str_contains($notation, 'authorised') ? implode("\n", $this->split($cells[$column] ?? '')) : ($cells[$column] ?? '');
                $draft->value($rowLanguage, $notation, $text);
            }
        }

        return array_values($drafts);
    }

    /** @return list<string> */
    private function split(string $value, bool $dropEmpty = true): array
    {
        if (trim($value) === '') {
            return [];
        }
        $parts = array_map(fn ($p) => trim($p) === 'NULL' ? '' : trim($p), explode('|', $value));

        return $dropEmpty ? array_values(array_filter($parts, fn ($p) => $p !== '')) : $parts;
    }
}
