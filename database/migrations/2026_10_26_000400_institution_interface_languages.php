<?php

// OpenRiC Workspace - the interface languages an institution offers (WS-005; AtoM's and Heratio's i18n languages setting).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A list of BCP 47 tags, chosen under Settings from the languages that have a
// translation file (App\Support\InterfaceLanguages). English is always offered.
// Institutions that exist keep the English and Afrikaans they had.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', fn (Blueprint $table) => $table->jsonb('interface_languages')->default(DB::raw("'[\"en\", \"af\"]'::jsonb")));
        DB::statement("ALTER TABLE institutions ADD CONSTRAINT institutions_interface_languages_list CHECK (jsonb_typeof(interface_languages) = 'array')");
    }

    public function down(): void
    {
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn('interface_languages'));
    }
};
