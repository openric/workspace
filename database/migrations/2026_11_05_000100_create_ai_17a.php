<?php

// OpenRiC Workspace - the AI foundation (roadmap phase 17a), after Heratio's ahg-provenance-ai and ahg-inference-receipts:
// an institution's AI settings (off until it switches them on, and only once the installation has a gateway key), and
// one log of every call a model answered through the AI gateway: the service, the model and its version, what went in
// and came out (hashed, with an excerpt), the confidence, what it was about and who asked. The log is a chain, each row
// holding the hash of the one before and signed with the installation's Ed25519 key, as the audit log is, and it is
// append-only. A proposal a model made names its inference, and keeps the value its reviewer wrote, so every machine
// output can be traced to its proposal and its reviewer.
// Changed from Heratio: the inference record and its receipt are one row, in PostgreSQL; a reviewer's correction is the
// proposal's accepted value rather than a separate override table.
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
        foreach (['ai-service', 'job-type'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }

        Schema::create('ai_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->unique()->constrained('institutions')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // At most so many calls a calendar month; none is no limit beyond the gateway's own quota.
            $table->integer('monthly_limit')->nullable();
            // The vision models that read printed pages, as the gateway names them; none is the Workspace's default.
            $table->string('ocr_model', 200)->nullable();
            $table->string('ocr_fallback_model', 200)->nullable();
            $table->foreignUuid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE ai_settings ADD CONSTRAINT ai_settings_monthly_limit CHECK (monthly_limit IS NULL OR monthly_limit >= 0)');

        Schema::create('ai_inferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('service_id')->constrained('concepts')->restrictOnDelete();
            $table->string('model', 255);
            $table->string('model_version', 64)->default('unknown');
            $table->string('endpoint', 500);
            $table->char('input_hash', 64);
            $table->string('input_excerpt', 500)->nullable();
            $table->char('output_hash', 64)->nullable();
            $table->string('output_excerpt', 500)->nullable();
            $table->decimal('confidence', 6, 5)->nullable();
            // What it was about: a table and row, and the element or field it was for.
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('field', 100)->nullable();
            $table->integer('elapsed_ms')->nullable();
            $table->boolean('succeeded');
            $table->text('error')->nullable();
            // What else the model reported (strips flagged, its own scores), kept for the trace.
            $table->jsonb('details')->default('{}');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at', 6);
            // The receipt: the chain and its signature (ahg-inference-receipts).
            $table->bigInteger('seq')->unique();
            $table->char('prev_hash', 64);
            $table->char('entry_hash', 64)->unique();
            $table->text('signature');
            $table->string('kid', 64);
            $table->index(['subject_table', 'subject_id']);
            $table->index(['institution_id', 'occurred_at']);
        });
        DB::statement('ALTER TABLE ai_inferences ADD CONSTRAINT ai_inferences_confidence CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_inferences_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'the log of inferences is append-only; % is not allowed', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END $$
        SQL);
        DB::statement('CREATE TRIGGER ai_inferences_append_only BEFORE UPDATE OR DELETE ON ai_inferences FOR EACH ROW EXECUTE FUNCTION ai_inferences_append_only()');
        DB::statement('CREATE TRIGGER ai_inferences_no_truncate BEFORE TRUNCATE ON ai_inferences FOR EACH STATEMENT EXECUTE FUNCTION ai_inferences_append_only()');
        DB::statement("CREATE TRIGGER ai_inferences_service_id_vocabulary BEFORE INSERT ON ai_inferences FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('service_id', '".self::VOCAB."ai-service/')");

        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignUuid('inference_id')->nullable()->constrained('ai_inferences')->restrictOnDelete();
            // What the reviewer wrote on accepting it, which may differ from what was proposed.
            $table->text('accepted_value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inference_id');
            $table->dropColumn('accepted_value');
        });
        DB::statement('DROP TRIGGER IF EXISTS ai_inferences_no_truncate ON ai_inferences');
        DB::statement('DROP TRIGGER IF EXISTS ai_inferences_append_only ON ai_inferences');
        Schema::dropIfExists('ai_inferences');
        DB::statement('DROP FUNCTION IF EXISTS ai_inferences_append_only()');
        Schema::dropIfExists('ai_settings');
    }
};
