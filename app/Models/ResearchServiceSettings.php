<?php

// OpenRiC Workspace - an institution's ORCID client and DataCite repository account; the secrets are encrypted (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'orcid_client_id', 'orcid_client_secret', 'orcid_sandbox', 'datacite_repository_id', 'datacite_password', 'datacite_prefix', 'datacite_test'])]
class ResearchServiceSettings extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_service_settings';

    protected function casts(): array
    {
        return ['orcid_client_secret' => 'encrypted', 'orcid_sandbox' => 'boolean', 'datacite_password' => 'encrypted', 'datacite_test' => 'boolean'];
    }
}
