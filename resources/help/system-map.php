<?php

// OpenRiC Workspace - the system map of the Help Center (roadmap phase 20b), after Heratio's ahg-help system-map.php:
// how a record moves through the Workspace, stage by stage, each stage with its parts and their details, three levels
// deep. Each node has a label, a line under it, and the guide that explains it (help), if one does. Edit this list to
// change the map; nothing else needs to change.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    ['label' => 'Intake', 'sub' => 'What arrives, and from whom', 'help' => 'accession-manage-user-guide', 'children' => [
        ['label' => 'Accessions', 'sub' => 'rico:Activity, with its appraisal, valuation, containers and rights', 'help' => 'accession-manage-user-guide', 'children' => [
            ['label' => 'Donor and agreement', 'sub' => 'The donor an authority record; the agreement a rico:Record', 'help' => 'donor-agreement-user-guide'],
            ['label' => 'Checklist and promotion', 'sub' => 'Worked through, then made into a unit of description'],
        ]],
        ['label' => 'Imports', 'sub' => 'CSV, EAD 2002 and 3, EAC-CPF, RiC-O, SKOS', 'help' => 'data-ingest-user-guide'],
        ['label' => 'Watched folders and scanning', 'sub' => 'Files taken in as they arrive', 'help' => 'scan-user-guide'],
        ['label' => 'Storage', 'sub' => 'Buildings, rooms, shelves and containers', 'help' => 'storage-manage-user-guide'],
    ]],
    ['label' => 'Describe', 'sub' => 'Records in Contexts (RiC-CM 1.0, RiC-O 1.1)', 'help' => 'information-object-manage-user-guide', 'children' => [
        ['label' => 'Units of description', 'sub' => 'rico:Record, rico:RecordSet, rico:RecordPart', 'help' => 'information-object-manage-user-guide', 'children' => [
            ['label' => 'Levels, dates and identifiers', 'sub' => 'The level a vocabulary; dates as rico:DateRange'],
            ['label' => 'Translations', 'sub' => 'Each value in its language', 'help' => 'record-translation-user-guide'],
            ['label' => 'Provenance', 'sub' => 'Each step a rico:Activity', 'help' => 'provenance-user-guide'],
        ]],
        ['label' => 'Authority records', 'sub' => 'rico:Person, rico:Family, rico:CorporateBody', 'help' => 'actor-manage-user-guide'],
        ['label' => 'Repositories, places, activities and rules', 'sub' => 'Each an entity of its own', 'help' => 'repository-manage-user-guide'],
        ['label' => 'Relations', 'sub' => 'Typed by RiC-O property, with their dates and evidence', 'help' => 'relations-user-guide'],
        ['label' => 'Vocabularies', 'sub' => 'SKOS concept schemes, never fixed lists', 'help' => 'vocabularies-skos-concept-schemes'],
        ['label' => 'Own fields and duplicates', 'sub' => 'Fields an institution adds; duplicate rules', 'help' => 'custom-fields-user-guide'],
    ]],
    ['label' => 'Digital objects', 'sub' => 'rico:Instantiation and its files', 'help' => 'multi-file-upload', 'children' => [
        ['label' => 'Upload and copies', 'sub' => 'A master, a reference copy and a thumbnail', 'help' => 'multi-file-upload'],
        ['label' => 'Viewers and IIIF', 'sub' => 'Deep zoom, Mirador, PDF, audio, video, 3D', 'help' => 'iiif-integration-user-guide'],
        ['label' => 'Captions', 'sub' => 'WebVTT tracks for audio and video', 'help' => 'caption-tracks-user-guide'],
    ]],
    ['label' => 'Rights and access', 'sub' => 'Who may see and do what', 'help' => 'rights-management-user-guide', 'children' => [
        ['label' => 'Rights', 'sub' => 'PREMIS rights, RightsStatements.org and Creative Commons', 'help' => 'rights-management-user-guide'],
        ['label' => 'Embargoes', 'sub' => 'Until a date, with exceptions', 'help' => 'embargo-user-guide'],
        ['label' => 'Privacy and clearance', 'sub' => 'POPIA, redaction, security clearance', 'help' => 'privacy-user-guide'],
        ['label' => 'Access requests and share links', 'sub' => 'Asked for, decided, and shared for a time', 'help' => 'access-requests-user-guide'],
    ]],
    ['label' => 'Preservation', 'sub' => 'OAIS, PREMIS 3 and OCFL 1.1', 'help' => 'dp-digital-preservation-overview', 'children' => [
        ['label' => 'Fixity and formats', 'sub' => 'Checksums checked; formats identified against PRONOM', 'help' => 'dp-fixity-and-checksums'],
        ['label' => 'The object store', 'sub' => 'Every file in an OCFL object, versioned', 'help' => 'ocfl-storage'],
        ['label' => 'Packages', 'sub' => 'SIP, AIP and DIP as BagIt bags with METS and PREMIS', 'help' => 'dp-oais-packages'],
        ['label' => 'Archivematica', 'sub' => 'Transfers sent, AIPs and DIPs taken in', 'help' => 'archivematica-integration'],
    ]],
    ['label' => 'Review and publish', 'sub' => 'Nothing reaches the public until it is published', 'help' => 'workflow-user-guide', 'children' => [
        ['label' => 'Workflows', 'sub' => 'Tasks, steps and service levels', 'help' => 'workflow-user-guide'],
        ['label' => 'Records management', 'sub' => 'The file plan, retention and disposal', 'help' => 'records-manage-user-guide'],
        ['label' => 'Audit', 'sub' => 'Every change, signed in a chain', 'help' => 'audit-trail-user-guide'],
    ]],
    ['label' => 'Discover', 'sub' => 'The public catalogue and site', 'help' => 'discovery-user-guide', 'children' => [
        ['label' => 'Search', 'sub' => 'Full text, facets and saved searches', 'help' => 'search-user-guide'],
        ['label' => 'The public site', 'sub' => 'Static pages, articles and landing pages', 'help' => 'landing-page-user-guide'],
        ['label' => 'The graph', 'sub' => 'SPARQL and Sparnatural over the published RiC-O', 'help' => 'graph-explorer-user-guide'],
    ]],
    ['label' => 'Share', 'sub' => 'Open data, exchange and federation', 'help' => 'federation-user-guide', 'children' => [
        ['label' => 'Exports', 'sub' => 'RiC-O, EAD, EAC-CPF, Dublin Core, METS, PREMIS', 'help' => 'export-data-user-guide'],
        ['label' => 'OAI-PMH and ResourceSync', 'sub' => 'For harvesters', 'help' => 'oai-user-guide'],
        ['label' => 'Federation', 'sub' => 'Signed search between catalogues, the union catalogue', 'help' => 'federation-user-guide'],
        ['label' => 'Europeana and loans', 'sub' => 'EDM bundles; loans between members', 'help' => 'europeana-publish'],
    ]],
    ['label' => 'Research', 'sub' => 'The reading room and researchers', 'help' => 'researcher-user-guide', 'children' => [
        ['label' => 'Researchers and bookings', 'sub' => 'Registered, booked, retrieved', 'help' => 'reading-room-booking'],
        ['label' => 'Research projects', 'sub' => 'Collections, notes, claims and outputs', 'help' => 'research-user-guide'],
        ['label' => 'Reproductions and the shop', 'sub' => 'Copies asked for and paid for', 'help' => 'reproduction-requests'],
    ]],
];
