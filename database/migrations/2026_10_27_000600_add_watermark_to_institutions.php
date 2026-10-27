<?php

// OpenRiC Workspace - the watermark an institution writes on the reference copies of its images (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's default watermark (watermark_setting, watermark_type, custom_watermark;
// ahg-media-processing) as columns of the institution: its type and position are
// concepts, null type for none; a custom image is kept on the private disk, as the logo
// is. The opacity is a percentage; an image smaller than the minimum size on either side
// is not marked. A master is never marked, nor a thumbnail.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::table('institutions', function (Blueprint $table) {
            $table->foreignUuid('watermark_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('watermark_position_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->smallInteger('watermark_opacity')->default(40);
            $table->integer('watermark_min_size')->default(200);
            $table->text('watermark_image_path')->nullable();
        });
        DB::statement('ALTER TABLE institutions ADD CONSTRAINT institutions_watermark_opacity CHECK (watermark_opacity BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE institutions ADD CONSTRAINT institutions_watermark_min_size CHECK (watermark_min_size >= 0)');
        foreach ([['watermark_type_id', 'watermark-type/'], ['watermark_position_id', 'watermark-position/']] as [$column, $vocabulary]) {
            DB::statement("CREATE TRIGGER institutions_{$column}_vocabulary BEFORE INSERT OR UPDATE ON institutions FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
    }

    public function down(): void
    {
        foreach (['watermark_type_id', 'watermark_position_id'] as $column) {
            DB::statement("DROP TRIGGER IF EXISTS institutions_{$column}_vocabulary ON institutions");
        }
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('watermark_type_id');
            $table->dropConstrainedForeignId('watermark_position_id');
            $table->dropColumn(['watermark_opacity', 'watermark_min_size', 'watermark_image_path']);
        });
    }
};
