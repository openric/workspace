<?php

// OpenRiC Workspace - the installation's Archivematica: its Dashboard and Storage Service, the transfer source
// and the folder SWORD deposits may come from (Heratio's ahg-archivematica settings; roadmap phase 15d). The API keys are encrypted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['dashboard_api_key', 'storage_api_key'])]
#[Fillable(['dashboard_url', 'dashboard_username', 'dashboard_api_key', 'storage_url', 'storage_username', 'storage_api_key', 'pipeline_uuid', 'transfer_source_location_uuid', 'transfer_source_path', 'staging_path', 'transfer_type', 'dip_match_strategy', 'deposit_path', 'fetch_dips', 'timeout_seconds'])]
class ArchivematicaSettings extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['dashboard_api_key' => 'encrypted', 'storage_api_key' => 'encrypted', 'fetch_dips' => 'boolean', 'timeout_seconds' => 'integer'];
    }

    /** The one row, made empty when there is none yet. */
    public static function current(): self
    {
        return self::first() ?? self::create([]);
    }

    public function dashboardConfigured(): bool
    {
        return filled($this->dashboard_url) && filled($this->dashboard_username) && filled($this->dashboard_api_key);
    }

    public function storageConfigured(): bool
    {
        return filled($this->storage_url) && filled($this->storage_username) && filled($this->storage_api_key);
    }
}
