<?php

// OpenRiC Workspace - donor agreements, after Heratio's ahg-donor-manage and AtoM's ahgDonorAgreementPlugin
// (roadmap phase 9b; ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A donor agreement is an entity of its own kind with a persistent IRI, exported as a rico:Record whose documentary
// form type is its agreement type. Its number is its local identifier and its title and texts are description values
// (agreement elements). Its type, status, repository, the agreement it belongs to and the one it supersedes, its dates,
// its parties' names and its financial terms are columns, as Heratio keeps them. The donor is an authority record that
// created it in the donor role, the accessions it documents and the units of description it covers are relations.
// Its rights, restrictions, reminders and documents are rows. Heratio's donor_agreement_history is the audit log.

use App\Access\RoleService;
use App\Models\Institution;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const ENTITY_TABLES_BEFORE = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities', 'instantiations', 'containers', 'accessions'";

    private const ENTITY_TABLES = "'record_resources', 'repositories', 'places', 'concepts', 'agents', 'activities', 'instantiations', 'containers', 'accessions', 'agreements'";

    public function up(): void
    {
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::create('agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            // The repository that is party to it (Heratio's repository_id and institution_name).
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->restrictOnDelete();
            $table->date('agreed_on')->nullable();
            $table->date('effective_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->date('review_on')->nullable();
            $table->date('terminated_on')->nullable();
            $table->text('transfer_method')->nullable();
            $table->date('transferred_on')->nullable();
            $table->text('received_by')->nullable();
            $table->text('legal_representative')->nullable();
            $table->text('legal_representative_title')->nullable();
            $table->text('repository_representative')->nullable();
            $table->text('repository_representative_title')->nullable();
            $table->boolean('has_financial_terms')->default(false);
            $table->decimal('purchase_amount', 15, 2)->nullable();
            // ISO 4217.
            $table->char('currency', 3)->default('ZAR');
            $table->text('donor_signature_name')->nullable();
            $table->date('donor_signed_on')->nullable();
            $table->text('repository_signature_name')->nullable();
            $table->date('repository_signed_on')->nullable();
            $table->text('witness_name')->nullable();
            $table->date('witnessed_on')->nullable();
            $table->boolean('is_template')->default(false);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
        });
        // The agreement it is part of (Heratio's parent_agreement_id) and the one it replaces (supersedes_agreement_id).
        Schema::table('agreements', function (Blueprint $table) {
            $table->foreignUuid('parent_id')->nullable()->constrained('agreements')->nullOnDelete();
            $table->foreignUuid('supersedes_id')->nullable()->constrained('agreements')->nullOnDelete();
        });
        DB::statement('CREATE TRIGGER agreements_iri_guard BEFORE UPDATE OR DELETE ON agreements FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        DB::statement("ALTER TABLE agreements ADD CONSTRAINT agreements_currency CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE agreements ADD CONSTRAINT agreements_term CHECK (effective_on IS NULL OR expires_on IS NULL OR effective_on <= expires_on)');
        DB::statement('ALTER TABLE agreements ADD CONSTRAINT agreements_amount CHECK (purchase_amount IS NULL OR purchase_amount >= 0)');
        DB::statement('ALTER TABLE agreements ADD CONSTRAINT agreements_not_own_parent CHECK (parent_id <> id AND supersedes_id <> id)');

        $rows = function (string $name, callable $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->uuid('id')->primary();
                $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
                $table->foreignUuid('agreement_id')->constrained('agreements')->cascadeOnDelete();
                $columns($table);
                $table->timestampsTz();
            });
        };
        // Heratio's donor_agreement_right: what the institution may do, to which of digital, physical and metadata, when.
        $rows('agreement_rights', function (Blueprint $table) {
            $table->foreignUuid('right_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('permission_id')->constrained('concepts')->restrictOnDelete();
            $table->text('conditions')->nullable();
            $table->boolean('applies_to_digital')->default(true);
            $table->boolean('applies_to_physical')->default(true);
            $table->boolean('applies_to_metadata')->default(true);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('requires_donor_approval')->default(false);
            $table->boolean('requires_fee')->default(false);
            $table->text('fee_structure')->nullable();
            $table->text('note')->nullable();
        });
        // Heratio's donor_agreement_restriction: on all of the material or some, for a time or until released.
        $rows('agreement_restrictions', function (Blueprint $table) {
            $table->foreignUuid('restriction_type_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('applies_to_all')->default(true);
            $table->text('specific_materials')->nullable();
            $table->text('box_list')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('auto_release')->default(false);
            $table->date('release_on')->nullable();
            $table->text('release_trigger')->nullable();
            $table->boolean('can_be_overridden')->default(false);
            $table->text('override_authority')->nullable();
            $table->text('reason')->nullable();
            $table->text('legal_basis')->nullable();
            $table->foreignUuid('popia_category_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->boolean('data_subject_consent')->nullable();
            $table->smallInteger('security_clearance_level')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
        });
        // Heratio's donor_agreement_reminder: sent to its accounts, advance_days before its date, again if it recurs.
        $rows('agreement_reminders', function (Blueprint $table) {
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('subject');
            $table->text('description')->nullable();
            $table->date('remind_on');
            $table->integer('advance_days')->default(30);
            $table->foreignUuid('recurrence_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->date('recurrence_ends_on')->nullable();
            $table->foreignUuid('priority_id')->constrained('concepts')->restrictOnDelete();
            $table->boolean('notify_email')->default(true);
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->date('snoozed_until')->nullable();
            $table->text('action_required')->nullable();
            // The date it was last sent for, so one date is sent once.
            $table->date('sent_for')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_note')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['institution_id', 'remind_on']);
        });
        // Heratio's notification_recipients, as accounts of the institution: mail goes only to them.
        Schema::create('agreement_reminder_recipients', function (Blueprint $table) {
            $table->foreignUuid('reminder_id')->constrained('agreement_reminders')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['reminder_id', 'user_id']);
        });
        // Heratio's donor_agreement_document: a file kept by its SHA-256 through FileStore, with what it is.
        $rows('agreement_documents', function (Blueprint $table) {
            $table->foreignUuid('document_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('original_name');
            $table->text('storage_path');
            $table->text('mime_type');
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('scanned_with')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->date('document_date')->nullable();
            $table->text('version')->nullable();
            $table->boolean('is_signed')->default(false);
            $table->date('signed_on')->nullable();
            $table->text('signed_by')->nullable();
            // Only those who may change the agreement open a confidential document.
            $table->boolean('is_confidential')->default(false);
            $table->text('access_restriction')->nullable();
            $table->foreignUuid('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement("ALTER TABLE agreement_reminders ADD CONSTRAINT agreement_reminders_subject CHECK (btrim(subject) <> '')");
        DB::statement('ALTER TABLE agreement_reminders ADD CONSTRAINT agreement_reminders_advance CHECK (advance_days BETWEEN 0 AND 366)');
        DB::statement('ALTER TABLE agreement_rights ADD CONSTRAINT agreement_rights_dates CHECK (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on)');
        DB::statement('ALTER TABLE agreement_restrictions ADD CONSTRAINT agreement_restrictions_dates CHECK (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on)');
        DB::statement('ALTER TABLE agreement_restrictions ADD CONSTRAINT agreement_restrictions_clearance CHECK (security_clearance_level IS NULL OR security_clearance_level BETWEEN 0 AND 9)');
        DB::statement("ALTER TABLE agreement_documents ADD CONSTRAINT agreement_documents_sha256 CHECK (sha256 ~ '^[0-9a-f]{64}$')");

        foreach ([['agreements', 'type_id', 'agreement-type'], ['agreements', 'status_id', 'agreement-status'],
            ['agreement_rights', 'right_id', 'agreement-right'], ['agreement_rights', 'permission_id', 'agreement-permission'],
            ['agreement_restrictions', 'restriction_type_id', 'agreement-restriction-type'], ['agreement_restrictions', 'popia_category_id', 'popia-category'],
            ['agreement_reminders', 'type_id', 'reminder-type'], ['agreement_reminders', 'recurrence_id', 'reminder-recurrence'],
            ['agreement_reminders', 'priority_id', 'reminder-priority'], ['agreement_reminders', 'status_id', 'reminder-status'],
            ['agreement_documents', 'document_type_id', 'agreement-document-type']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        // An agreement takes part in relations: its donor created it (creation, in the donor role), it documents
        // accessions (documentation), and it covers, covers in part or refers to units of description.
        $this->entityTables(self::ENTITY_TABLES);
        $type = fn (string $notation) => DB::table('concepts')->where('iri', self::VOCAB."relation-type/$notation")->value('id');
        DB::table('relation_type_pairs')->insert([
            ['relation_type_id' => $type('creation'), 'source_kind' => 'agreement', 'target_kind' => 'agent'],
            ['relation_type_id' => $type('documentation'), 'source_kind' => 'agreement', 'target_kind' => 'accession'],
        ]);
        $af = json_decode(file_get_contents(lang_path('af.json')), true);
        foreach (['covers' => ['covers', 'is covered by'], 'partly-covers' => ['covers in part', 'is covered in part by'], 'refers-to' => ['refers to', 'is referred to by']] as $notation => [$forward, $inverse]) {
            $id = $type($notation);
            DB::table('relation_types')->insert(['concept_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('relation_type_pairs')->insert(['relation_type_id' => $id, 'source_kind' => 'agreement', 'target_kind' => 'record']);
            foreach (['en' => [$forward, $inverse], 'af' => [$af[$forward], $af[$inverse]]] as $language => [$from, $to]) {
                DB::table('relation_type_phrases')->insert(['id' => (string) Str::uuid7(), 'relation_type_id' => $id, 'language' => $language,
                    'forward' => $from, 'inverse' => $to, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'agreement'));
        }
    }

    public function down(): void
    {
        DB::table('relation_types')->whereIn('concept_id', DB::table('concepts')->whereIn('iri', array_map(fn ($n) => self::VOCAB."relation-type/$n", ['covers', 'partly-covers', 'refers-to']))->select('id'))->delete();
        DB::table('relation_type_pairs')->where('source_kind', 'agreement')->delete();
        $this->entityTables(self::ENTITY_TABLES_BEFORE);
        foreach (['agreement_documents', 'agreement_reminder_recipients', 'agreement_reminders', 'agreement_restrictions', 'agreement_rights', 'agreements'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Which tables relations, and the institution check on their ends, accept. */
    private function entityTables(string $tables): void
    {
        DB::statement('ALTER TABLE relations DROP CONSTRAINT relations_entity_tables');
        DB::statement("ALTER TABLE relations ADD CONSTRAINT relations_entity_tables CHECK (source_table IN ($tables) AND target_table IN ($tables) AND (context_table IS NULL OR context_table IN ($tables)))");
        DB::statement('DROP TRIGGER relations_entities ON relations');
        DB::statement("CREATE TRIGGER relations_entities BEFORE INSERT OR UPDATE ON relations FOR EACH ROW EXECUTE FUNCTION relation_entities_in_institution($tables)");
    }
};
