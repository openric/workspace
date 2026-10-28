<?php

// OpenRiC Workspace - a contact point of an entity (ISDIAH 5.2; ISAAR(CPF) later).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'subject_table', 'subject_id', 'is_primary', 'label', 'contact_person', 'street_address', 'city', 'region', 'postal_code', 'country_code', 'telephone', 'fax', 'email', 'website', 'latitude', 'longitude', 'note'])]
class ContactPoint extends Model
{
    use Audited, HasUuids;

    /** The editable fields, in form order. */
    public const FIELDS = ['label', 'contact_person', 'street_address', 'city', 'region', 'postal_code', 'country_code', 'telephone', 'fax', 'email', 'website', 'latitude', 'longitude', 'note'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
