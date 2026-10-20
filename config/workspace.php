<?php

// OpenRiC Workspace - application settings.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    // The OpenRiC specification release this build implements (ADR 0003, rule 1;
    // conformance/README.md). Raised only together with the conformance matrix.
    'openric_spec_version' => '0.53.0',

    // Ed25519 key that signs every audit row (ADR 0004). Kept outside git and the
    // database, readable only by the application; back it up with the database.
    'audit_signing_key' => env('WORKSPACE_AUDIT_SIGNING_KEY', storage_path('keys/audit-signing.sk')),

    // The Workspace's own vocabularies, bundled with the code (resources/vocabularies).
    'bundled_vocabularies' => ['account-role.ttl', 'permission.ttl', 'audit-action.ttl', 'description-element.ttl', 'language-value-state.ttl', 'description-status.ttl', 'description-detail.ttl', 'level-of-description.ttl', 'record-kind.ttl', 'date-property.ttl', 'date-qualifier.ttl', 'name-type.ttl', 'identifier-type.ttl', 'relation-type.ttl', 'relation-state.ttl', 'relation-certainty.ttl', 'assertion-origin.ttl', 'assertion-status.ttl'],

    // The shape of the permission matrix: for each kind of record (record-kind
    // vocabulary), the actions that apply to it (permission vocabulary), as
    // Heratio has one action list per class (ADR 0005).
    'permission_matrix' => [
        'institution' => ['manage-institution', 'manage-accounts', 'manage-roles', 'view-audit-log'],
        'repository' => ['read', 'create', 'update', 'delete'],
        'description' => ['read', 'create', 'update', 'delete', 'view-draft', 'publish', 'read-master', 'read-reference', 'read-thumbnail', 'translate'],
        'vocabulary' => ['create', 'update', 'delete'],
    ],

    // Which date properties (date-property vocabulary) each kind of entity offers on
    // screen, in this order (ADR 0006). The store accepts any of them.
    'date_properties' => [
        'description' => ['creation', 'accumulation', 'associated'],
        'agent' => ['existence', 'birth', 'death', 'associated'],
        'repository' => ['existence', 'associated'],
        'name' => ['used-from', 'used-to'],
        'relation' => ['beginning', 'end', 'relation'],
    ],

    // Which kinds of entity each relation type (relation-type vocabulary) connects,
    // as [source kind, target kind] pairs; "agent" stands for every kind of agent
    // (relation_kind_groups). symmetric: the order of the two carries no meaning.
    // role: the relation may carry an agent role (OpenRiC role-type vocabulary).
    // forward and inverse: how the relation reads from its source and from its
    // target, on screen.
    'relation_kind_groups' => [
        'agent' => ['repository'],
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
    ],

    // The roles a new institution starts with, by account-role template, with
    // their parent and their permissions on everything (* = every action of
    // every kind), after Heratio's and AtoM's standard groups. Read only when
    // an institution is installed; afterwards roles and permissions are data.
    'default_roles' => [
        'public' => ['parent' => null, 'grants' => ['repository' => ['read'], 'description' => ['read', 'read-reference', 'read-thumbnail']]],
        'signed-in' => ['parent' => 'public', 'grants' => []],
        'administrator' => ['parent' => 'signed-in', 'grants' => '*'],
        'editor' => ['parent' => 'signed-in', 'grants' => [
            'repository' => ['read', 'create', 'update'],
            'description' => ['read', 'create', 'update', 'delete', 'view-draft', 'publish', 'read-master', 'read-reference', 'read-thumbnail'],
            'vocabulary' => ['create', 'update'],
        ]],
        'contributor' => ['parent' => 'signed-in', 'grants' => [
            'repository' => ['read'],
            'description' => ['read', 'create', 'update', 'view-draft', 'read-reference', 'read-thumbnail'],
        ]],
        'translator' => ['parent' => 'signed-in', 'grants' => ['description' => ['read', 'view-draft', 'translate']]],
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

];
