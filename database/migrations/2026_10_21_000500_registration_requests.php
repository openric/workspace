<?php

// OpenRiC Workspace - requests for an account, made by visitors and decided by an administrator.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// As Heratio's user_registration_request: a visitor gives a name, an email and
// a password (kept hashed) and why they want an account; an administrator
// approves it, which makes the account with that password, or rejects it.
// Heratio first verifies the email by a mailed link; the Workspace sends no
// mail yet, so a request goes straight to the administrators. Open only where
// the institution has turned registration on in Settings.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->boolean('registration_open')->default(false);
        });
        Schema::create('registration_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('name', 1024);
            $table->string('email', 255);
            $table->string('password');
            $table->text('reason')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->foreignUuid('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('account_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['institution_id', 'approved_at', 'rejected_at']);
        });
        DB::statement('ALTER TABLE registration_requests ADD CONSTRAINT registration_requests_one_decision CHECK (approved_at IS NULL OR rejected_at IS NULL)');
        // One undecided request per email.
        DB::statement('CREATE UNIQUE INDEX registration_requests_one_pending ON registration_requests (lower(email)) WHERE approved_at IS NULL AND rejected_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_requests');
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('registration_open');
        });
    }
};
