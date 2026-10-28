<?php

// OpenRiC Workspace - the fields Heratio has that the Workspace lacked (roadmap, field by field, 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// governance/HERATIO-PARITY.md, "Field by field": a repository's type (ISDIAH 5.1.5, AtoM's repository types);
// an activity's description identifier, status and level of detail (ISDF 5.4.1, 5.4.4, 5.4.5), as an agent has
// them; a contact point's latitude, longitude and note; a right's licence terms and policy identifier (Heratio's
// ahg-extended-rights). The other fields are description elements of the bundled vocabulary, imported here.

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
        Schema::table('repositories', fn (Blueprint $table) => $table->foreignUuid('repository_type_id')->nullable()->constrained('concepts')->restrictOnDelete());
        DB::statement("CREATE TRIGGER repositories_repository_type_id_vocabulary BEFORE INSERT OR UPDATE ON repositories FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('repository_type_id', '".self::VOCAB."repository-type/')");

        Schema::table('activities', function (Blueprint $table) {
            $table->text('description_identifier')->nullable();
            $table->foreignUuid('description_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('description_detail_id')->nullable()->constrained('concepts')->restrictOnDelete();
        });
        foreach (['description_status_id' => 'description-status/', 'description_detail_id' => 'description-detail/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER activities_{$column}_vocabulary BEFORE INSERT OR UPDATE ON activities FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }

        Schema::table('contact_points', function (Blueprint $table) {
            // WGS 84 decimal degrees, as a place's coordinates.
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->text('note')->nullable();
        });

        Schema::table('rights', function (Blueprint $table) {
            // The terms of a licence outside Creative Commons, and the institution's policy the right rests on.
            $table->text('licence_terms')->nullable();
            $table->text('policy_identifier')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rights', fn (Blueprint $table) => $table->dropColumn(['licence_terms', 'policy_identifier']));
        Schema::table('contact_points', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude', 'note']));
        DB::statement('DROP TRIGGER IF EXISTS activities_description_status_id_vocabulary ON activities');
        DB::statement('DROP TRIGGER IF EXISTS activities_description_detail_id_vocabulary ON activities');
        Schema::table('activities', fn (Blueprint $table) => $table->dropConstrainedForeignId('description_status_id'));
        Schema::table('activities', fn (Blueprint $table) => $table->dropConstrainedForeignId('description_detail_id'));
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn('description_identifier'));
        DB::statement('DROP TRIGGER IF EXISTS repositories_repository_type_id_vocabulary ON repositories');
        Schema::table('repositories', fn (Blueprint $table) => $table->dropConstrainedForeignId('repository_type_id'));
    }
};
