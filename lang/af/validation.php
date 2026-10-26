<?php

// OpenRiC Workspace - Laravel's validation messages in Afrikaans (WS-005, roadmap phase 1), for the rules the
// Workspace uses; a rule not listed falls back to Laravel's English. Written for the Workspace: neither Heratio
// nor PSIS has them. For review by Dr Johan Pieterse.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'accepted' => 'Die :attribute-veld moet aanvaar word.',
    'after' => 'Die :attribute-veld moet \'n datum ná :date wees.',
    'after_or_equal' => 'Die :attribute-veld moet \'n datum op of ná :date wees.',
    'array' => 'Die :attribute-veld moet \'n lys wees.',
    'boolean' => 'Die :attribute-veld moet waar of vals wees.',
    'confirmed' => 'Die bevestiging van :attribute stem nie ooreen nie.',
    'current_password' => 'Die wagwoord is nie korrek nie.',
    'date' => 'Die :attribute-veld moet \'n geldige datum wees.',
    'date_format' => 'Die :attribute-veld moet die formaat :format hê.',
    'email' => 'Die :attribute-veld moet \'n geldige e-posadres wees.',
    'exists' => 'Die gekose :attribute is nie geldig nie.',
    'file' => 'Die :attribute-veld moet \'n lêer wees.',
    'in' => 'Die gekose :attribute is nie geldig nie.',
    'integer' => 'Die :attribute-veld moet \'n heelgetal wees.',
    'max' => [
        'array' => 'Die :attribute-veld mag nie meer as :max items hê nie.',
        'file' => 'Die :attribute-veld mag nie groter as :max kilogrepe wees nie.',
        'numeric' => 'Die :attribute-veld mag nie groter as :max wees nie.',
        'string' => 'Die :attribute-veld mag nie meer as :max karakters hê nie.',
    ],
    'mimes' => 'Die :attribute-veld moet \'n lêer van die tipe :values wees.',
    'min' => [
        'array' => 'Die :attribute-veld moet ten minste :min items hê.',
        'file' => 'Die :attribute-veld moet ten minste :min kilogrepe wees.',
        'numeric' => 'Die :attribute-veld moet ten minste :min wees.',
        'string' => 'Die :attribute-veld moet ten minste :min karakters hê.',
    ],
    'password' => [
        'letters' => 'Die :attribute-veld moet ten minste een letter bevat.',
        'mixed' => 'Die :attribute-veld moet ten minste een hoofletter en een kleinletter bevat.',
        'numbers' => 'Die :attribute-veld moet ten minste een syfer bevat.',
        'symbols' => 'Die :attribute-veld moet ten minste een simbool bevat.',
        'uncompromised' => 'Hierdie :attribute het in \'n datalek verskyn. Kies \'n ander :attribute.',
    ],
    'regex' => 'Die formaat van die :attribute-veld is nie geldig nie.',
    'required' => 'Die :attribute-veld is verpligtend.',
    'string' => 'Die :attribute-veld moet teks wees.',
    'unique' => 'Die :attribute is reeds gebruik.',
    'uploaded' => 'Die :attribute kon nie opgelaai word nie.',
    'url' => 'Die :attribute-veld moet \'n geldige webadres wees.',
    'uuid' => 'Die :attribute-veld moet \'n geldige UUID wees.',

    // The names of the fields, as they read in a message.
    'attributes' => [
        'name' => 'naam',
        'email' => 'e-pos',
        'password' => 'wagwoord',
        'current_password' => 'huidige wagwoord',
        'language' => 'taal',
        'title' => 'titel',
        'value' => 'waarde',
        'file' => 'lêer',
        'reason' => 'rede',
        'note' => 'nota',
        'identifier' => 'identifiseerder',
        'type' => 'tipe',
        'level' => 'vlak',
        'source' => 'bron',
        'label' => 'etiket',
        'scopes' => 'bestekke',
        'daily_limit' => 'daaglikse perk',
        'expires_at' => 'vervaldatum',
        'parent' => 'ouer',
        'place' => 'plek',
        'repository' => 'bewaarplek',
    ],
];
