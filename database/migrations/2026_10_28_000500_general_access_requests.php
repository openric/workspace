<?php

// OpenRiC Workspace - general access requests (governance/HERATIO-PARITY.md, "Access request", 28 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's new-request form (ahg-access-request new.blade.php): a subject, a request type and a scope of
// everything, one collection with what is inside it, or one item, besides the request for one instantiation's
// files. Heratio folds the description into reason; it stays there. Its request-object form also asks how the
// files will be used (access_type) but never keeps it; here it is kept. The scope is a concept; the record is
// the collection or item asked for, none for everything; the instantiation, when the request is for its files.

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
        Schema::table('access_requests', function (Blueprint $table) {
            $table->foreignUuid('scope_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->restrictOnDelete();
            $table->foreignUuid('request_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('access_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('subject')->nullable();
            $table->index(['user_id', 'record_resource_id']);
        });
        // The requests made so far are each for one instantiation's files: a single item, the instantiation's record.
        $item = DB::table('concepts')->where('iri', self::VOCAB.'access-request-scope/item')->value('id');
        DB::statement('UPDATE access_requests SET scope_id = ?, record_resource_id = i.record_resource_id FROM instantiations i WHERE i.id = access_requests.instantiation_id', [$item]);
        Schema::table('access_requests', function (Blueprint $table) {
            $table->uuid('scope_id')->nullable(false)->change();
            $table->uuid('instantiation_id')->nullable()->change();
        });
        foreach (['scope_id' => 'access-request-scope/', 'request_type_id' => 'access-request-type/', 'access_type_id' => 'access-type/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER access_requests_{$column}_vocabulary BEFORE INSERT OR UPDATE ON access_requests FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
        // An instantiation's files are asked for through its record.
        DB::statement('ALTER TABLE access_requests ADD CONSTRAINT access_requests_instantiation_has_record CHECK (instantiation_id IS NULL OR record_resource_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access_requests DROP CONSTRAINT IF EXISTS access_requests_instantiation_has_record');
        foreach (['scope_id', 'request_type_id', 'access_type_id'] as $column) {
            DB::statement("DROP TRIGGER IF EXISTS access_requests_{$column}_vocabulary ON access_requests");
        }
        DB::table('access_requests')->whereNull('instantiation_id')->delete();
        Schema::table('access_requests', function (Blueprint $table) {
            $table->uuid('instantiation_id')->nullable(false)->change();
            $table->dropIndex(['user_id', 'record_resource_id']);
            $table->dropConstrainedForeignId('scope_id');
            $table->dropConstrainedForeignId('record_resource_id');
            $table->dropConstrainedForeignId('request_type_id');
            $table->dropConstrainedForeignId('access_type_id');
            $table->dropColumn('subject');
        });
    }
};
