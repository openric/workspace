<?php

// OpenRiC Workspace - a request to join an institution's federation network, and its review (Heratio's federation_join_request; roadmap phase 16c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'institution_name', 'contact_name', 'contact_email', 'base_url', 'what_they_share', 'notes', 'status_id', 'reviewed_by_id', 'reviewed_at', 'review_note', 'peer_id'])]
class FederationJoinRequest extends Model
{
    use HasUuids;

    protected $table = 'federation_join_requests';
}
