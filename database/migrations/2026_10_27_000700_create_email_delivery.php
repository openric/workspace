<?php

// OpenRiC Workspace - email delivery: bounces, the sent-mail log, and the hold on an address that bounces (roadmap phase 7).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's ahg_email_bounce, ahg_sent_email and user.email_bounced_at (heratio-dev
// migrations 2026_05_25_020000 and 030000), with the bounce type and the message status
// as concepts. A bounce is kept with the provider's payload; the hold is on the account,
// so every account with the address is held. The sent-mail log holds no message body.

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
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('email_bounced_at')->nullable();
        });
        Schema::create('email_bounces', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255);
            $table->foreignUuid('type_id')->constrained('concepts')->restrictOnDelete();
            $table->string('subtype', 80)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('message_id', 255)->nullable();
            $table->string('provider', 40)->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('email');
            $table->index('occurred_at');
        });
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->string('mailable', 255);
            $table->string('recipient_email', 255);
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 512)->nullable();
            $table->string('locale', 35)->nullable();
            // The id stamped on the message, so the sent event finds its row again.
            $table->uuid('audit_id')->nullable()->unique();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->text('error')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['institution_id', 'created_at']);
            $table->index('recipient_email');
        });
        DB::statement("CREATE TRIGGER email_bounces_type_id_vocabulary BEFORE INSERT OR UPDATE ON email_bounces FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('type_id', '".self::VOCAB."email-bounce-type/')");
        DB::statement("CREATE TRIGGER sent_emails_status_id_vocabulary BEFORE INSERT OR UPDATE ON sent_emails FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('status_id', '".self::VOCAB."email-status/')");
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
        Schema::dropIfExists('email_bounces');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_bounced_at');
        });
    }
};
