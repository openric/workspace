<?php

// OpenRiC Workspace - 3D objects (roadmap phase 21, 3D only), after Heratio's ahg-3d-model: how a model is shown (its
// rotation, camera, field of view, light, shadow, background and augmented reality), as each institution sets it by
// default and as each model may change it; hotspots, labelled points on a model in several languages, drafted and
// published with the record; saved camera views, a reader's own or published by staff; and what a model's file is made
// of (vertices, faces, textures, materials, animations, dimensions), kept on the file. Every type and placement is a
// vocabulary row.
// Changed from Heratio: a model is a file of an instantiation (rico:Instantiation), not a record of its own
// (object_3d_model); its settings and hotspots hang on the instantiation; a hotspot's text has a state, as every value
// does; textures travel inside the GLB, so Heratio's object_3d_texture has no table.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hotspot-type', 'ar-placement'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }

        // How a model is shown: one row per institution (its defaults) and one per instantiation that changes them.
        $view = function (Blueprint $table) {
            $table->boolean('auto_rotate')->nullable();
            $table->smallInteger('rotation_speed')->nullable();
            $table->string('camera_orbit', 100)->nullable();
            $table->string('min_camera_orbit', 100)->nullable();
            $table->string('max_camera_orbit', 100)->nullable();
            $table->string('field_of_view', 20)->nullable();
            $table->decimal('exposure', 4, 2)->nullable();
            $table->decimal('shadow_intensity', 4, 2)->nullable();
            $table->decimal('shadow_softness', 4, 2)->nullable();
            $table->boolean('neutral_lighting')->nullable();
            $table->string('background_color', 7)->nullable();
            $table->boolean('ar_enabled')->nullable();
            $table->boolean('ar_fixed_scale')->nullable();
            $table->foreignUuid('ar_placement_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
        };
        Schema::create('model_viewer_defaults', function (Blueprint $table) use ($view) {
            $table->foreignUuid('institution_id')->primary()->constrained('institutions')->cascadeOnDelete();
            $view($table);
        });
        Schema::create('model_settings', function (Blueprint $table) use ($view) {
            $table->foreignUuid('instantiation_id')->primary()->constrained('instantiations')->cascadeOnDelete();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $view($table);
        });

        // Heratio's object_3d_hotspot and object_3d_hotspot_i18n.
        Schema::create('model_hotspots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('instantiation_id')->constrained('instantiations')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            foreach (['position_x', 'position_y', 'position_z'] as $c) {
                $table->decimal($c, 14, 6);
            }
            foreach (['normal_x' => 0, 'normal_y' => 1, 'normal_z' => 0] as $c => $d) {
                $table->decimal($c, 10, 6)->default($d);
            }
            $table->string('color', 7)->default('#10373E');
            $table->string('link_url', 2000)->nullable();
            $table->integer('position')->default(0);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['instantiation_id', 'position']);
        });
        Schema::create('model_hotspot_texts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hotspot_id')->constrained('model_hotspots')->cascadeOnDelete();
            $table->string('language', 35);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignUuid('state_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['hotspot_id', 'language']);
        });

        // Heratio's object_3d_camera_bookmark: a reader's own views, or one staff publish for everyone.
        Schema::create('model_bookmarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('instantiation_id')->constrained('instantiations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('camera_orbit', 100);
            $table->string('camera_target', 100)->nullable();
            $table->string('field_of_view', 20)->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestampsTz();
            $table->index(['instantiation_id', 'user_id']);
        });

        // What the model is made of (Heratio's ModelMetadataExtractor), read from the master at upload.
        Schema::table('digital_files', function (Blueprint $table) {
            $table->json('model_properties')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('digital_files', fn (Blueprint $table) => $table->dropColumn('model_properties'));
        foreach (['model_bookmarks', 'model_hotspot_texts', 'model_hotspots', 'model_settings', 'model_viewer_defaults'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
