<?php

// OpenRiC Workspace - the catalogue's assistant and AI compliance (roadmap phase 17f), after Heratio's ahg-ai-chatbot and
// ahg-ai-compliance: conversations and their messages, each answer with its sources, the receipt of its inference and
// what the guardrails found, and a reader's flag with its review; the register of the institution's AI systems (one per
// AI service it uses), each with its purpose, risk class, lifecycle, role, the human oversight it is under and whether it
// is halted; the models seen in the log of inferences, with what is known of them; the risks of each system and their
// incidents; and the attestations of those who review AI output. Every class, status, level and role is a vocabulary row.
// Changed from Heratio: an AI system's oversight policy is kept on the system (Heratio's ai_oversight_policy); reviewers'
// decisions are the proposals they accept and reject (the Workspace's review queue), not a table of their own; the log
// of inferences is the one phase 17a keeps, chained and signed.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai-service', 'ai-risk-class', 'ai-lifecycle', 'ai-role', 'risk-level', 'ai-risk-status', 'ai-risk-use', 'chat-role', 'chat-review'] as $vocabulary) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$vocabulary.ttl")));
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            // Whether the public catalogue offers the assistant (staff always may, while AI is on); off until chosen.
            $table->boolean('chatbot_public')->default(false);
            // How long a conversation is kept after its last message, then deleted.
            $table->unsignedSmallInteger('chatbot_retention_days')->default(90);
            // The institution's own instructions to the assistant, in place of Heratio's.
            $table->text('chatbot_instructions')->nullable();
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // A visitor's conversation is theirs by a token kept in their session, of which only the hash is kept.
            $table->char('token_hash', 64)->nullable()->unique();
            $table->boolean('public')->default(true);
            $table->string('language', 35)->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();
            $table->index(['institution_id', 'last_message_at']);
        });
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('concepts')->restrictOnDelete();
            $table->text('content');
            // The records an answer was given from: [{label, url}], numbered as the answer cites them.
            $table->json('sources')->nullable();
            $table->foreignUuid('inference_id')->nullable()->constrained('ai_inferences')->nullOnDelete();
            // What the guardrails found: personal information masked in the question, how far the answer is grounded.
            $table->json('guardrail')->nullable();
            $table->timestampTz('flagged_at')->nullable();
            $table->text('flag_note')->nullable();
            $table->foreignUuid('review_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['conversation_id', 'created_at']);
        });

        // Heratio's ai_system with its ai_oversight_policy: one per AI service an institution uses.
        Schema::create('ai_systems', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('service_id')->constrained('concepts')->restrictOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->text('purpose')->nullable();
            $table->string('provider', 255)->nullable();
            $table->foreignUuid('role_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('risk_class_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('lifecycle_id')->constrained('concepts')->restrictOnDelete();
            $table->text('deployment_context')->nullable();
            $table->text('human_oversight')->nullable();
            $table->string('owner', 255)->nullable();
            $table->date('last_review_date')->nullable();
            $table->date('next_review_date')->nullable();
            $table->boolean('requires_human_review')->default(true);
            $table->boolean('dual_review_required')->default(false);
            $table->string('automation_bias_text', 512)->nullable();
            $table->timestampTz('halted_at')->nullable();
            $table->string('halted_reason', 255)->nullable();
            $table->foreignUuid('halted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['institution_id', 'service_id']);
        });

        // Heratio's ai_model_registry: the models seen in the log of inferences, and what is known of them.
        Schema::create('ai_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('service_id')->constrained('concepts')->restrictOnDelete();
            $table->string('model', 255);
            $table->string('model_version', 64)->default('unknown');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->unsignedInteger('calls')->default(0);
            $table->timestampTz('retired_at')->nullable();
            $table->text('training_data_summary')->nullable();
            $table->text('known_limits')->nullable();
            $table->text('intended_purpose')->nullable();
            $table->timestampsTz();
            $table->unique(['institution_id', 'service_id', 'model', 'model_version']);
        });

        Schema::create('ai_risks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('system_id')->constrained('ai_systems')->cascadeOnDelete();
            $table->string('description', 512);
            $table->foreignUuid('severity_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('likelihood_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('use_id')->constrained('concepts')->restrictOnDelete();
            $table->string('affected_group', 255)->nullable();
            $table->text('mitigation')->nullable();
            $table->foreignUuid('residual_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('status_id')->constrained('concepts')->restrictOnDelete();
            $table->timestampTz('last_reviewed_at')->nullable();
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('ai_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('system_id')->constrained('ai_systems')->cascadeOnDelete();
            $table->foreignUuid('risk_id')->nullable()->constrained('ai_risks')->nullOnDelete();
            $table->foreignUuid('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->foreignUuid('severity_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('inference_id')->nullable()->constrained('ai_inferences')->nullOnDelete();
            // Raised by the nightly monitor, not a person (workspace:ai:monitor).
            $table->string('raised_by', 32)->default('person');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ai_attestations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('version', 16);
            $table->timestampTz('attested_at');
            $table->timestampTz('expires_at');
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        foreach (['ai_attestations', 'ai_incidents', 'ai_risks', 'ai_models', 'ai_systems', 'chat_messages', 'chat_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('ai_settings', fn (Blueprint $table) => $table->dropColumn(['chatbot_public', 'chatbot_retention_days', 'chatbot_instructions']));
    }
};
