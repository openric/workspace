<?php

// OpenRiC Workspace - Heratio fields that roadmap phases 13a and 18a left out, found by the form audit of 31 October
// 2026: an embargo's review date and interval (embargo.review_date, review_interval_months; ADR 0015), a data subject
// request's priority and notes (privacy_dsar.priority, notes), a PAIA request's fee exemption
// (privacy_paia_request.fee_exemption_requested), a security compromise's cause (privacy_breach.cause; ADR 0016) and a
// report's category (custom_report.category, ahg-reports). The priority and the category are vocabulary rows.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['privacy-request-priority', 'report-category'] as $name) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$name.ttl")));
        }
        Schema::table('privacy_requests', function (Blueprint $table) {
            $table->foreignUuid('priority_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->text('notes')->nullable();
        });
        DB::statement("CREATE TRIGGER privacy_requests_priority_id_vocabulary BEFORE INSERT OR UPDATE ON privacy_requests FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('priority_id', 'https://workspace.openric.org/vocab/privacy-request-priority/')");
        Schema::table('paia_requests', fn (Blueprint $table) => $table->boolean('fee_exemption_requested')->default(false));
        Schema::table('security_compromises', fn (Blueprint $table) => $table->text('cause')->nullable());
        Schema::table('embargoes', function (Blueprint $table) {
            $table->date('review_on')->nullable();
            $table->integer('review_interval_months')->nullable();
        });
        DB::statement('ALTER TABLE embargoes ADD CONSTRAINT embargoes_review_interval CHECK (review_interval_months IS NULL OR review_interval_months > 0)');
        Schema::table('custom_reports', fn (Blueprint $table) => $table->foreignUuid('category_id')->nullable()->constrained('concepts')->restrictOnDelete());
        DB::statement("CREATE TRIGGER custom_reports_category_id_vocabulary BEFORE INSERT OR UPDATE ON custom_reports FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('category_id', 'https://workspace.openric.org/vocab/report-category/')");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS custom_reports_category_id_vocabulary ON custom_reports');
        Schema::table('custom_reports', fn (Blueprint $table) => $table->dropConstrainedForeignId('category_id'));
        Schema::table('embargoes', fn (Blueprint $table) => $table->dropColumn(['review_on', 'review_interval_months']));
        DB::statement('DROP TRIGGER IF EXISTS privacy_requests_priority_id_vocabulary ON privacy_requests');
        Schema::table('privacy_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('priority_id'));
        Schema::table('privacy_requests', fn (Blueprint $table) => $table->dropColumn('notes'));
        Schema::table('paia_requests', fn (Blueprint $table) => $table->dropColumn('fee_exemption_requested'));
        Schema::table('security_compromises', fn (Blueprint $table) => $table->dropColumn('cause'));
    }
};
