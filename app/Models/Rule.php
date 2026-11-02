<?php

// OpenRiC Workspace - a rule or mandate, such as an act, a regulation or the mandate of an office (rico:Rule,
// rico:Mandate; roadmap phase 23b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// As App\Models\Activity, with the rule type and Heratio's authority IRI (ahg-ric ric_rule.authority_uri).

namespace App\Models;

use App\Audit\Audited;
use App\Description\Describable;
use App\Description\HasDates;
use App\Description\HasIdentifiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['institution_id', 'iri', 'rule_type_id', 'authority_iri', 'description_identifier', 'description_status_id', 'description_detail_id'])]
class Rule extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/rule/{UUID}. */
    public const IRI_SEGMENT = 'rule';

    /** The OpenRiC rule-type vocabulary (mapping section 9). */
    public const TYPES = 'https://openric.org/vocab/rule-type/';

    protected function casts(): array
    {
        return ['deleted_at' => 'immutable_datetime'];
    }

    /** rico:hasOrHadRuleType */
    public function ruleType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'rule_type_id');
    }

    public function descriptionStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'description_status_id');
    }

    public function descriptionDetail(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'description_detail_id');
    }

    /** A rule of the rule type mandate is a rico:Mandate, a subclass of rico:Rule. */
    public function isMandate(): bool
    {
        return $this->ruleType?->iri === self::TYPES.'mandate';
    }
}
