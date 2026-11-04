<?php

// OpenRiC Workspace - preservation events and file formats (roadmap phase 15a), after Heratio's ahg-preservation
// (preservation_event, preservation_format, preservation_object_format): what was done to each file, when, by whom or
// what, and how it ended, as PREMIS 3 events; and each file's format as PRONOM identifies it, with the format's risk
// and the institution's action for it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECKED = [['preservation_events', 'event_type_id', 'premis-event-type'], ['preservation_events', 'outcome_id', 'premis-event-outcome'],
        ['preservation_events', 'agent_type_id', 'premis-agent-type'], ['file_formats', 'risk_id', 'format-risk'], ['file_formats', 'action_id', 'format-action']];

    public function up(): void
    {
        foreach (['premis-event-type', 'premis-event-outcome', 'premis-agent-type', 'format-risk', 'format-action'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        // PRONOM's formats, one row each, for every institution: a format is the same format wherever it is kept.
        Schema::create('file_formats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('puid')->unique();
            $table->text('name');
            $table->text('version')->nullable();
            $table->text('mime_type')->nullable();
            $table->text('format_class')->nullable();
            $table->foreignUuid('risk_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('action_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->boolean('is_preservation_format')->default(false);
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        Schema::table('digital_files', function (Blueprint $table) {
            $table->foreignUuid('format_id')->nullable()->constrained('file_formats')->nullOnDelete();
            $table->text('format_basis')->nullable();
            $table->text('format_warning')->nullable();
            $table->timestampTz('format_identified_at')->nullable();
        });
        // No foreign key to the file or its instantiation: the event outlives a file erased by disposal, as PREMIS asks.
        Schema::create('preservation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->uuid('digital_file_id')->nullable();
            $table->uuid('instantiation_id')->nullable();
            $table->uuid('record_resource_id')->nullable();
            // What the file was called when the event happened, for when it is gone.
            $table->text('file_label')->nullable();
            $table->foreignUuid('event_type_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('outcome_id')->constrained('concepts')->restrictOnDelete();
            $table->text('detail')->nullable();
            $table->text('outcome_detail')->nullable();
            $table->foreignUuid('agent_type_id')->constrained('concepts')->restrictOnDelete();
            $table->text('agent_name');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // The file an event made a new one from (a creation or migration), its PREMIS source object.
            $table->uuid('source_file_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['digital_file_id', 'occurred_at']);
            $table->index(['record_resource_id', 'occurred_at']);
            $table->index(['institution_id', 'occurred_at']);
        });
        foreach (self::CHECKED as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', 'https://workspace.openric.org/vocab/{$vocabulary}/')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preservation_events');
        Schema::table('digital_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('format_id');
            $table->dropColumn(['format_basis', 'format_warning', 'format_identified_at']);
        });
        Schema::dropIfExists('file_formats');
    }
};
