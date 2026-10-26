<?php

// OpenRiC Workspace - proposals for a record, sent through the API, waiting for review (ADR 0007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A proposal asks to add a person or organisation, a place, a date, the value
// of an element or the transcript of a file to one record; its kind comes from
// the proposal-kind vocabulary and its status from assertion-status, as a
// proposed relation's does (a proposed relation is a relation assertion, not a
// row here). Nothing here is part of the record: review writes through the
// record's own services. Who proposed it is an account, through one of its
// keys; what proposed it is a label the caller gives, such as glam-mcp. Evidence
// is text, sources are web addresses. A proposal is kept after review, with who
// reviewed it and when. The review-proposals permission is given to the default
// roles that hold it in config workspace.default_roles.

use App\Access\RoleService;
use App\Models\Institution;
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
        // The vocabularies the checks below name, and the permission the grants cite.
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->foreignUuid('kind_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('value');
            // A value's element and language; a person or organisation's agent type; a transcript's instantiation.
            $table->foreignUuid('element_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->string('language', 35)->nullable();
            $table->foreignUuid('agent_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('instantiation_id')->nullable()->constrained('instantiations')->restrictOnDelete();
            // An existing agent or place proposed for a name, and why.
            $table->text('linked_table')->nullable();
            $table->uuid('linked_id')->nullable();
            $table->text('link_reason')->nullable();
            // What the proposer knows of a person or organisation: dates of existence, history, places.
            $table->jsonb('authority')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('evidence')->nullable();
            $table->jsonb('sources')->default('[]');
            $table->text('origin_label');
            $table->foreignUuid('proposed_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();
            $table->index(['record_resource_id', 'status_id']);
            $table->index(['institution_id', 'created_at']);
            $table->index('proposed_by_id');
        });
        foreach (['kind_id' => 'proposal-kind/', 'status_id' => 'assertion-status/', 'element_id' => 'description-element/', 'agent_type_id' => 'agent-type/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER proposals_{$column}_vocabulary BEFORE INSERT OR UPDATE ON proposals FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
        DB::statement('ALTER TABLE proposals ADD CONSTRAINT proposals_confidence CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE proposals ADD CONSTRAINT proposals_link_whole CHECK ((linked_table IS NULL) = (linked_id IS NULL))');
        DB::statement('ALTER TABLE proposals ADD CONSTRAINT proposals_review_whole CHECK ((reviewed_by_id IS NULL) = (reviewed_at IS NULL))');
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_sources_list CHECK (jsonb_typeof(sources) = 'array')");

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'description', ['review-proposals']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
