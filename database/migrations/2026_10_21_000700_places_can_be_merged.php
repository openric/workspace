<?php

// OpenRiC Workspace - a place can be merged into another, as an authority record can.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The same columns and checks as agents.merged_into_id: a merged place is marked
// deleted and names the place it was merged into, of the same institution, so its
// persistent IRI still leads somewhere.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->uuid('merged_into_id')->nullable();
            $table->foreign('merged_into_id')->references('id')->on('places')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE places ADD CONSTRAINT places_merged_is_deleted CHECK (merged_into_id IS NULL OR (deleted_at IS NOT NULL AND merged_into_id <> id))');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION place_merge_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.merged_into_id IS NOT NULL AND (SELECT institution_id FROM places WHERE id = NEW.merged_into_id) IS DISTINCT FROM NEW.institution_id THEN
                    RAISE EXCEPTION 'a place is merged into a place of its own institution' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER places_merge_guard BEFORE INSERT OR UPDATE ON places FOR EACH ROW EXECUTE FUNCTION place_merge_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS places_merge_guard ON places');
        DB::statement('DROP FUNCTION IF EXISTS place_merge_guard()');
        DB::statement('ALTER TABLE places DROP CONSTRAINT IF EXISTS places_merged_is_deleted');
        Schema::table('places', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
            $table->dropColumn('merged_into_id');
        });
    }
};
