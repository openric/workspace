<?php

// OpenRiC Workspace - an agent described by an authority record (ISAAR(CPF)): a rico:Person, rico:Family, rico:CorporateBody, rico:Position or rico:Mechanism.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use App\Description\Describable;
use App\Description\HasDates;
use App\Description\HasIdentifiers;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['institution_id', 'iri', 'agent_type_id', 'description_identifier', 'maintainer_id', 'description_status_id', 'description_detail_id', 'embargo_until', 'icip_sensitivity_id', 'source_standard'])]
class Agent extends Model
{
    use Audited, Describable, HasDates, HasIdentifiers, HasUuids;

    /** The URL path segment of its persistent IRI: {base IRI}/agent/{UUID}. */
    public const IRI_SEGMENT = 'agent';

    public const TYPES = Vocab::BASE.'agent-type/';

    protected function casts(): array
    {
        return ['embargo_until' => 'immutable_date', 'deleted_at' => 'immutable_datetime'];
    }

    /**
     * Every authority record of an institution as id => authorised name, in name order, for a choice list: the name in the
     * first of the languages that has one, else the first written, as described() picks it. One query, once a request, so
     * a form listing every agent never loads them as models (a record's page held two such lists: 13 s on live at 4,000).
     *
     * @param  list<string>  $languages
     * @return array<string, string>
     */
    public static function choices(string $institutionId, array $languages): array
    {
        return once(fn () => collect(DB::select('SELECT e.id, n.value FROM agents e
            LEFT JOIN LATERAL (SELECT value FROM names WHERE subject_table = ? AND subject_id = e.id AND name_type_id = (SELECT id FROM concepts WHERE iri = ?)
                ORDER BY array_position(?::text[], language::text) NULLS LAST, created_at LIMIT 1) n ON true
            WHERE e.institution_id = ? AND e.deleted_at IS NULL ORDER BY lower(n.value) NULLS LAST, e.id',
            ['agents', Vocab::NAME_TYPES.'authorised', '{'.implode(',', array_map(fn ($l) => '"'.$l.'"', $languages)).'}', $institutionId]))
            ->mapWithKeys(fn ($row) => [$row->id => (string) $row->value])->all());
    }

    public function agentType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'agent_type_id');
    }

    /** Its agent-type notation: person, family, corporate-body, position or mechanism. */
    public function typeNotation(): string
    {
        return (string) $this->agentType?->notation;
    }

    public function maintainer(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'maintainer_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function descriptionStatus(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'description_status_id');
    }

    public function descriptionDetail(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'description_detail_id');
    }

    /** Heratio's ICIP cultural-sensitivity classification of the record. */
    public function icipSensitivity(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'icip_sensitivity_id');
    }

    public function contactPoints(): HasMany
    {
        return $this->hasMany(ContactPoint::class, 'subject_id')->where('subject_table', $this->getTable())
            ->orderByDesc('is_primary')->orderBy('created_at');
    }

    /** Kept from those who may not update it until this date (living persons, POPIA). */
    public function isEmbargoed(): bool
    {
        return $this->embargo_until !== null && $this->embargo_until->isFuture();
    }
}
