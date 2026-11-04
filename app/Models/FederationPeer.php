<?php

// OpenRiC Workspace - a federation peer of an institution: another institution here, another Workspace or Heratio, or a
// catalogue searched by its own protocol, with how it is searched and whether its answers are trusted (Heratio's
// federation_peer and federation_peer_search; roadmap phase 16a). Its API key is encrypted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'name', 'type_id', 'base_url', 'search_url', 'peer_institution_id', 'api_key', 'description', 'contact_email', 'is_active', 'search_enabled',
    'timeout_ms', 'max_results', 'priority', 'last_search_at', 'last_search_status_id', 'avg_response_ms', 'pinned_key_id', 'key_pinned_at', 'last_verification_id',
    'metadata_prefix', 'default_set', 'harvest_interval_hours', 'harvest_repository_id', 'last_harvest_at', 'last_harvest_status_id', 'last_harvest_records', 'config',
    'trust_level_id', 'rate_limit_seconds', 'allowed_surfaces', 'discovery_status_id', 'protocol_version', 'declared_surfaces', 'maturity_grade', 'capabilities', 'last_probed_at'])]
#[Hidden(['api_key'])]
class FederationPeer extends Model
{
    use Audited, HasUuids;

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'is_active' => 'boolean', 'search_enabled' => 'boolean', 'timeout_ms' => 'integer', 'max_results' => 'integer', 'priority' => 'integer',
            'avg_response_ms' => 'integer', 'last_search_at' => 'immutable_datetime', 'key_pinned_at' => 'immutable_datetime',
            'harvest_interval_hours' => 'integer', 'last_harvest_at' => 'immutable_datetime', 'last_harvest_records' => 'integer', 'config' => 'array',
            'rate_limit_seconds' => 'integer', 'allowed_surfaces' => 'array', 'declared_surfaces' => 'array', 'capabilities' => 'array', 'last_probed_at' => 'immutable_datetime'];
    }

    /** Searching or being searched changes no record of the peer worth an audit row. */
    public function notAudited(): array
    {
        return ['last_search_at', 'last_search_status_id', 'avg_response_ms', 'last_verification_id', 'last_harvest_at', 'last_harvest_status_id', 'last_harvest_records', 'discovery_status_id', 'protocol_version', 'declared_surfaces', 'maturity_grade', 'capabilities', 'last_probed_at'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function peerInstitution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'peer_institution_id');
    }

    public function trustLevel(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'trust_level_id');
    }

    public function discoveryStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'discovery_status_id');
    }

    public function lastHarvestStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'last_harvest_status_id');
    }

    public function harvestRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'harvest_repository_id');
    }

    public function lastStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'last_search_status_id');
    }

    public function lastVerification(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'last_verification_id');
    }

    /** The address searched: the one given, or the peer's federation search under its base. */
    public function searchAddress(): ?string
    {
        return $this->search_url ?: ($this->base_url ? rtrim($this->base_url, '/').'/federation/search' : null);
    }
}
