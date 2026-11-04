<?php

// OpenRiC Workspace - OAI-PMH harvest and the union catalogue (roadmap phase 16b), after Heratio's ahg-federation: a peer
// harvested on its interval (federation_peer's harvest columns), each run kept (federation_harvest_session) with what it
// did to each record (federation_harvest_log), and each unit it took in tied to the record it came from (Heratio's
// federation provenance properties, as a table); and the union catalogue, its members (federation_member) and their
// records (federation_union_record).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECKED = [['federation_peers', 'last_harvest_status_id', 'harvest-status'], ['federation_harvest_sessions', 'status_id', 'harvest-status'],
        ['federation_harvest_log', 'action_id', 'harvest-action']];

    public function up(): void
    {
        foreach (['harvest-status', 'harvest-action'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::table('federation_peers', function (Blueprint $table) {
            $table->text('metadata_prefix')->nullable();
            $table->text('default_set')->nullable();
            $table->integer('harvest_interval_hours')->nullable();
            $table->foreignUuid('harvest_repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->timestampTz('last_harvest_at')->nullable();
            $table->foreignUuid('last_harvest_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->integer('last_harvest_records')->nullable();
            // A connector's own settings: DSpace's REST base, Alma's SRU address and record link (Heratio's federation_peer.config).
            $table->jsonb('config')->nullable();
        });
        Schema::create('federation_harvest_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peer_id')->constrained('federation_peers')->cascadeOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('metadata_prefix');
            $table->text('harvest_from')->nullable();
            $table->text('harvest_until')->nullable();
            $table->text('harvest_set')->nullable();
            $table->boolean('is_full_harvest')->default(false);
            foreach (['records_total', 'records_created', 'records_updated', 'records_deleted', 'records_skipped', 'records_errors'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->text('error_message')->nullable();
            $table->foreignUuid('initiated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->index(['peer_id', 'started_at']);
        });
        Schema::create('federation_harvested_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peer_id')->constrained('federation_peers')->cascadeOnDelete();
            $table->foreignUuid('record_resource_id')->unique()->constrained('record_resources')->cascadeOnDelete();
            $table->text('source_identifier');
            $table->text('datestamp')->nullable();
            $table->text('metadata_format');
            $table->timestampTz('first_harvested_at');
            $table->timestampTz('last_harvested_at');
            $table->unique(['peer_id', 'source_identifier']);
        });
        Schema::create('federation_harvest_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('peer_id')->constrained('federation_peers')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('federation_harvest_sessions')->cascadeOnDelete();
            $table->uuid('record_resource_id')->nullable();
            $table->text('source_identifier');
            $table->text('metadata_format');
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestampTz('harvested_at');
            $table->index(['peer_id', 'harvested_at']);
        });
        Schema::create('federation_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            // Its public catalogue, whose union records are fetched from <base_url>/union/records; none for the institution itself.
            $table->text('base_url')->nullable();
            $table->text('contact')->nullable();
            $table->text('share_scope')->nullable();
            $table->boolean('is_self')->default(false);
            $table->boolean('is_enabled')->default(false);
            $table->timestampTz('last_fetched_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX federation_members_one_self ON federation_members (institution_id) WHERE is_self');
        Schema::create('federation_union_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained('federation_members')->cascadeOnDelete();
            $table->text('record_ref');
            $table->text('title');
            $table->text('level')->nullable();
            $table->text('dates')->nullable();
            $table->text('repository')->nullable();
            $table->text('url')->nullable();
            $table->timestampTz('indexed_at');
            $table->unique(['member_id', 'record_ref']);
        });
        DB::statement("CREATE INDEX federation_union_records_title ON federation_union_records USING gin (to_tsvector('simple', title))");
        foreach (self::CHECKED as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        foreach (['federation_union_records', 'federation_members', 'federation_harvest_log', 'federation_harvested_records', 'federation_harvest_sessions'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('federation_peers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('harvest_repository_id');
            $table->dropConstrainedForeignId('last_harvest_status_id');
            $table->dropColumn(['metadata_prefix', 'default_set', 'harvest_interval_hours', 'last_harvest_at', 'last_harvest_records', 'config']);
        });
    }
};
