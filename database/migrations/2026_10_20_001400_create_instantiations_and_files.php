<?php

// OpenRiC Workspace - instantiations of units of description and their digital files (rico:Instantiation; WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An instantiation is one carrier of a unit of description: a digital file,
// a paper copy, a microfilm (rico:isOrWasInstantiationOf). It is an entity
// with its own persistent IRI, title, values, dates and identifiers, a
// carrier type and an availability, marked deleted and never removed. A
// digital instantiation holds files, one for each use: the master as
// received, and the reference copy and thumbnail made from it. A file's
// SHA-256 is taken on upload and checked again by the fixity check; files
// live outside the web root and are delivered only through the Workspace.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const NAMED_BEFORE = "'record_resources', 'repositories', 'places', 'agents', 'activities'";

    private const NAMED = "'record_resources', 'repositories', 'places', 'agents', 'activities', 'instantiations'";

    public function up(): void
    {
        // The carrier-type, file-use and file-availability vocabularies, which the checks below name.
        foreach (['carrier-type.ttl', 'file-use.ttl', 'file-availability.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('instantiations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->foreignUuid('carrier_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('availability_id')->constrained('concepts')->restrictOnDelete();
            $table->integer('position')->default(0);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['record_resource_id', 'position']);
        });
        DB::statement('CREATE TRIGGER instantiations_iri_guard BEFORE UPDATE OR DELETE ON instantiations FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        foreach (['carrier_type_id' => 'carrier-type/', 'availability_id' => 'file-availability/'] as $column => $vocabulary) {
            DB::statement("CREATE TRIGGER instantiations_{$column}_vocabulary BEFORE INSERT OR UPDATE ON instantiations FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION instantiation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT institution_id FROM record_resources WHERE id = NEW.record_resource_id) IS DISTINCT FROM NEW.institution_id THEN
                    RAISE EXCEPTION 'an instantiation belongs to a unit of description of its institution' USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.record_resource_id IS DISTINCT FROM OLD.record_resource_id THEN
                    RAISE EXCEPTION 'an instantiation stays with its unit of description' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER instantiations_guard BEFORE INSERT OR UPDATE ON instantiations FOR EACH ROW EXECUTE FUNCTION instantiation_guard()');
        DB::statement('CREATE CONSTRAINT TRIGGER instantiations_has_authorised_name AFTER INSERT OR UPDATE ON instantiations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION keeps_authorised_name('.self::NAMED.')');
        $this->named(self::NAMED);

        Schema::create('digital_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('instantiation_id')->constrained('instantiations')->restrictOnDelete();
            $table->foreignUuid('use_id')->constrained('concepts')->restrictOnDelete();
            $table->text('original_name');
            $table->text('mime_type');
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('storage_path');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            // What scanned it for viruses and when; null when no scanner was installed.
            $table->text('scanned_with')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampTz('fixity_checked_at')->nullable();
            $table->timestampTz('fixity_failed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['instantiation_id', 'use_id']);
            $table->index('sha256');
        });
        DB::statement("ALTER TABLE digital_files ADD CONSTRAINT digital_files_sha256_hex CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE digital_files ADD CONSTRAINT digital_files_size CHECK (size_bytes >= 0)');
        DB::statement("CREATE TRIGGER digital_files_use_id_vocabulary BEFORE INSERT OR UPDATE ON digital_files FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('use_id', '".self::VOCAB."file-use/')");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION digital_file_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT institution_id FROM instantiations WHERE id = NEW.instantiation_id) IS DISTINCT FROM NEW.institution_id THEN
                    RAISE EXCEPTION 'a file belongs to an instantiation of its institution' USING ERRCODE = 'foreign_key_violation';
                END IF;
                -- What was received is never changed: a different file is a new upload.
                IF TG_OP = 'UPDATE' AND (NEW.sha256, NEW.size_bytes, NEW.storage_path, NEW.instantiation_id, NEW.use_id)
                        IS DISTINCT FROM (OLD.sha256, OLD.size_bytes, OLD.storage_path, OLD.instantiation_id, OLD.use_id) THEN
                    RAISE EXCEPTION 'a file''s content, place and use do not change; upload a new one' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER digital_files_guard BEFORE INSERT OR UPDATE ON digital_files FOR EACH ROW EXECUTE FUNCTION digital_file_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_files');
        DB::statement('DROP FUNCTION IF EXISTS digital_file_guard()');
        $this->named(self::NAMED_BEFORE);
        DB::statement('DROP TRIGGER IF EXISTS instantiations_has_authorised_name ON instantiations');
        Schema::dropIfExists('instantiations');
        DB::statement('DROP FUNCTION IF EXISTS instantiation_guard()');
    }

    private function named(string $tables): void
    {
        DB::statement('DROP TRIGGER IF EXISTS names_keep_authorised ON names');
        DB::statement("CREATE CONSTRAINT TRIGGER names_keep_authorised AFTER UPDATE OR DELETE ON names DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION keeps_authorised_name($tables)");
    }
};
