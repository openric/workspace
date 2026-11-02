<?php

// OpenRiC Workspace - research services, part 19h (roadmap phase 19, ADR 0017): funding, grant applications and the
// calls a researcher watches, data management plans, ethics approvals, signs of impact, replication packs, DOIs at
// DataCite and ORCID links, after Heratio's ahg-research: research_funding, research_grant_draft,
// research_grant_section, research_grant_call, research_dmp, research_dmp_section, research_ethics,
// research_impact_signal, research_replication_log and the ORCID and DOI parts of research_researcher and
// research_project. The sections a grant or plan template starts with are rows, not code (Heratio's grant template
// dropdown metadata and DmpService::MADMP_SECTIONS).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    private const VOCABULARIES = ['project-activity-type', 'funder-type', 'funding-status', 'currency', 'grant-template', 'grant-draft-status', 'grant-call-status', 'dmp-template', 'dmp-status',
        'ethics-approval-type', 'ethics-status', 'consent-basis', 'data-sensitivity', 'doi-state', 'impact-signal-type'];

    public function up(): void
    {
        foreach (self::VOCABULARIES as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        $concept = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->constrained('concepts')->restrictOnDelete();
        $who = fn (Blueprint $table, string $column) => $table->foreignUuid($column)->nullable()->constrained('users')->nullOnDelete();
        $project = fn (Blueprint $table) => $table->foreignUuid('project_id')->constrained('research_projects')->cascadeOnDelete();
        $sections = function (Blueprint $table) {
            $table->text('section_key');
            $table->text('label');
            $table->text('hint')->nullable();
            $table->text('body')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        };

        // The sections a grant application or data management plan starts with, by its template.
        Schema::create('research_template_sections', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $concept($table, 'template_id');
            $table->text('section_key');
            $table->text('label');
            $table->text('hint')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unique(['template_id', 'section_key']);
        });

        Schema::create('research_dmps', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->text('title');
            $concept($table, 'status_id');
            $table->text('funder')->nullable();
            $concept($table, 'template_id');
            $table->string('language', 12)->default('en');
            $table->text('contact_name')->nullable();
            $table->text('contact_email')->nullable();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_dmp_sections', function (Blueprint $table) use ($sections) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dmp_id')->constrained('research_dmps')->cascadeOnDelete();
            $sections($table);
            $table->unique(['dmp_id', 'section_key']);
        });

        Schema::create('research_funding', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->text('title');
            $table->text('funder_name');
            $concept($table, 'funder_type_id');
            $table->text('award_reference')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $concept($table, 'currency_id');
            $concept($table, 'status_id');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('dmp_id')->nullable()->constrained('research_dmps')->nullOnDelete();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE research_funding ADD CONSTRAINT research_funding_amount_check CHECK (amount IS NULL OR amount >= 0)');
        DB::statement('ALTER TABLE research_funding ADD CONSTRAINT research_funding_period_check CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');

        Schema::create('research_grant_drafts', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $concept($table, 'template_id');
            $table->text('title');
            $concept($table, 'status_id');
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });

        Schema::create('research_grant_sections', function (Blueprint $table) use ($sections) {
            $table->uuid('id')->primary();
            $table->foreignUuid('draft_id')->constrained('research_grant_drafts')->cascadeOnDelete();
            $sections($table);
            $table->unique(['draft_id', 'section_key']);
        });

        // A funding call a researcher keeps an eye on, theirs alone, for a project or not yet.
        Schema::create('research_grant_calls', function (Blueprint $table) use ($concept) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('research_projects')->nullOnDelete();
            $table->text('funder');
            $table->text('title');
            $table->text('url')->nullable();
            $table->date('deadline')->nullable();
            $concept($table, 'status_id');
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('research_ethics_approvals', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->text('title');
            $concept($table, 'approval_type_id');
            $table->text('reference_number')->nullable();
            $table->text('committee_name')->nullable();
            $concept($table, 'status_id');
            $table->date('decision_date')->nullable();
            $table->date('expiry_date')->nullable();
            $concept($table, 'consent_basis_id');
            $concept($table, 'data_sensitivity_id');
            $table->text('notes')->nullable();
            $table->foreignUuid('dmp_id')->nullable()->constrained('research_dmps')->nullOnDelete();
            $who($table, 'created_by_id');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE research_ethics_approvals ADD CONSTRAINT research_ethics_period_check CHECK (expiry_date IS NULL OR decision_date IS NULL OR expiry_date >= decision_date)');

        Schema::create('research_impact_signals', function (Blueprint $table) use ($concept, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('output_id')->nullable()->constrained('research_project_outputs')->nullOnDelete();
            // The DOI of the project's output the signal is about.
            $table->text('doi')->nullable();
            $concept($table, 'signal_type_id');
            $table->text('title')->nullable();
            $table->text('detail')->nullable();
            $table->text('url')->nullable();
            // Where it was found: openalex, crossref-event, or manual.
            $table->text('source')->nullable();
            $table->timestampTz('detected_at')->nullable();
            $table->timestampTz('created_at')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX research_impact_signals_once ON research_impact_signals (project_id, signal_type_id, url) WHERE url IS NOT NULL');

        Schema::create('research_replication_builds', function (Blueprint $table) use ($who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $who($table, 'built_by_id');
            $table->timestampTz('built_at');
            $table->char('sha256', 64);
            $table->bigInteger('size_bytes');
            $table->jsonb('included');
        });

        // A DOI registered at DataCite under the institution's own repository account, for the project or one of its outputs.
        Schema::create('research_dois', function (Blueprint $table) use ($concept, $who, $project) {
            $table->uuid('id')->primary();
            $project($table);
            $table->foreignUuid('output_id')->nullable()->constrained('research_project_outputs')->nullOnDelete();
            $table->text('doi')->unique();
            $concept($table, 'state_id');
            $table->text('url');
            $table->jsonb('metadata');
            $table->boolean('test')->default(false);
            $who($table, 'minted_by_id');
            $table->timestampsTz();
        });

        // The institution's ORCID client and DataCite repository account, the secrets encrypted.
        Schema::create('research_service_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->unique()->constrained('institutions')->cascadeOnDelete();
            $table->text('orcid_client_id')->nullable();
            $table->text('orcid_client_secret')->nullable();
            $table->boolean('orcid_sandbox')->default(false);
            $table->text('datacite_repository_id')->nullable();
            $table->text('datacite_password')->nullable();
            $table->text('datacite_prefix')->nullable();
            $table->boolean('datacite_test')->default(true);
            $table->timestampsTz();
        });

        // A researcher's ORCID iD, proven by signing in at ORCID, with the token to read and add works.
        Schema::create('researcher_orcid_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('researcher_id')->unique()->constrained('researchers')->cascadeOnDelete();
            $table->string('orcid', 19);
            $table->text('name')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->text('scope')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('linked_at');
            $table->timestampTz('works_pulled_at')->nullable();
            $table->jsonb('works')->nullable();
        });

        foreach ([['research_dmps', 'status_id', 'dmp-status'], ['research_dmps', 'template_id', 'dmp-template'],
            ['research_funding', 'funder_type_id', 'funder-type'], ['research_funding', 'currency_id', 'currency'], ['research_funding', 'status_id', 'funding-status'],
            ['research_grant_drafts', 'template_id', 'grant-template'], ['research_grant_drafts', 'status_id', 'grant-draft-status'], ['research_grant_calls', 'status_id', 'grant-call-status'],
            ['research_ethics_approvals', 'approval_type_id', 'ethics-approval-type'], ['research_ethics_approvals', 'status_id', 'ethics-status'],
            ['research_ethics_approvals', 'consent_basis_id', 'consent-basis'], ['research_ethics_approvals', 'data_sensitivity_id', 'data-sensitivity'],
            ['research_impact_signals', 'signal_type_id', 'impact-signal-type'], ['research_dois', 'state_id', 'doi-state']] as [$t, $column, $vocabulary]) {
            DB::statement("CREATE TRIGGER {$t}_{$column}_vocabulary BEFORE INSERT OR UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('{$column}', '".self::VOCAB."{$vocabulary}/')");
        }

        foreach (json_decode(file_get_contents(resource_path('research/template-sections.json')), true) as $vocabulary => $templates) {
            foreach ($templates as $notation => $rows) {
                $template = DB::table('concepts')->where('iri', self::VOCAB."$vocabulary/$notation")->value('id');
                foreach ($rows as $i => $row) {
                    DB::table('research_template_sections')->insertOrIgnore(['id' => (string) Str::uuid7(), 'template_id' => $template, 'section_key' => $row['key'],
                        'label' => $row['label'], 'hint' => $row['hint'] ?: null, 'sort_order' => ($i + 1) * 10]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['researcher_orcid_links', 'research_service_settings', 'research_dois', 'research_replication_builds', 'research_impact_signals', 'research_ethics_approvals',
            'research_grant_calls', 'research_grant_sections', 'research_grant_drafts', 'research_funding', 'research_dmp_sections', 'research_dmps', 'research_template_sections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
