<?php

// OpenRiC Workspace - contact points (ISDIAH 5.2) and the control area of a description (5.6).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A contact point belongs to any entity (subject_table, subject_id), so agents
// use the same table later. Status and level of detail are concepts of their
// own vocabularies; the textual control elements are language values.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        Schema::create('contact_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->boolean('is_primary')->default(false);
            // A free label such as "Reading room" (Heratio and AtoM keep it as free text too).
            $table->string('label', 255)->nullable();
            $table->string('contact_person', 255)->nullable();
            $table->text('street_address')->nullable();
            $table->string('city', 255)->nullable();
            $table->string('region', 255)->nullable();
            $table->string('postal_code', 64)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('telephone', 64)->nullable();
            $table->string('fax', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 1024)->nullable();
            $table->timestampsTz();
            $table->index(['subject_table', 'subject_id']);
        });
        // ISO 3166-1 alpha-2. ponytail: syntax only; a country vocabulary can replace it.
        DB::statement("ALTER TABLE contact_points ADD CONSTRAINT contact_points_country_code CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')");

        Schema::table('repositories', function (Blueprint $table) {
            // ISDIAH 5.6.1 Description identifier.
            $table->string('description_identifier', 255)->nullable();
            $table->foreignUuid('description_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('description_detail_id')->nullable()->constrained('concepts')->restrictOnDelete();
        });
        foreach (['description_status_id' => 'description-status/', 'description_detail_id' => 'description-detail/'] as $column => $vocabulary) {
            $iri = self::VOCAB.$vocabulary;
            DB::statement("CREATE TRIGGER repositories_{$column}_vocabulary BEFORE INSERT OR UPDATE ON repositories FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '{$iri}')");
        }
    }

    public function down(): void
    {
        foreach (['description_status_id', 'description_detail_id'] as $column) {
            DB::statement("DROP TRIGGER IF EXISTS repositories_{$column}_vocabulary ON repositories");
        }
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropForeign(['description_status_id']);
            $table->dropForeign(['description_detail_id']);
            $table->dropColumn(['description_identifier', 'description_status_id', 'description_detail_id']);
        });
        Schema::dropIfExists('contact_points');
    }
};
