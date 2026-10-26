<?php

// OpenRiC Workspace - rights and Local Contexts Labels on records and instantiations (WS-007, roadmap phase 2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's rights_record, rights_grant (ahg-extended-rights) and icip_tk_label
// (ahg-icip), on the Workspace's model. A right is a rico:Rule with its own persistent
// IRI, held by a unit of description or an instantiation: its basis (PREMIS rightsBasis),
// a RightsStatements.org statement and a Creative Commons licence, the copyright status,
// jurisdiction and dates, the statute cited, and its holder, an authority record
// (rico:hasOrHadIntellectualPropertyRightsHolder). Its grants say what it allows,
// disallows or conditions (PREMIS acts). A community label is a Traditional Knowledge
// or Biocultural Label of Local Contexts that a community, or the institution for it,
// applies. Every list is a vocabulary; every change is audited.

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

        Schema::create('rights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('basis_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('statement_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('licence_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('copyright_status_id')->nullable()->constrained('concepts')->restrictOnDelete();
            // ISO 3166-1 alpha-2, such as ZA.
            $table->char('jurisdiction', 2)->nullable();
            $table->date('determined_on')->nullable();
            // A statute's citation, a licence's terms or a policy's identifier.
            $table->text('citation')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignUuid('holder_id')->nullable()->constrained('agents')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->string('note_language', 35)->nullable();
            $table->timestampsTz();
            // A right is marked deleted, never removed, so its IRI keeps resolving.
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['subject_table', 'subject_id']);
            $table->index('holder_id');
        });
        DB::statement("ALTER TABLE rights ADD CONSTRAINT rights_subject CHECK (subject_table IN ('record_resources', 'instantiations'))");
        DB::statement("ALTER TABLE rights ADD CONSTRAINT rights_jurisdiction CHECK (jurisdiction ~ '^[A-Z]{2}$')");
        DB::statement('ALTER TABLE rights ADD CONSTRAINT rights_dates CHECK (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on)');
        DB::statement('ALTER TABLE rights ADD CONSTRAINT rights_note_language CHECK ((note IS NULL) = (note_language IS NULL))');
        DB::statement('CREATE TRIGGER rights_iri_guard BEFORE UPDATE OR DELETE ON rights FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');

        Schema::create('rights_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('right_id')->constrained('rights')->cascadeOnDelete();
            $table->foreignUuid('act_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('restriction_id')->constrained('concepts')->restrictOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['right_id', 'act_id']);
        });
        DB::statement('ALTER TABLE rights_grants ADD CONSTRAINT rights_grants_dates CHECK (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on)');

        Schema::create('community_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('label_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('community_id')->nullable()->constrained('agents')->restrictOnDelete();
            $table->foreignUuid('applied_by_id')->constrained('concepts')->restrictOnDelete();
            // The project of the Local Contexts Hub the Label comes from, when it does.
            $table->text('local_contexts_project')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['subject_table', 'subject_id', 'label_id']);
        });
        DB::statement("ALTER TABLE community_labels ADD CONSTRAINT community_labels_subject CHECK (subject_table IN ('record_resources', 'instantiations'))");

        foreach ([['rights', 'basis_id', 'rights-basis/'], ['rights', 'statement_id', 'rights-statement/'], ['rights', 'licence_id', 'licence/'],
            ['rights', 'copyright_status_id', 'copyright-status/'], ['rights_grants', 'act_id', 'rights-act/'], ['rights_grants', 'restriction_id', 'rights-restriction/'],
            ['community_labels', 'label_id', 'tk-label/'], ['community_labels', 'applied_by_id', 'label-applier/']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
        // A Label, not one of the two groups of Labels.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION community_label_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT broader_id FROM concepts WHERE id = NEW.label_id) IS NULL THEN
                    RAISE EXCEPTION 'a community label is a Traditional Knowledge or Biocultural Label, not a group of them' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER community_labels_guard BEFORE INSERT OR UPDATE ON community_labels FOR EACH ROW EXECUTE FUNCTION community_label_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('community_labels');
        Schema::dropIfExists('rights_grants');
        Schema::dropIfExists('rights');
        DB::statement('DROP FUNCTION IF EXISTS community_label_guard()');
    }
};
