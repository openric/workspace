<?php

// OpenRiC Workspace - curated IIIF collections, after Heratio's ahg-iiif-collection iiif_collection and
// iiif_collection_item (roadmap phase 14a, ADR 0014): a named, ordered list of the institution's records (each its
// manifest), of its other curated collections, and of other institutions' manifests and collections by their URIs.
// A collection's behaviour and an outside item's type are vocabulary rows.
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
        foreach (['iiif-behavior.ttl', 'iiif-resource-type.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('iiif_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->text('label');
            $table->text('summary')->nullable();
            $table->text('language')->default('en');
            $table->text('attribution')->nullable();
            $table->foreignUuid('behavior_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->date('nav_date')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_public')->default(true);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::table('iiif_collections', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('iiif_collections')->nullOnDelete());
        Schema::create('iiif_collection_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('iiif_collection_id')->constrained('iiif_collections')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->nullable()->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('member_collection_id')->nullable()->constrained('iiif_collections')->cascadeOnDelete();
            $table->text('uri')->nullable();
            $table->foreignUuid('resource_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('label')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();
            $table->index(['iiif_collection_id', 'position']);
        });
        DB::statement("ALTER TABLE iiif_collections ADD CONSTRAINT iiif_collections_values CHECK (btrim(label) <> '' AND parent_id IS DISTINCT FROM id)");
        // An item is exactly one of: a record, another curated collection, or an outside resource with its type.
        DB::statement("ALTER TABLE iiif_collection_items ADD CONSTRAINT iiif_collection_items_one CHECK (
            (record_resource_id IS NOT NULL)::int + (member_collection_id IS NOT NULL)::int + (uri IS NOT NULL)::int = 1
            AND (uri IS NULL OR (uri ~ '^https?://' AND resource_type_id IS NOT NULL))
            AND member_collection_id IS DISTINCT FROM iiif_collection_id)");
        foreach ([['iiif_collections', 'behavior_id', 'iiif-behavior'], ['iiif_collection_items', 'resource_type_id', 'iiif-resource-type']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('iiif_collection_items');
        Schema::dropIfExists('iiif_collections');
    }
};
