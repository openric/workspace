<?php

// OpenRiC Workspace - a researcher registered with the institution: who they are, their affiliation and identity, and whether they may use the reading room (Heratio's research_researcher; roadmap phase 19b). The phone and identity number are encrypted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'user_id', 'title', 'first_name', 'last_name', 'phone', 'affiliation_id', 'institution', 'department', 'position', 'student_id', 'research_interests', 'current_project', 'orcid_id', 'researcher_id_wos', 'scopus_id', 'isni', 'researcher_type_id', 'id_type_id', 'id_number', 'id_verified', 'id_verified_by_id', 'id_verified_at', 'status_id', 'rejection_reason', 'approved_by_id', 'approved_at', 'expires_on', 'renewal_reminder_sent_at', 'notes', 'photo_file_id', 'card_number', 'card_issued_at'])]
class Researcher extends Model
{
    use Audited, HasUuids;

    protected $table = 'researchers';

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'id_number' => 'encrypted', 'id_verified' => 'boolean', 'id_verified_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'expires_on' => 'immutable_date', 'renewal_reminder_sent_at' => 'immutable_datetime', 'card_issued_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function affiliation(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'affiliation_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ResearcherType::class, 'researcher_type_id');
    }

    public function idType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'id_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(ResearchFile::class, 'photo_file_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(ResearcherVerification::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ReadingRoomBooking::class);
    }
}
