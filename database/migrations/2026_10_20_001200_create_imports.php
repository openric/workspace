<?php

// OpenRiC Workspace - imports of archival description and the keys that make them repeatable (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An import keeps the file it read, the choices it was made with and its
// report: what each unit became, every field that was rejected, left out or
// ambiguous, and, once committed, the reconciliation. It is a dry run until
// it is committed. An import key ties a unit in a source (an AtoM legacyId,
// an EAD component, a row) to the unit it became, so importing the same
// source again updates instead of duplicating.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->text('format');
            $table->text('source');
            $table->text('file_name');
            $table->char('file_sha256', 64);
            $table->text('content');
            $table->text('language');
            $table->foreignUuid('repository_id')->constrained('repositories')->restrictOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('record_resources')->restrictOnDelete();
            $table->jsonb('options');
            $table->jsonb('report')->nullable();
            $table->timestampTz('committed_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'created_at']);
        });

        Schema::create('import_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('source');
            $table->text('key');
            $table->foreignUuid('record_resource_id')->constrained('record_resources')->restrictOnDelete();
            $table->foreignUuid('import_id')->constrained('imports')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['institution_id', 'source', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_keys');
        Schema::dropIfExists('imports');
    }
};
