<?php

// OpenRiC Workspace - display profiles and browse settings (roadmap phase 20d), after Heratio's ahg-display: a profile
// says how the public sees a unit of description of a level (or of any level, the institution's default): which
// elements, in what order and under what label in each language, with the hierarchy beside it or not, its images at
// what size, and what the reader may do from the page. Browse settings say how the staff browse lists units: the view,
// how many a page, the sort and its direction, and images or none; the institution sets them, and each account may set
// its own where the institution allows it. Changed from Heratio: the archival part only (no museum, library or gallery
// collection types), and every choice is a vocabulary term.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

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
        foreach (['display-action', 'thumbnail-size', 'browse-view', 'browse-sort', 'sort-direction'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }

        Schema::create('display_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name', 200);
            // The level it is for; none is the institution's default for every level without a profile of its own.
            $table->foreignUuid('level_id')->nullable()->constrained('concepts')->restrictOnDelete();
            // The ISAD(G) elements shown, by notation, in order; empty shows every element in the standard's order.
            $table->jsonb('elements')->default('[]');
            // The institution's labels for them: {"scope-and-content": {"en": "...", "af": "..."}}.
            $table->jsonb('labels')->default('{}');
            $table->boolean('show_tree')->default(true);
            $table->foreignUuid('thumbnail_size_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX display_profiles_level_unique ON display_profiles (institution_id, level_id) WHERE level_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX display_profiles_default_unique ON display_profiles (institution_id) WHERE level_id IS NULL');
        Schema::create('display_profile_actions', function (Blueprint $table) {
            $table->foreignUuid('display_profile_id')->constrained('display_profiles')->cascadeOnDelete();
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->primary(['display_profile_id', 'action_id']);
        });

        Schema::create('browse_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // None is the institution's own settings; an account's override its own.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('view_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('sort_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('direction_id')->constrained('concepts')->restrictOnDelete();
            $table->smallInteger('per_page')->default(50);
            $table->boolean('show_thumbnails')->default(true);
            // On the institution's row: whether an account may set its own.
            $table->boolean('allow_user_override')->default(true);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE browse_settings ADD CONSTRAINT browse_settings_per_page CHECK (per_page BETWEEN 10 AND 200)');
        DB::statement('CREATE UNIQUE INDEX browse_settings_institution_unique ON browse_settings (institution_id) WHERE user_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX browse_settings_user_unique ON browse_settings (user_id) WHERE user_id IS NOT NULL');

        foreach ([['display_profiles', 'level_id', 'level-of-description'], ['display_profiles', 'thumbnail_size_id', 'thumbnail-size'],
            ['display_profile_actions', 'action_id', 'display-action'], ['browse_settings', 'view_id', 'browse-view'],
            ['browse_settings', 'sort_id', 'browse-sort'], ['browse_settings', 'direction_id', 'sort-direction']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('browse_settings');
        Schema::dropIfExists('display_profile_actions');
        Schema::dropIfExists('display_profiles');
    }
};
