<?php

// OpenRiC Workspace - a watermark for one description (governance/HERATIO-PARITY.md, "Archival description", 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's object_watermark_setting, as its WatermarkService::getWatermarkConfig reads it: a description with a row
// of its own has no watermark when it is off; when it is on, its type, position and opacity, each falling back to
// the institution's. Without a row, the institution's watermark applies. Heratio's library of custom images is the
// institution's one custom image here.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        Schema::create('record_watermarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->boolean('enabled');
            $table->foreignUuid('watermark_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('watermark_position_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->smallInteger('opacity')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE record_watermarks ADD CONSTRAINT record_watermarks_opacity CHECK (opacity BETWEEN 1 AND 100)');
        foreach (['watermark_type_id' => 'watermark-type/', 'watermark_position_id' => 'watermark-position/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER record_watermarks_{$column}_vocabulary BEFORE INSERT OR UPDATE ON record_watermarks FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('record_watermarks');
    }
};
