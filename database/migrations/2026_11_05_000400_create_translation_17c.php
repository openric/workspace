<?php

// OpenRiC Workspace - translation (roadmap phase 17c), after Heratio's ahg-translation: the language an institution
// translates into by default and the elements it offers, beside its other AI settings; and the institution's own
// wording of the interface, by language and string, with every change proposed, reviewed and kept
// (Heratio's ui_string and ui_string_change). A machine translation of a description is a proposal of the review
// queue, so it needs no table of its own (Heratio's ahg_translation_draft).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('translation_target', 35)->nullable();
            $table->jsonb('translation_elements')->default('[]');
        });

        Schema::create('interface_strings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->text('key');
            $table->text('value');
            $table->foreignUuid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['institution_id', 'locale', 'key']);
        });

        Schema::create('interface_string_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->text('key');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('submitted_by_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->index(['institution_id', 'status_id', 'submitted_at']);
            $table->index(['institution_id', 'locale', 'key']);
        });
        DB::statement("CREATE TRIGGER interface_string_changes_status_id_vocabulary BEFORE INSERT OR UPDATE ON interface_string_changes FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('status_id', '".self::VOCAB."assertion-status/')");
    }

    public function down(): void
    {
        Schema::dropIfExists('interface_string_changes');
        Schema::dropIfExists('interface_strings');
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['translation_target', 'translation_elements']);
        });
    }
};
