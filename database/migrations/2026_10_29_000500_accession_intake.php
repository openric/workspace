<?php

// OpenRiC Workspace - the intake of accessions, after PSIS's ahgAccessionManagePlugin AccessionIntakeService and
// Heratio's accession_v2, accession_intake_checklist, accession_intake_template, accession_timeline,
// accession_attachment, accession_numbering_sequence and accession_config (roadmap phase 9c; ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An accession's intake status, priority, assignee and the times it was submitted, accepted or rejected are columns of
// the accession (accession_v2 is one to one). Its checklist, timeline and attachments are rows. Checklist templates
// and numbering sequences are the institution's, and the accession settings are columns of the institution.

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
        $concept = fn (string $vocabulary, string $notation) => DB::table('concepts')->where('iri', self::VOCAB."$vocabulary/$notation")->value('id');

        Schema::table('accessions', function (Blueprint $table) {
            $table->foreignUuid('intake_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('intake_priority_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('intake_notes')->nullable();
        });
        DB::table('accessions')->update(['intake_status_id' => $concept('intake-status', 'draft'), 'intake_priority_id' => $concept('intake-priority', 'normal')]);
        DB::statement('ALTER TABLE accessions ALTER COLUMN intake_status_id SET NOT NULL, ALTER COLUMN intake_priority_id SET NOT NULL');

        Schema::create('accession_checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('accession_id')->constrained('accessions')->cascadeOnDelete();
            $table->text('label');
            $table->boolean('is_completed')->default(false);
            $table->foreignUuid('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });
        // A template's items are labels, one per line, in its order (Heratio's items JSON of label and sort_order).
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->jsonb('items');
            $table->timestampsTz();
        });
        Schema::create('accession_timeline', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('accession_id')->constrained('accessions')->cascadeOnDelete();
            $table->foreignUuid('event_type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['accession_id', 'occurred_at']);
        });
        Schema::create('accession_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('accession_id')->constrained('accessions')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('concepts')->restrictOnDelete();
            $table->text('original_name');
            $table->text('storage_path');
            $table->text('mime_type');
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('scanned_with')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->text('description')->nullable();
            $table->foreignUuid('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        // A numbering sequence for accessions (by repository, or the institution's own) or agreements (by type, or all).
        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->cascadeOnDelete();
            // An agreement type, when the mask numbers each type on its own ({TYPE}).
            $table->foreignUuid('type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->integer('last_sequence')->default(0);
            $table->integer('last_year')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX numbering_sequences_scope ON numbering_sequences (institution_id, kind, repository_id, type_id) NULLS NOT DISTINCT');
        DB::statement("ALTER TABLE numbering_sequences ADD CONSTRAINT numbering_sequences_kind CHECK (kind IN ('accession', 'agreement'))");
        DB::statement("ALTER TABLE accession_checklist_items ADD CONSTRAINT accession_checklist_items_label CHECK (btrim(label) <> '')");
        DB::statement("ALTER TABLE checklist_templates ADD CONSTRAINT checklist_templates_name CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE accession_attachments ADD CONSTRAINT accession_attachments_sha256 CHECK (sha256 ~ '^[0-9a-f]{64}$')");

        // Heratio's accession_config, as the institution's: the numbering masks, assigning a new accession to whoever
        // records it, whether accepting needs an agreement documenting it, the default priority and checklist template.
        Schema::table('institutions', function (Blueprint $table) {
            $table->text('accession_numbering_mask')->default('{YEAR}-{SEQ:5}');
            $table->text('agreement_numbering_mask')->default('{TYPE}-{YEAR}-{SEQ:3}');
            $table->boolean('accession_auto_assign')->default(false);
            $table->boolean('accession_requires_agreement')->default(false);
            $table->foreignUuid('accession_default_priority_id')->nullable()->constrained('concepts')->nullOnDelete();
            $table->foreignUuid('accession_checklist_template_id')->nullable()->constrained('checklist_templates')->nullOnDelete();
        });

        foreach ([['accessions', 'intake_status_id', 'intake-status'], ['accessions', 'intake_priority_id', 'intake-priority'],
            ['accession_timeline', 'event_type_id', 'timeline-event'], ['accession_attachments', 'category_id', 'attachment-category'],
            ['institutions', 'accession_default_priority_id', 'intake-priority']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS institutions_accession_default_priority_id_vocabulary ON institutions');
        DB::statement('DROP TRIGGER IF EXISTS accessions_intake_status_id_vocabulary ON accessions');
        DB::statement('DROP TRIGGER IF EXISTS accessions_intake_priority_id_vocabulary ON accessions');
        Schema::table('institutions', fn (Blueprint $table) => $table->dropConstrainedForeignId('accession_checklist_template_id'));
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accession_default_priority_id');
            $table->dropColumn(['accession_numbering_mask', 'agreement_numbering_mask', 'accession_auto_assign', 'accession_requires_agreement']);
        });
        foreach (['numbering_sequences', 'accession_attachments', 'accession_timeline', 'checklist_templates', 'accession_checklist_items'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('accessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('intake_status_id');
            $table->dropConstrainedForeignId('intake_priority_id');
            $table->dropConstrainedForeignId('assigned_to_id');
            $table->dropColumn(['submitted_at', 'accepted_at', 'rejected_at', 'rejection_reason', 'intake_notes']);
        });
    }
};
