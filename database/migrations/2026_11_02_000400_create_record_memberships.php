<?php

// OpenRiC Workspace - a unit of description in several record sets at once, as RiC-O 1.1's rico:Proxy (roadmap phase 24).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A unit keeps one parent (record_resources.parent_id): the arrangement that gives its reference code, its holder and
// what it inherits. The design review of 2 November 2026 (scenario E) asks that a record described in two record sets
// keep both, each with its own order, and Dr Johan Pieterse chose on 2 November 2026 to allow several parents. Each
// further membership is a proxy, as RiC-O 1.1 models it: its own IRI, standing for the unit in that record set
// (rico:proxyFor, rico:proxyIn), with the unit's place in that set's order.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('record_set_id')->constrained('record_resources')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->text('note')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            // A proxy's IRI is persistent: a membership ended is marked so, not deleted.
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['record_set_id', 'position']);
        });
        DB::statement('ALTER TABLE record_memberships ADD CONSTRAINT record_memberships_not_itself CHECK (record_resource_id <> record_set_id)');
        DB::statement('CREATE UNIQUE INDEX record_memberships_current ON record_memberships (record_resource_id, record_set_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE TRIGGER record_memberships_iri_guard BEFORE UPDATE OR DELETE ON record_memberships FOR EACH ROW EXECUTE FUNCTION persistent_iri_guard()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION record_membership_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM record_resources WHERE id = NEW.record_resource_id AND institution_id = NEW.institution_id)
                   OR NOT EXISTS (SELECT 1 FROM record_resources WHERE id = NEW.record_set_id AND institution_id = NEW.institution_id) THEN
                    RAISE EXCEPTION 'a unit and the record set it is also in belong to the membership''s institution' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER record_memberships_guard BEFORE INSERT OR UPDATE ON record_memberships FOR EACH ROW EXECUTE FUNCTION record_membership_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('record_memberships');
        DB::statement('DROP FUNCTION IF EXISTS record_membership_guard()');
    }
};
