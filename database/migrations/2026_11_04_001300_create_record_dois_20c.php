<?php

// OpenRiC Workspace - DOIs for units of description (roadmap phase 20c), after Heratio's ahg-doi-manage: how an
// institution mints them (its DataCite account is the one its research services already keep), each unit's DOI and its
// state at DataCite, the queue of what is to be asked of DataCite with its attempts, the log of what was asked, a unit's
// funding references, and the usage and citation events reported to DataCite. DOI, ORCID iD, ISNI and ROR ID become
// identifier types, so a minted DOI is one of its unit's identifiers.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['identifier-type', 'doi-action', 'doi-queue-status', 'datacite-resource-type', 'funder-identifier-type', 'datacite-event-relation', 'datacite-event-status'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }
        Schema::create('doi_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->unique()->constrained('institutions')->cascadeOnDelete();
            // Mint a DOI for a unit when it is published, of these levels (empty: every level), and only if it has a file.
            $table->boolean('auto_mint')->default(false);
            $table->jsonb('auto_mint_level_ids')->default('[]');
            $table->boolean('require_digital_object')->default(false);
            $table->text('default_publisher')->nullable();
            $table->foreignUuid('default_resource_type_id')->nullable()->constrained('concepts')->nullOnDelete();
            // The DOI's suffix, from {repository_code}, {reference_code}, {year} and {id}.
            $table->text('suffix_pattern')->default('{repository_code}/{year}/{id}');
            // Usage and citation events reported to DataCite, with a token from DataCite Fabrica (kept encrypted).
            $table->boolean('events_enabled')->default(false);
            $table->text('events_token')->nullable();
            $table->timestampsTz();
        });
        Schema::create('record_dois', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->text('doi')->unique();
            $table->foreignUuid('state_id')->constrained('concepts')->restrictOnDelete();
            // Registered with DataCite's test service rather than its production one.
            $table->boolean('test')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->jsonb('datacite_response')->nullable();
            $table->foreignUuid('minted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('minted_at')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'state_id']);
        });
        DB::statement("ALTER TABLE record_dois ADD CONSTRAINT record_dois_doi CHECK (doi ~ '^10\\.[0-9]{4,9}/\\S+$')");
        Schema::create('doi_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            // Lower runs first.
            $table->integer('priority')->default(100);
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->text('last_error')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('scheduled_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status_id', 'scheduled_at', 'priority']);
        });
        Schema::create('doi_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('record_doi_id')->nullable()->constrained('record_dois')->nullOnDelete();
            $table->uuid('record_resource_id')->nullable();
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('succeeded');
            $table->text('state_before')->nullable();
            $table->text('state_after')->nullable();
            $table->jsonb('details')->default('{}');
            $table->foreignUuid('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('performed_at');
            $table->index(['institution_id', 'performed_at']);
        });
        Schema::create('record_funding', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->text('funder_name');
            $table->text('funder_identifier')->nullable();
            $table->foreignUuid('funder_identifier_type_id')->nullable()->constrained('concepts')->nullOnDelete();
            $table->text('award_number')->nullable();
            $table->text('award_uri')->nullable();
            $table->text('award_title')->nullable();
            $table->timestampsTz();
            $table->index('record_resource_id');
        });
        Schema::create('datacite_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // sha256 of subject, relation, object and source, so one event is reported once.
            $table->char('dedupe_hash', 64)->unique();
            $table->text('subj_doi');
            $table->foreignUuid('relation_id')->constrained('concepts')->restrictOnDelete();
            $table->text('obj_id');
            $table->text('obj_id_type')->default('url');
            $table->text('source_id');
            $table->jsonb('payload')->nullable();
            $table->smallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'status_id']);
        });
        foreach ([['doi_settings', 'default_resource_type_id', 'datacite-resource-type'], ['record_dois', 'state_id', 'doi-state'], ['doi_queue', 'action_id', 'doi-action'],
            ['doi_queue', 'status_id', 'doi-queue-status'], ['doi_log', 'action_id', 'doi-action'], ['record_funding', 'funder_identifier_type_id', 'funder-identifier-type'],
            ['datacite_events', 'relation_id', 'datacite-event-relation'], ['datacite_events', 'status_id', 'datacite-event-status']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['datacite_events', 'record_funding', 'doi_log', 'doi_queue', 'record_dois', 'doi_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
