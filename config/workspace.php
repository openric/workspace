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
    'bundled_vocabularies' => ['account-role.ttl', 'permission.ttl', 'audit-action.ttl'],

    // Role grants a new institution starts with, by notation. After installation
    // the grants are data in role_grants, changed by an administrator; this list is
    // read only by workspace:install. A role not listed here can do nothing.
    'default_grants' => [
        'administrator' => ['manage-institution', 'manage-accounts', 'view-audit-log'],
        'archivist' => ['describe'],
        'reviewer' => ['review', 'publish'],
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
