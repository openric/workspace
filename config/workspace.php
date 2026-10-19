<?php

// OpenRiC Workspace - application settings.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    // The OpenRiC specification release this build implements (ADR 0003, rule 1;
    // conformance/README.md). Raised only together with the conformance matrix.
    'openric_spec_version' => '0.53.0',

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
