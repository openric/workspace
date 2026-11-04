<?php

// OpenRiC Workspace - the system breakdown of the Help Center (roadmap phase 20b), after Heratio's ahg-help
// system-breakdown.php: what the Workspace does for each kind of entity it describes, four levels deep (the Workspace,
// the kind of entity, an aspect of it, and what it holds). Each node has a label, a line under it, and the guide that
// explains it (help), if one does. Edit this list to change the tree; nothing else needs to change.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    ['label' => 'OpenRiC Workspace', 'sub' => 'An archival system on Records in Contexts', 'help' => 'core-user-guide', 'children' => [
        ['label' => 'Units of description', 'sub' => 'rico:Record, rico:RecordSet, rico:RecordPart', 'help' => 'information-object-manage-user-guide', 'children' => [
            ['label' => 'Description', 'sub' => 'ISAD(G) areas on RiC terms', 'children' => [
                ['label' => 'Identity', 'sub' => 'Reference code, title, dates, level, extent'],
                ['label' => 'Context', 'sub' => 'Creators, archival history, acquisition'],
                ['label' => 'Content and structure', 'sub' => 'Scope and content, appraisal, accruals, arrangement'],
                ['label' => 'Conditions of access and use', 'sub' => 'Access, reproduction, languages, finding aids'],
            ]],
            ['label' => 'Relations', 'sub' => 'To agents, places, activities, rules and other records', 'help' => 'relations-user-guide', 'children' => [
                ['label' => 'Creation and accumulation', 'sub' => 'rico:Activity, with its dates'],
                ['label' => 'Subjects, places and genres', 'sub' => 'Access points from the vocabularies'],
            ]],
            ['label' => 'Instantiations', 'sub' => 'rico:Instantiation and its files', 'help' => 'multi-file-upload', 'children' => [
                ['label' => 'Files', 'sub' => 'Master, reference copy, thumbnail, captions'],
                ['label' => 'Viewers', 'sub' => 'IIIF, Mirador, PDF, audio, video, 3D', 'help' => 'iiif-integration-user-guide'],
            ]],
            ['label' => 'Rights and access', 'sub' => 'Rights, embargoes, privacy, clearance', 'help' => 'rights-management-user-guide', 'children' => [
                ['label' => 'Rights in force', 'sub' => 'Exported as rights statements'],
                ['label' => 'Requests and share links', 'sub' => 'Asked for and granted for a time', 'help' => 'access-requests-user-guide'],
            ]],
            ['label' => 'Preservation', 'sub' => 'PREMIS events, fixity, OCFL, packages', 'help' => 'dp-digital-preservation-overview', 'children' => [
                ['label' => 'Events', 'sub' => 'Each action on a file a PREMIS event', 'help' => 'dp-preservation-metadata-premis-mets'],
                ['label' => 'Formats', 'sub' => 'PRONOM identification, risk and migration', 'help' => 'dp-format-identification'],
            ]],
            ['label' => 'Publication', 'sub' => 'Draft, reviewed, published, deprecated', 'help' => 'workflow-user-guide', 'children' => [
                ['label' => 'Persistent IRI', 'sub' => 'Kept for ever; a deprecated record answers 410'],
                ['label' => 'Exports', 'sub' => 'RiC-O, EAD, Dublin Core, EDM', 'help' => 'export-data-user-guide'],
            ]],
        ]],
        ['label' => 'Authority records', 'sub' => 'rico:Person, rico:Family, rico:CorporateBody', 'help' => 'actor-manage-user-guide', 'children' => [
            ['label' => 'Description', 'sub' => 'ISAAR(CPF) areas on RiC terms', 'children' => [
                ['label' => 'Names', 'sub' => 'Authorised, parallel, standardised, other, historical, abbreviated'],
                ['label' => 'Dates of existence', 'sub' => 'Birth, death, existence'],
            ]],
            ['label' => 'Relations', 'sub' => 'To records, other agents, places and activities', 'help' => 'relations-user-guide', 'children' => [
                ['label' => 'Family and hierarchy', 'sub' => 'Typed by RiC-O property'],
            ]],
        ]],
        ['label' => 'Repositories', 'sub' => 'The holding institutions, ISDIAH on RiC terms', 'help' => 'repository-manage-user-guide', 'children' => [
            ['label' => 'Contact and access', 'sub' => 'Contact points, opening times, services', 'children' => [
                ['label' => 'Contact points', 'sub' => 'Address, telephone, e-mail, position'],
            ]],
        ]],
        ['label' => 'Accessions', 'sub' => 'What arrives, as rico:Activity', 'help' => 'accession-manage-user-guide', 'children' => [
            ['label' => 'Intake', 'sub' => 'Donor, agreement, appraisal, valuation, containers', 'help' => 'donor-agreement-user-guide', 'children' => [
                ['label' => 'Storage', 'sub' => 'Where each container is kept', 'help' => 'storage-manage-user-guide'],
            ]],
        ]],
        ['label' => 'Vocabularies', 'sub' => 'SKOS concept schemes', 'help' => 'vocabularies-skos-concept-schemes', 'children' => [
            ['label' => 'Terms', 'sub' => 'Each with its RiC-O term or a note that it has none', 'help' => 'term-taxonomy-user-guide', 'children' => [
                ['label' => 'Import and sync', 'sub' => 'SKOS Turtle, and peers\' vocabularies'],
            ]],
        ]],
        ['label' => 'Researchers', 'sub' => 'The reading room and research services', 'help' => 'researcher-user-guide', 'children' => [
            ['label' => 'Visits', 'sub' => 'Registration, bookings, retrievals', 'help' => 'reading-room-booking', 'children' => [
                ['label' => 'Reproductions', 'sub' => 'Copies asked for and supplied', 'help' => 'reproduction-requests'],
            ]],
        ]],
    ]],
];
