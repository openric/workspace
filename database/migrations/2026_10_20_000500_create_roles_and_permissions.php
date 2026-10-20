<?php

// OpenRiC Workspace - roles, their members and the permission matrix (ADR 0005).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Replaces account_roles and role_grants. An existing institution's roles and
// members are converted (App\Access\AccessMigration); the conversion is written
// to the audit log. Going back means restoring the dump deploy.sh takes before
// migrating, so down() refuses rather than half-undo.

use App\Access\AccessMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            // The account-role template it was made from; null for a role the institution invented.
            $table->foreignUuid('template_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            // Public and Signed in: cannot be removed or given a parent.
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
            $table->unique(['institution_id', 'template_id']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('roles')->restrictOnDelete();
        });
        DB::statement("CREATE TRIGGER roles_template_vocabulary BEFORE INSERT OR UPDATE ON roles FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('template_id', '".self::VOCAB."account-role/')");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION roles_hierarchy() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM roles p WHERE p.id = NEW.parent_id AND p.institution_id = NEW.institution_id) THEN
                    RAISE EXCEPTION 'the parent of a role must belong to the same institution' USING ERRCODE = 'check_violation';
                END IF;
                IF EXISTS (
                    WITH RECURSIVE ancestors(id) AS (
                        SELECT NEW.parent_id
                        UNION
                        SELECT r.parent_id FROM roles r JOIN ancestors a ON r.id = a.id WHERE r.parent_id IS NOT NULL
                    )
                    SELECT 1 FROM ancestors WHERE id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'a role cannot inherit from itself or from a role that inherits from it' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER roles_hierarchy BEFORE INSERT OR UPDATE OF parent_id ON roles FOR EACH ROW EXECUTE FUNCTION roles_hierarchy()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION roles_system_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.is_system THEN
                    RAISE EXCEPTION 'a system role cannot be removed' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN OLD;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER roles_system_guard BEFORE DELETE ON roles FOR EACH ROW EXECUTE FUNCTION roles_system_guard()');

        Schema::create('role_labels', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('language', 35);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->primary(['role_id', 'language']);
        });

        Schema::create('role_members', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('granted_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('granted_at')->useCurrent();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('role_translation_languages', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('language', 35);
            $table->primary(['role_id', 'language']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('kind_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('action_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('repository_id')->nullable()->constrained('repositories')->restrictOnDelete();
            $table->text('subject_table')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->boolean('is_grant');
            $table->timestampsTz();
            $table->index(['institution_id', 'kind_id', 'action_id']);
        });
        DB::statement('ALTER TABLE permissions ADD CONSTRAINT permissions_one_holder CHECK ((role_id IS NULL) <> (user_id IS NULL))');
        DB::statement('ALTER TABLE permissions ADD CONSTRAINT permissions_one_scope CHECK (NOT (repository_id IS NOT NULL AND subject_id IS NOT NULL))');
        DB::statement('ALTER TABLE permissions ADD CONSTRAINT permissions_subject_pair CHECK ((subject_table IS NULL) = (subject_id IS NULL))');
        // One cell per holder, action, kind and scope.
        DB::statement("CREATE UNIQUE INDEX permissions_one_cell ON permissions (COALESCE(role_id, user_id), kind_id, action_id, COALESCE(repository_id, '00000000-0000-0000-0000-000000000000'::uuid), COALESCE(subject_id, '00000000-0000-0000-0000-000000000000'::uuid))");
        DB::statement("CREATE TRIGGER permissions_kind_vocabulary BEFORE INSERT OR UPDATE ON permissions FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('kind_id', '".self::VOCAB."record-kind/')");
        DB::statement("CREATE TRIGGER permissions_action_vocabulary BEFORE INSERT OR UPDATE ON permissions FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('action_id', '".self::VOCAB."permission/')");

        app(AccessMigration::class)->run();

        Schema::drop('account_roles');
        Schema::drop('role_grants');
    }

    public function down(): void
    {
        throw new RuntimeException('Roles and permissions cannot be undone by migration; restore the dump deploy.sh took before this release.');
    }
};
