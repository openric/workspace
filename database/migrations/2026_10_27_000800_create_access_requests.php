<?php

// OpenRiC Workspace - requests for files available on request (roadmap phase 7; WS-007, WS-011).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's access_request with its scope row (ahg-access-request): who asks, for which
// instantiation, why and how soon; who reviewed it, when, with what note; and, once
// approved, until when the requester may open its files. Status and urgency are concepts.
// Heratio's access_request_log is the audit log here (AccessRequest is audited), and its
// access_request_approver table is the review-access-requests permission, granted to the
// default roles that hold it in config workspace.default_roles.

use App\Access\RoleService;
use App\Models\Institution;
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
        Schema::create('access_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('instantiation_id')->constrained('instantiations')->restrictOnDelete();
            $table->text('reason');
            $table->text('justification')->nullable();
            $table->foreignUuid('urgency_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'created_at']);
            $table->index(['user_id', 'instantiation_id']);
        });
        foreach ([['urgency_id', 'access-request-urgency/'], ['status_id', 'access-request-status/']] as [$column, $vocabulary]) {
            DB::statement("CREATE TRIGGER access_requests_{$column}_vocabulary BEFORE INSERT OR UPDATE ON access_requests FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB.$vocabulary."')");
        }
        DB::statement('ALTER TABLE access_requests ADD CONSTRAINT access_requests_review_whole CHECK ((reviewed_by_id IS NULL) = (reviewed_at IS NULL))');

        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => app(RoleService::class)->grantKind($institution, 'description', ['review-access-requests']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
