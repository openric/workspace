<?php

// OpenRiC Workspace - embargoes on units of description and their files, after Heratio's ahg-extended-rights embargo,
// embargo_exception and embargo_i18n (roadmap phase 13a, ADR 0015). An embargo is in force from its first day to its
// last (none: until lifted), unless lifted; its type and reason are vocabulary rows. Exceptions open it to an account,
// a role or an IP range, for a time. Heratio's embargo_audit is the audit log here.
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
        foreach (['embargo-type.ttl', 'embargo-reason.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }

        Schema::create('embargoes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('reason_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('note')->nullable();
            // What the public catalogue says where a description is shown and its files are not.
            $table->text('public_message')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->integer('notify_days_before')->nullable();
            $table->date('warned_on')->nullable();
            $table->timestampTz('ended_notice_at')->nullable();
            $table->timestampTz('lifted_at')->nullable();
            $table->foreignUuid('lifted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_reason')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['record_resource_id', 'starts_on']);
        });
        DB::statement('ALTER TABLE embargoes ADD CONSTRAINT embargoes_dates CHECK ((ends_on IS NULL OR ends_on >= starts_on) AND (notify_days_before IS NULL OR notify_days_before > 0))');

        Schema::create('embargo_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('embargo_id')->constrained('embargoes')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->ipAddress('ip_from')->nullable();
            $table->ipAddress('ip_to')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        // An exception is for exactly one of an account, a role or an IP range, and its range runs forward.
        DB::statement('ALTER TABLE embargo_exceptions ADD CONSTRAINT embargo_exceptions_one CHECK (
            (user_id IS NOT NULL)::int + (role_id IS NOT NULL)::int + (ip_from IS NOT NULL)::int = 1
            AND (ip_from IS NULL) = (ip_to IS NULL) AND (ip_from IS NULL OR ip_from::inet <= ip_to::inet)
            AND (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from))');
        foreach ([['type_id', 'embargo-type'], ['reason_id', 'embargo-reason']] as [$column, $vocabulary]) {
            DB::statement("CREATE TRIGGER embargoes_{$column}_vocabulary BEFORE INSERT OR UPDATE ON embargoes FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('embargo_exceptions');
        Schema::dropIfExists('embargoes');
    }
};
