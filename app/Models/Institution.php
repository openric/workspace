<?php

// OpenRiC Workspace - an institution, the owner of every other record.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'base_iri', 'header_title', 'logo_path', 'logo_mime', 'catalogue_introduction', 'catalogue_credits', 'registration_open', 'interface_languages',
    'watermark_type_id', 'watermark_position_id', 'watermark_opacity', 'watermark_min_size', 'watermark_image_path', 'accession_numbering_mask',
    'agreement_numbering_mask', 'accession_auto_assign', 'accession_requires_agreement', 'accession_default_priority_id', 'accession_checklist_template_id',
    'accession_requires_appraisal', 'accession_container_barcodes', 'accession_rights_inheritance'])]
class Institution extends Model
{
    protected function casts(): array
    {
        return ['registration_open' => 'boolean', 'interface_languages' => 'array', 'accession_auto_assign' => 'boolean', 'accession_requires_agreement' => 'boolean', 'accession_requires_appraisal' => 'boolean', 'accession_container_barcodes' => 'boolean', 'accession_rights_inheritance' => 'boolean'];
    }

    use Audited, HasUuids;

    /** The institution whose branding a page shows: the signed-in account's, or the installation's only one. */
    public static function current(): ?self
    {
        return auth()->user()?->institution ?? self::orderBy('created_at')->first();
    }

    public function headerTitle(): string
    {
        return $this->header_title ?: (string) config('app.name');
    }

    public function watermarkType(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'watermark_type_id');
    }

    public function watermarkPosition(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'watermark_position_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
