<?php

// OpenRiC Workspace - dates as entities (rico:Date; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A date belongs to the entity it dates (subject_table, subject_id) through a
// property from the date-property vocabulary that names its RiC-O link. Its
// normalised value is EDTF (rico:normalizedDateValue); its written form and
// the language of that text are kept beside it (rico:expressedDate). The
// earliest and latest day are derived from the EDTF value, for sorting and
// filtering only. Record dates from v0.0.7 are converted, then their table goes.

use App\Description\Edtf;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** v0.0.7 record dates had only an OpenRiC date type; their property follows from it. */
    private const PROPERTY_FOR_TYPE = [
        'creation' => 'creation', 'accumulation' => 'accumulation', 'existence' => 'existence',
        'descriptive' => 'modification', 'custody' => 'associated',
    ];

    public function up(): void
    {
        Schema::create('dates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->text('iri')->unique();
            $table->text('subject_table');
            $table->uuid('subject_id');
            $table->foreignUuid('property_id')->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('date_type_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('qualifier_id')->nullable()->constrained('concepts')->restrictOnDelete();
            $table->string('normalized_value', 64)->nullable();
            $table->text('expressed')->nullable();
            $table->string('expressed_language', 35)->nullable();
            // Derived from normalized_value; null where the interval end is open or unknown.
            $table->date('earliest')->nullable();
            $table->date('latest')->nullable();
            $table->timestampsTz();
            $table->index(['subject_table', 'subject_id']);
            $table->index(['institution_id', 'earliest', 'latest']);
        });
        DB::statement('ALTER TABLE dates ADD CONSTRAINT dates_not_empty CHECK (normalized_value IS NOT NULL OR expressed IS NOT NULL)');
        DB::statement('ALTER TABLE dates ADD CONSTRAINT dates_expressed_language CHECK ((expressed IS NULL) = (expressed_language IS NULL))');
        DB::statement('ALTER TABLE dates ADD CONSTRAINT dates_bounds_order CHECK (earliest IS NULL OR latest IS NULL OR earliest <= latest)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION iri_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.iri <> OLD.iri OR NEW.institution_id <> OLD.institution_id THEN
                    RAISE EXCEPTION 'the persistent IRI and institution of % cannot change', OLD.iri USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER dates_iri_immutable BEFORE UPDATE ON dates FOR EACH ROW EXECUTE FUNCTION iri_immutable()');
        DB::statement("CREATE TRIGGER dates_property_vocabulary BEFORE INSERT OR UPDATE ON dates FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('property_id', '".self::VOCAB."date-property/')");
        DB::statement("CREATE TRIGGER dates_type_vocabulary BEFORE INSERT OR UPDATE ON dates FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('date_type_id', 'https://openric.org/vocab/date-type/')");
        DB::statement("CREATE TRIGGER dates_qualifier_vocabulary BEFORE INSERT OR UPDATE ON dates FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('qualifier_id', '".self::VOCAB."date-qualifier/')");

        $this->convertRecordDates();
        Schema::drop('record_dates');
    }

    public function down(): void
    {
        throw new RuntimeException('Dates as entities cannot be undone by migration; restore the dump deploy.sh took before this release.');
    }

    private function convertRecordDates(): void
    {
        $rows = DB::table('record_dates as d')->join('institutions as i', 'i.id', '=', 'd.institution_id')
            ->join('concepts as t', 't.id', '=', 'd.date_type_id')
            ->get(['d.*', 'i.base_iri', 't.notation as type']);
        if ($rows->isEmpty()) {
            return;
        }
        foreach (['date-property.ttl', 'date-qualifier.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        foreach ($rows as $row) {
            $begin = $row->begin_date ? substr((string) $row->begin_date, 0, 10) : '';
            $end = $row->end_date ? substr((string) $row->end_date, 0, 10) : '';
            $edtf = $begin === $end ? $begin : $begin.'/'.$end;
            [$earliest, $latest] = Edtf::bounds($edtf);
            $id = (string) Str::uuid7();
            DB::table('dates')->insert([
                'id' => $id, 'institution_id' => $row->institution_id, 'iri' => $row->base_iri.'/date/'.$id,
                'subject_table' => 'record_resources', 'subject_id' => $row->record_resource_id,
                'property_id' => DB::table('concepts')->where('iri', self::VOCAB.'date-property/'.(self::PROPERTY_FOR_TYPE[$row->type] ?? 'associated'))->value('id'),
                'date_type_id' => $row->date_type_id, 'normalized_value' => $edtf,
                'earliest' => $earliest, 'latest' => $latest,
                'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }
    }
};
