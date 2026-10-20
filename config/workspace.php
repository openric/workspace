<?php

// OpenRiC Workspace - application settings.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    // The OpenRiC specification release this build implements (ADR 0003, rule 1;
    // conformance/README.md). Raised only together with the conformance matrix.
    'openric_spec_version' => '0.54.0',

    // Ed25519 key that signs every audit row (ADR 0004). Kept outside git and the
    // database, readable only by the application; back it up with the database.
    'audit_signing_key' => env('WORKSPACE_AUDIT_SIGNING_KEY', storage_path('keys/audit-signing.sk')),

    // The Workspace's own vocabularies, bundled with the code (resources/vocabularies).
    'bundled_vocabularies' => ['account-role.ttl', 'permission.ttl', 'audit-action.ttl', 'description-element.ttl', 'language-value-state.ttl', 'description-status.ttl', 'description-detail.ttl', 'level-of-description.ttl', 'record-kind.ttl', 'date-property.ttl', 'date-qualifier.ttl', 'name-type.ttl', 'identifier-type.ttl', 'relation-type.ttl', 'relation-state.ttl', 'relation-certainty.ttl', 'assertion-origin.ttl', 'assertion-status.ttl', 'concept-scheme-kind.ttl', 'concept-match-type.ttl', 'agent-type.ttl', 'carrier-type.ttl', 'file-use.ttl', 'file-availability.ttl'],

    // The shape of the permission matrix: for each kind of record (record-kind
    // vocabulary), the actions that apply to it (permission vocabulary), as
    // Heratio has one action list per class (ADR 0005).
    'permission_matrix' => [
        'institution' => ['manage-institution', 'manage-accounts', 'manage-roles', 'view-audit-log'],
        'repository' => ['read', 'create', 'update', 'delete', 'publish'],
        'description' => ['read', 'create', 'update', 'delete', 'view-draft', 'publish', 'read-master', 'read-reference', 'read-thumbnail', 'translate'],
        'vocabulary' => ['create', 'update', 'delete', 'publish'],
        'authority' => ['read', 'create', 'update', 'delete', 'publish'],
    ],

    // Which date properties (date-property vocabulary) each kind of entity (App\Relations\EntityKinds,
    // and names and relations) offers on screen, in this order (ADR 0006). The store accepts any of them.
    'date_properties' => [
        'record' => ['creation', 'accumulation', 'associated'],
        'agent' => ['existence', 'associated'],
        'agent:person' => ['existence', 'birth', 'death', 'associated'],
        'repository' => ['existence', 'associated'],
        'name' => ['used-from', 'used-to'],
        'relation' => ['beginning', 'end', 'relation'],
        'place' => ['existence', 'associated'],
        'activity' => ['associated', 'beginning', 'end'],
        'instantiation' => ['creation', 'associated'],
    ],

    // Which kinds of entity each relation type (relation-type vocabulary) connects,
    // as [source kind, target kind] pairs; "agent" stands for every kind of agent
    // (relation_kind_groups), person a person, group a family or corporate body (rico:Group);
    // a kind may name types after a colon, such as agent:family|corporate-body (agent-type
    // vocabulary). symmetric: the order of the two carries no meaning.
    // role: the relation may carry an agent role (OpenRiC role-type vocabulary).
    // forward and inverse: how the relation reads from its source and from its
    // target, on screen. schemes: a concept at either end must be in a vocabulary
    // of one of these kinds (concept-scheme-kind vocabulary). access_point: the
    // relation is shown with the access points of a page, not with its relations.
    'relation_kind_groups' => [
        'agent' => ['repository', 'agent'],
        'person' => ['agent:person'],
        'group' => ['repository', 'agent:family|corporate-body'],
        'position' => ['agent:position'],
    ],
    'relation_types' => [
        'associated-record' => ['pairs' => [['record', 'record']], 'symmetric' => true, 'forward' => 'is associated with', 'inverse' => 'is associated with'],
        'genetic' => ['pairs' => [['record', 'record']], 'symmetric' => true, 'forward' => 'has a genetic link to', 'inverse' => 'has a genetic link to'],
        'sequential' => ['pairs' => [['record', 'record'], ['agent', 'agent']], 'forward' => 'precedes or preceded', 'inverse' => 'follows or followed'],
        'holding' => ['pairs' => [['agent', 'record']], 'role' => true, 'forward' => 'holds or held', 'inverse' => 'is or was held by'],
        'creation' => ['pairs' => [['record', 'agent']], 'role' => true, 'forward' => 'was created by', 'inverse' => 'created'],
        'accumulation' => ['pairs' => [['record', 'agent']], 'role' => true, 'forward' => 'was accumulated by', 'inverse' => 'accumulated'],
        'management' => ['pairs' => [['agent', 'record']], 'role' => true, 'forward' => 'manages or managed', 'inverse' => 'is or was managed by'],
        'ownership' => ['pairs' => [['agent', 'record']], 'role' => true, 'forward' => 'owns or owned', 'inverse' => 'is or was owned by'],
        'agent-hierarchical' => ['pairs' => [['agent', 'agent']], 'forward' => 'has or had as a subordinate', 'inverse' => 'is or was subordinate to'],
        'agent-temporal' => ['pairs' => [['agent', 'agent']], 'forward' => 'was succeeded by', 'inverse' => 'succeeded'],
        'agent-control' => ['pairs' => [['agent', 'agent']], 'forward' => 'controls or controlled', 'inverse' => 'is or was controlled by'],
        'agent-associated' => ['pairs' => [['agent', 'agent']], 'symmetric' => true, 'forward' => 'is or was associated with', 'inverse' => 'is or was associated with'],
        'subject' => ['pairs' => [['record', 'concept'], ['record', 'place'], ['record', 'agent'], ['record', 'activity']], 'schemes' => ['subject'], 'access_point' => true, 'forward' => 'is or was about', 'inverse' => 'is or was the subject of'],
        'place' => ['pairs' => [['place', 'record'], ['place', 'agent'], ['place', 'activity']], 'access_point' => true, 'forward' => 'is associated with', 'inverse' => 'is associated with the place'],
        'place-containment' => ['pairs' => [['place', 'place']], 'forward' => 'contains or contained', 'inverse' => 'lies or lay in'],
        'family' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'belongs to the same family as', 'inverse' => 'belongs to the same family as'],
        'child' => ['pairs' => [['person', 'person']], 'forward' => 'is the parent of', 'inverse' => 'is the child of'],
        'descendance' => ['pairs' => [['person', 'person']], 'forward' => 'is an ancestor of', 'inverse' => 'is a descendant of'],
        'sibling' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'is a sibling of', 'inverse' => 'is a sibling of'],
        'spouse' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'is or was married to', 'inverse' => 'is or was married to'],
        'guardianship' => ['pairs' => [['agent', 'person']], 'forward' => 'is or was the guardian of', 'inverse' => 'is or was under the guardianship of'],
        'adoption' => ['pairs' => [['person', 'person']], 'forward' => 'adopted', 'inverse' => 'is the adopted child of'],
        'fostering' => ['pairs' => [['agent', 'person']], 'forward' => 'fostered', 'inverse' => 'was fostered by'],
        'membership' => ['pairs' => [['group', 'person']], 'role' => true, 'forward' => 'has or had as a member', 'inverse' => 'is or was a member of'],
        'leadership' => ['pairs' => [['person', 'group']], 'forward' => 'leads or led', 'inverse' => 'is or was led by'],
        'position-holding' => ['pairs' => [['person', 'position']], 'forward' => 'occupies or occupied', 'inverse' => 'is or was occupied by'],
        'position-in-group' => ['pairs' => [['position', 'group']], 'forward' => 'exists or existed in', 'inverse' => 'has or had the position'],
        'group-subdivision' => ['pairs' => [['group', 'group']], 'forward' => 'has or had as a subdivision', 'inverse' => 'is or was a subdivision of'],
        'knowing' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'knew', 'inverse' => 'knew'],
        'knowing-of' => ['pairs' => [['person', 'person']], 'forward' => 'knew of', 'inverse' => 'was known of by'],
        'correspondence' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'corresponded with', 'inverse' => 'corresponded with'],
        'teaching' => ['pairs' => [['person', 'person']], 'forward' => 'taught', 'inverse' => 'was taught by'],
        'study-at' => ['pairs' => [['group', 'person']], 'forward' => 'was attended by the student', 'inverse' => 'studied at'],
        'work' => ['pairs' => [['agent', 'agent']], 'symmetric' => true, 'forward' => 'works or worked with', 'inverse' => 'works or worked with'],
        'occupation' => ['pairs' => [['person', 'concept']], 'schemes' => ['occupation-type'], 'access_point' => true, 'forward' => 'pursues or pursued the occupation', 'inverse' => 'is or was the occupation of'],
        'legal-status' => ['pairs' => [['agent', 'concept']], 'schemes' => ['legal-status'], 'access_point' => true, 'forward' => 'has or had the legal status', 'inverse' => 'is or was the legal status of'],
        // Activities (rico:Activity): who performed them, who took part and in what role, what they resulted in, what documents them, and the activities they are part of.
        'performance' => ['pairs' => [['activity', 'agent']], 'role' => true, 'forward' => 'is or was performed by', 'inverse' => 'performs or performed'],
        'participation' => ['pairs' => [['activity', 'agent'], ['activity', 'place'], ['activity', 'record']], 'role' => true, 'forward' => 'has or had as a participant', 'inverse' => 'takes or took part in'],
        'result' => ['pairs' => [['activity', 'record']], 'forward' => 'results or resulted in', 'inverse' => 'results or resulted from'],
        'documentation' => ['pairs' => [['record', 'activity']], 'forward' => 'documents', 'inverse' => 'is documented by'],
        'subevent' => ['pairs' => [['activity', 'activity']], 'forward' => 'has or had as a part', 'inverse' => 'is or was part of'],
    ],

    // The RiC-O 1.1 class the concepts of each kind of local vocabulary (concept-scheme-kind
    // vocabulary) are exported as, beside skos:Concept.
    'scheme_kinds' => [
        'subject' => 'https://www.ica.org/standards/RiC/ontology#Concept',
        'occupation-type' => 'https://www.ica.org/standards/RiC/ontology#OccupationType',
        'place-type' => 'https://www.ica.org/standards/RiC/ontology#PlaceType',
        'documentary-form-type' => 'https://www.ica.org/standards/RiC/ontology#DocumentaryFormType',
        'legal-status' => 'https://www.ica.org/standards/RiC/ontology#LegalStatus',
        'activity-type' => 'https://www.ica.org/standards/RiC/ontology#ActivityType',
    ],

    // Description elements that are names (ADR 0006): what the description form
    // writes there is kept as rico:Name entities of this name type (name-type
    // vocabulary). An authorised name is single, one in each language; the others
    // take one name per line.
    'name_elements' => [
        'title' => 'authorised',
        'authorised-name' => 'authorised',
        'parallel-name' => 'parallel',
        'other-name' => 'other',
        'place-name' => 'authorised',
        'place-parallel-name' => 'parallel',
        'place-other-name' => 'other',
        'agent-authorised-name' => 'authorised',
        'agent-parallel-name' => 'parallel',
        'agent-standardised-name' => 'standardised',
        'agent-other-name' => 'other',
        'activity-name' => 'authorised',
        'activity-parallel-name' => 'parallel',
        'activity-other-name' => 'other',
        'instantiation-title' => 'authorised',
    ],

    // The roles a new institution starts with, by account-role template, with
    // their parent and their permissions on everything (* = every action of
    // every kind), after Heratio's and AtoM's standard groups. Read only when
    // an institution is installed; afterwards roles and permissions are data.
    'default_roles' => [
        'public' => ['parent' => null, 'grants' => ['repository' => ['read'], 'description' => ['read', 'read-reference', 'read-thumbnail'], 'authority' => ['read']]],
        'signed-in' => ['parent' => 'public', 'grants' => []],
        'administrator' => ['parent' => 'signed-in', 'grants' => '*'],
        'editor' => ['parent' => 'signed-in', 'grants' => [
            'repository' => ['read', 'create', 'update', 'publish'],
            'description' => ['read', 'create', 'update', 'delete', 'view-draft', 'publish', 'read-master', 'read-reference', 'read-thumbnail'],
            'vocabulary' => ['create', 'update', 'publish'],
            'authority' => ['read', 'create', 'update', 'publish'],
        ]],
        'contributor' => ['parent' => 'signed-in', 'grants' => [
            'repository' => ['read'],
            'description' => ['read', 'create', 'update', 'view-draft', 'read-reference', 'read-thumbnail'],
            'authority' => ['read', 'create', 'update'],
        ]],
        'translator' => ['parent' => 'signed-in', 'grants' => ['description' => ['read', 'view-draft', 'translate'], 'authority' => ['read']]],
    ],

    // Vocabularies every installation loads (`php artisan workspace:vocabulary:import --configured`).
    // Published OpenRiC vocabularies are used as they are, never copied into local ones
    // (logical model, section 2).
    'vocabularies' => [
        'https://openric.org/vocab/role-type.ttl',
        'https://openric.org/vocab/date-type.ttl',
        'https://openric.org/vocab/activity-type.ttl',
        'https://openric.org/vocab/rule-type.ttl',
    ],

    // Digital files (WS-007). Uploads larger than this are refused; nginx and PHP must allow
    // at least as much. The scanner is a ClamAV client run on every upload (clamdscan, which
    // uses the running daemon); empty, files are kept as not scanned.
    'max_upload_megabytes' => (int) env('WORKSPACE_MAX_UPLOAD_MB', 60),
    'object_store' => env('WORKSPACE_OBJECT_STORE'),
    'virus_scanner' => env('WORKSPACE_VIRUS_SCANNER', 'clamdscan'),

    // A copy of AtoM's uploads folder (the one holding r/), from which an import of
    // archival descriptions takes the file each digitalObjectURI names; empty, none is.
    'import_files' => env('WORKSPACE_IMPORT_FILES'),

    // The OAI-PMH adminEmail when no repository gives a contact email (Export-Only).
    'oai_admin_email' => env('WORKSPACE_OAI_ADMIN_EMAIL'),
];
