<?php

// OpenRiC Workspace - a donor agreement: a deed of gift, deposit, loan or other agreement under which material comes to
// the institution (Heratio's ahg-donor-manage donor_agreement; exported as a rico:Record; ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\Describable;
use App\Description\HasIdentifiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['institution_id', 'iri', 'type_id', 'status_id', 'repository_id', 'parent_id', 'supersedes_id', 'agreed_on', 'effective_on', 'expires_on',
    'review_on', 'terminated_on', 'transfer_method', 'transferred_on', 'received_by', 'legal_representative', 'legal_representative_title',
    'repository_representative', 'repository_representative_title', 'has_financial_terms', 'purchase_amount', 'currency', 'donor_signature_name',
    'donor_signed_on', 'repository_signature_name', 'repository_signed_on', 'witness_name', 'witnessed_on', 'is_template'])]
class Agreement extends Model
{
    use Audited, Describable, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/agreement/{UUID}. */
    public const IRI_SEGMENT = 'agreement';

    /** Its dates, as Heratio has them. */
    public const DATES = ['agreed_on', 'effective_on', 'expires_on', 'review_on', 'terminated_on', 'transferred_on', 'donor_signed_on', 'repository_signed_on', 'witnessed_on'];

    protected function casts(): array
    {
        return [...array_fill_keys(self::DATES, 'immutable_date'), 'has_financial_terms' => 'boolean', 'is_template' => 'boolean',
            'purchase_amount' => 'decimal:2', 'deleted_at' => 'immutable_datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'status_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** The agreement this one is part of, such as the deed an addendum belongs to. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The agreement this one replaces. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function rights(): HasMany
    {
        return $this->hasMany(AgreementRight::class)->orderBy('created_at');
    }

    public function restrictions(): HasMany
    {
        return $this->hasMany(AgreementRestriction::class)->orderBy('created_at');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(AgreementReminder::class)->orderBy('remind_on')->orderBy('created_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AgreementDocument::class)->orderByDesc('created_at');
    }

    /** The agreement number: its local identifier. */
    public function number(): ?string
    {
        return $this->identifier;
    }

    /** Its number and title, as lists show it. */
    public function heading(array $languages = ['en']): string
    {
        return trim(implode(' ', array_filter([$this->number(), $this->described('agreement-title', $languages)])));
    }
}
