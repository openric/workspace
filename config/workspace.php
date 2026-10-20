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
    'bundled_vocabularies' => ['account-role.ttl', 'permission.ttl', 'audit-action.ttl', 'description-element.ttl', 'language-value-state.ttl', 'description-status.ttl', 'description-detail.ttl', 'level-of-description.ttl', 'record-kind.ttl', 'date-property.ttl', 'date-qualifier.ttl'],

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
