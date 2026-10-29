<?php

// OpenRiC Workspace - relation types as rows (governance/HERATIO-PARITY.md, "Term", 29 October 2026).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The relation types and what they connect were config workspace.relation_types. They are rows now, so that an
// institution can add its own, as AtoM's and Heratio's relation type taxonomies allow (converse_term, self_reciprocal):
// - relation_types: a row for each type's concept. A bundled type (relation-type vocabulary) says whether the order of
//   its two ends carries no meaning (self_reciprocal, Heratio's name for it), whether it may carry an agent role (OpenRiC role-type vocabulary),
//   whether it is shown with a page's access points or only in a section of its own, such as physical storage, and
//   the RiC-O term a record set's relation takes where RiC-O gives a record set the term only through its members.
//   An institution's own type, a concept of one of its relation-type vocabularies, narrows a bundled type (base_id)
//   and takes everything but its wording from it, so it connects, is exported and counts as that type;
// - relation_type_pairs: the kinds of entity a bundled type connects, source and target. "agent" stands for every
//   kind of agent (config relation_kind_groups), person a person, group a family or corporate body (rico:Group); a
//   kind may name types after a colon, such as agent:family|corporate-body (agent-type vocabulary);
// - relation_type_schemes: the kinds of vocabulary (concept-scheme-kind vocabulary) a concept at either end must be in;
// - relation_type_phrases: how a relation reads from its source (forward) and from its target (inverse, Heratio's
//   converse term), in each language; the bundled types' Afrikaans comes from lang/af.json.
// A relation's type is one of these rows, and an institution's own type only for its own relations.

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** The bundled types as config workspace.relation_types held them until v0.0.75. */
    private const TYPES = [
        'associated-record' => ['pairs' => [['record', 'record']], 'symmetric' => true, 'forward' => 'is associated with', 'inverse' => 'is associated with'],
        'genetic' => ['pairs' => [['record', 'record']], 'symmetric' => true, 'forward' => 'has a genetic link to', 'inverse' => 'has a genetic link to'],
        'sequential' => ['pairs' => [['record', 'record'], ['agent', 'agent']], 'forward' => 'precedes or preceded', 'inverse' => 'follows or followed'],
        'holding' => ['pairs' => [['agent', 'record']], 'role' => true, 'forward' => 'holds or held', 'inverse' => 'is or was held by'],
        'creation' => ['pairs' => [['record', 'agent']], 'role' => true, 'forward' => 'was created by', 'inverse' => 'created'],
        'accumulation' => ['pairs' => [['record', 'agent']], 'role' => true, 'forward' => 'was accumulated by', 'inverse' => 'accumulated'],
        'management' => ['pairs' => [['agent', 'record']], 'role' => true, 'forward' => 'manages or managed', 'inverse' => 'is or was managed by'],
        'ownership' => ['pairs' => [['agent', 'record']], 'role' => true, 'forward' => 'owns or owned', 'inverse' => 'is or was owned by'],
        'agent-hierarchical' => ['pairs' => [['agent', 'agent']], 'forward' => 'has or had as a subordinate', 'inverse' => 'is or was subordinate to'],
        'agent-temporal' => ['pairs' => [['agent', 'agent']], 'forward' => 'was succeeded by', 'inverse' => 'succeeded'],
        'agent-control' => ['pairs' => [['agent', 'agent']], 'forward' => 'controls or controlled', 'inverse' => 'is or was controlled by'],
        'agent-associated' => ['pairs' => [['agent', 'agent']], 'symmetric' => true, 'forward' => 'is or was associated with', 'inverse' => 'is or was associated with'],
        'subject' => ['pairs' => [['record', 'concept'], ['record', 'place'], ['record', 'agent'], ['record', 'activity']], 'schemes' => ['subject'], 'access_point' => true, 'forward' => 'is or was about', 'inverse' => 'is or was the subject of'],
        'place' => ['pairs' => [['place', 'record'], ['place', 'agent'], ['place', 'activity']], 'access_point' => true, 'forward' => 'is associated with', 'inverse' => 'is associated with the place'],
        'place-containment' => ['pairs' => [['place', 'place']], 'forward' => 'contains or contained', 'inverse' => 'lies or lay in'],
        'family' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'belongs to the same family as', 'inverse' => 'belongs to the same family as'],
        'child' => ['pairs' => [['person', 'person']], 'forward' => 'is the parent of', 'inverse' => 'is the child of'],
        'descendance' => ['pairs' => [['person', 'person']], 'forward' => 'is an ancestor of', 'inverse' => 'is a descendant of'],
        'sibling' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'is a sibling of', 'inverse' => 'is a sibling of'],
        'spouse' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'is or was married to', 'inverse' => 'is or was married to'],
        'guardianship' => ['pairs' => [['agent', 'person']], 'forward' => 'is or was the guardian of', 'inverse' => 'is or was under the guardianship of'],
        'adoption' => ['pairs' => [['person', 'person']], 'forward' => 'adopted', 'inverse' => 'is the adopted child of'],
        'fostering' => ['pairs' => [['agent', 'person']], 'forward' => 'fostered', 'inverse' => 'was fostered by'],
        'membership' => ['pairs' => [['group', 'person']], 'role' => true, 'forward' => 'has or had as a member', 'inverse' => 'is or was a member of'],
        'leadership' => ['pairs' => [['person', 'group']], 'forward' => 'leads or led', 'inverse' => 'is or was led by'],
        'position-holding' => ['pairs' => [['person', 'position']], 'forward' => 'occupies or occupied', 'inverse' => 'is or was occupied by'],
        'position-in-group' => ['pairs' => [['position', 'group']], 'forward' => 'exists or existed in', 'inverse' => 'has or had the position'],
        'group-subdivision' => ['pairs' => [['group', 'group']], 'forward' => 'has or had as a subdivision', 'inverse' => 'is or was a subdivision of'],
        'knowing' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'knew', 'inverse' => 'knew'],
        'knowing-of' => ['pairs' => [['person', 'person']], 'forward' => 'knew of', 'inverse' => 'was known of by'],
        'correspondence' => ['pairs' => [['person', 'person']], 'symmetric' => true, 'forward' => 'corresponded with', 'inverse' => 'corresponded with'],
        'teaching' => ['pairs' => [['person', 'person']], 'forward' => 'taught', 'inverse' => 'was taught by'],
        'study-at' => ['pairs' => [['group', 'person']], 'forward' => 'was attended by the student', 'inverse' => 'studied at'],
        'work' => ['pairs' => [['agent', 'agent']], 'symmetric' => true, 'forward' => 'works or worked with', 'inverse' => 'works or worked with'],
        'occupation' => ['pairs' => [['person', 'concept']], 'schemes' => ['occupation-type'], 'access_point' => true, 'forward' => 'pursues or pursued the occupation', 'inverse' => 'is or was the occupation of'],
        'legal-status' => ['pairs' => [['agent', 'concept']], 'schemes' => ['legal-status'], 'access_point' => true, 'forward' => 'has or had the legal status', 'inverse' => 'is or was the legal status of'],
        // RiC-O 1.1 gives a record set a documentary form type only through its members, hence its own term.
        'documentary-form' => ['pairs' => [['record', 'concept']], 'schemes' => ['documentary-form-type'], 'access_point' => true, 'forward' => 'has the documentary form type',
            'inverse' => 'is the documentary form type of', 'record_set_term' => 'rico:hasOrHadSomeMembersWithDocumentaryFormType'],
        // Activities (rico:Activity): who performed them, who took part and in what role, what they resulted in, what documents them, and the activities they are part of.
        'performance' => ['pairs' => [['activity', 'agent']], 'role' => true, 'forward' => 'is or was performed by', 'inverse' => 'performs or performed'],
        'participation' => ['pairs' => [['activity', 'agent'], ['activity', 'place'], ['activity', 'record']], 'role' => true, 'forward' => 'has or had as a participant', 'inverse' => 'takes or took part in'],
        'result' => ['pairs' => [['activity', 'record']], 'forward' => 'results or resulted in', 'inverse' => 'results or resulted from'],
        'documentation' => ['pairs' => [['record', 'activity']], 'forward' => 'documents', 'inverse' => 'is documented by'],
        'subevent' => ['pairs' => [['activity', 'activity']], 'forward' => 'has or had as a part', 'inverse' => 'is or was part of'],
        // Physical storage (extension ontology 1.6.0): what is kept in a container, and where a container is
        // kept; the dates of a location are the container's movements, and only a storage place takes one.
        'storage' => ['pairs' => [['record', 'container'], ['instantiation', 'container'], ['container', 'container']], 'forward' => 'is or was stored in', 'inverse' => 'stores or stored', 'own_section' => true],
        'location' => ['pairs' => [['container', 'place']], 'forward' => 'is or was kept in', 'inverse' => 'keeps or kept', 'own_section' => true],
    ];

    public function up(): void
    {
        // The relation-type kind of vocabulary (concept-scheme-kind vocabulary).
        foreach (config('workspace.bundled_vocabularies') as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::create('relation_types', function (Blueprint $table) {
            $table->foreignUuid('concept_id')->primary()->constrained('concepts')->restrictOnDelete();
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->uuid('base_id')->nullable();
            $table->boolean('self_reciprocal')->default(false);
            $table->boolean('role')->default(false);
            $table->boolean('access_point')->default(false);
            $table->boolean('own_section')->default(false);
            $table->text('record_set_term')->nullable();
            $table->timestampsTz();
        });
        Schema::table('relation_types', fn (Blueprint $table) => $table->foreign('base_id')->references('concept_id')->on('relation_types')->restrictOnDelete());
        // A bundled type has no base; an institution's own has one and takes what it says from it.
        DB::statement('ALTER TABLE relation_types ADD CONSTRAINT relation_types_local CHECK ((institution_id IS NULL) = (base_id IS NULL)
            AND (base_id IS NULL OR NOT (self_reciprocal OR role OR access_point OR own_section OR record_set_term IS NOT NULL)))');
        Schema::create('relation_type_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('relation_type_id')->constrained('relation_types', 'concept_id')->cascadeOnDelete();
            $table->text('source_kind');
            $table->text('target_kind');
            $table->unique(['relation_type_id', 'source_kind', 'target_kind']);
        });
        Schema::create('relation_type_schemes', function (Blueprint $table) {
            $table->foreignUuid('relation_type_id')->constrained('relation_types', 'concept_id')->cascadeOnDelete();
            $table->foreignUuid('scheme_kind_id')->constrained('concepts')->restrictOnDelete();
            $table->primary(['relation_type_id', 'scheme_kind_id']);
        });
        Schema::create('relation_type_phrases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('relation_type_id')->constrained('relation_types', 'concept_id')->cascadeOnDelete();
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->string('language', 35);
            $table->text('forward');
            $table->text('inverse');
            $table->timestampsTz();
            $table->unique(['relation_type_id', 'language']);
        });
        DB::statement("ALTER TABLE relation_type_phrases ADD CONSTRAINT relation_type_phrases_wording CHECK (btrim(forward) <> '' AND btrim(inverse) <> '')");
        DB::statement("CREATE TRIGGER relation_type_schemes_scheme_kind_id_vocabulary BEFORE INSERT OR UPDATE ON relation_type_schemes FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('scheme_kind_id', '".self::VOCAB."concept-scheme-kind/')");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION relation_type_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.institution_id IS NULL AND NOT EXISTS (SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id
                        WHERE c.id = NEW.concept_id AND v.iri = 'https://workspace.openric.org/vocab/relation-type/') THEN
                    RAISE EXCEPTION 'a bundled relation type is a concept of the relation-type vocabulary' USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF NEW.institution_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM concepts c JOIN vocabularies v ON v.id = c.vocabulary_id JOIN concepts k ON k.id = v.kind_id
                        WHERE c.id = NEW.concept_id AND c.institution_id = NEW.institution_id AND k.iri = 'https://workspace.openric.org/vocab/concept-scheme-kind/relation-type') THEN
                    RAISE EXCEPTION 'an institution''s own relation type is a concept of one of its relation-type vocabularies' USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF NEW.base_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM relation_types b WHERE b.concept_id = NEW.base_id AND b.institution_id IS NULL AND NOT b.own_section) THEN
                    RAISE EXCEPTION 'an institution''s own relation type narrows a bundled type other than storage and location' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER relation_types_guard BEFORE INSERT OR UPDATE ON relation_types FOR EACH ROW EXECUTE FUNCTION relation_type_guard()');

        $af = json_decode(file_get_contents(lang_path('af.json')), true);
        $now = now();
        foreach (self::TYPES as $notation => $type) {
            $id = DB::table('concepts')->where('iri', self::VOCAB."relation-type/$notation")->value('id');
            DB::table('relation_types')->insert(['concept_id' => $id, 'self_reciprocal' => $type['symmetric'] ?? false, 'role' => $type['role'] ?? false,
                'access_point' => $type['access_point'] ?? false, 'own_section' => $type['own_section'] ?? false,
                'record_set_term' => $type['record_set_term'] ?? null, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($type['pairs'] as [$source, $target]) {
                DB::table('relation_type_pairs')->insert(['relation_type_id' => $id, 'source_kind' => $source, 'target_kind' => $target]);
            }
            foreach ($type['schemes'] ?? [] as $kind) {
                DB::table('relation_type_schemes')->insert(['relation_type_id' => $id,
                    'scheme_kind_id' => DB::table('concepts')->where('iri', self::VOCAB."concept-scheme-kind/$kind")->value('id')]);
            }
            $phrases = ['en' => [$type['forward'], $type['inverse']]];
            if (isset($af[$type['forward']], $af[$type['inverse']])) {
                $phrases['af'] = [$af[$type['forward']], $af[$type['inverse']]];
            }
            foreach ($phrases as $language => [$forward, $inverse]) {
                DB::table('relation_type_phrases')->insert(['id' => (string) Str::uuid7(), 'relation_type_id' => $id, 'language' => $language,
                    'forward' => $forward, 'inverse' => $inverse, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // A relation's type is a bundled type or one of its institution's own.
        DB::statement('DROP TRIGGER relations_relation_type_id_vocabulary ON relations');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION relation_of_type_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM relation_types t WHERE t.concept_id = NEW.relation_type_id AND (t.institution_id IS NULL OR t.institution_id = NEW.institution_id)) THEN
                    RAISE EXCEPTION 'a relation''s type is a bundled relation type or one of the institution''s own' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $$
            SQL);
        DB::statement('CREATE TRIGGER relations_relation_type_guard BEFORE INSERT OR UPDATE ON relations FOR EACH ROW EXECUTE FUNCTION relation_of_type_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS relations_relation_type_guard ON relations');
        DB::statement('DROP FUNCTION IF EXISTS relation_of_type_guard()');
        DB::statement("CREATE TRIGGER relations_relation_type_id_vocabulary BEFORE INSERT OR UPDATE ON relations FOR EACH ROW EXECUTE FUNCTION concept_in_vocabulary('relation_type_id', '".self::VOCAB."relation-type/')");
        foreach (['relation_type_phrases', 'relation_type_schemes', 'relation_type_pairs', 'relation_types'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS relation_type_guard()');
    }
};
