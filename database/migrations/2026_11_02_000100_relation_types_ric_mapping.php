<?php

// OpenRiC Workspace - the RiC-O mapping of each relation type as data (roadmap phase 23b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Until now the relation-type vocabulary said in words how each type is exported ("Exported as a
// rico:CreationRelation...; shortcut rico:hasCreator, inverse rico:isCreatorOf"), and the export read only a
// record's creators. Each bundled type now keeps its RiC-O class (the rico:Relation subclass, rico:Relation where
// RiC-O 1.1 has none, or none where the relation is only a property), the property from its source to its target
// and the inverse, so the export and the import read the same mapping. An institution's own type takes its base's.
// Taken from the scope notes and checked against RiC-O 1.1 and the extension ontology 1.6.0 (RicMappingTest).
// The derivation type is new: one instantiation derived from another (scenario S2 of the gap assessment).

use App\Vocabulary\SkosImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VOCAB = 'https://workspace.openric.org/vocab/';

    /** Bundled type => [class, property, inverse]. */
    private const MAPPING = [
        'associated-record' => ['rico:RecordResourceToRecordResourceRelation', 'rico:isRecordResourceAssociatedWithRecordResource', 'rico:isRecordResourceAssociatedWithRecordResource'],
        'genetic' => ['rico:RecordResourceGeneticRelation', 'rico:hasGeneticLinkToRecordResource', 'rico:hasGeneticLinkToRecordResource'],
        'sequential' => ['rico:SequentialRelation', 'rico:precedesOrPreceded', 'rico:followsOrFollowed'],
        'holding' => ['rico:RecordResourceHoldingRelation', 'rico:isOrWasHolderOf', 'rico:hasOrHadHolder'],
        'creation' => ['rico:CreationRelation', 'rico:hasCreator', 'rico:isCreatorOf'],
        'accumulation' => ['rico:AccumulationRelation', 'rico:hasAccumulator', 'rico:isAccumulatorOf'],
        'management' => ['rico:ManagementRelation', 'rico:isOrWasManagerOf', 'rico:hasOrHadManager'],
        'ownership' => ['rico:OwnershipRelation', 'rico:isOrWasOwnerOf', 'rico:hasOrHadOwner'],
        'agent-hierarchical' => ['rico:AgentHierarchicalRelation', 'rico:hasOrHadSubordinate', 'rico:isOrWasSubordinateTo'],
        'agent-temporal' => ['rico:AgentTemporalRelation', 'rico:hasSuccessor', 'rico:isSuccessorOf'],
        'agent-control' => ['rico:AgentControlRelation', 'rico:isOrWasControllerOf', 'rico:hasOrHadController'],
        'agent-associated' => ['rico:AgentToAgentRelation', 'rico:isAgentAssociatedWithAgent', 'rico:isAgentAssociatedWithAgent'],
        'subject' => ['rico:Relation', 'rico:hasOrHadSubject', 'rico:isOrWasSubjectOf'],
        'place' => ['rico:PlaceRelation', 'rico:isPlaceAssociatedWith', 'rico:isAssociatedWithPlace'],
        'place-containment' => ['rico:Relation', 'rico:containsOrContained', 'rico:isOrWasContainedBy'],
        'family' => ['rico:FamilyRelation', 'rico:hasFamilyAssociationWith', 'rico:hasFamilyAssociationWith'],
        'child' => ['rico:ChildRelation', 'rico:hasChild', 'rico:isChildOf'],
        'descendance' => ['rico:DescendanceRelation', 'rico:hasDescendant', 'rico:hasAncestor'],
        'sibling' => ['rico:SiblingRelation', 'rico:hasSibling', 'rico:hasSibling'],
        'spouse' => ['rico:SpouseRelation', 'rico:hasOrHadSpouse', 'rico:hasOrHadSpouse'],
        'guardianship' => ['rico:FamilyRelation', 'openricx:isOrWasGuardianOf', 'openricx:isOrWasUnderGuardianshipOf'],
        'adoption' => ['rico:ChildRelation', 'openricx:hasAdoptedChild', 'openricx:isOrWasAdoptedChildOf'],
        'fostering' => ['rico:FamilyRelation', 'openricx:hasFosterChild', 'openricx:isOrWasFosterChildOf'],
        'membership' => ['rico:MembershipRelation', 'rico:hasOrHadMember', 'rico:isOrWasMemberOf'],
        'leadership' => ['rico:LeadershipRelation', 'rico:isOrWasLeaderOf', 'rico:hasOrHadLeader'],
        'position-holding' => ['rico:PositionHoldingRelation', 'rico:occupiesOrOccupied', 'rico:isOrWasOccupiedBy'],
        'position-in-group' => ['rico:PositionToGroupRelation', 'rico:existsOrExistedIn', 'rico:hasOrHadPosition'],
        'group-subdivision' => ['rico:GroupSubdivisionRelation', 'rico:hasOrHadSubdivision', 'rico:isOrWasSubdivisionOf'],
        'knowing' => ['rico:KnowingRelation', 'rico:knows', 'rico:knows'],
        'knowing-of' => ['rico:KnowingOfRelation', 'rico:knowsOf', 'rico:knownBy'],
        'correspondence' => ['rico:CorrespondenceRelation', 'rico:hasOrHadCorrespondent', 'rico:hasOrHadCorrespondent'],
        'teaching' => ['rico:TeachingRelation', 'rico:hasOrHadStudent', 'rico:hasOrHadTeacher'],
        'study-at' => ['rico:StudyAtRelation', 'rico:isOrWasAttendedByStudent', 'rico:studiesOrStudiedAt'],
        'work' => ['rico:WorkRelation', 'rico:hasOrHadWorkRelationWith', 'rico:hasOrHadWorkRelationWith'],
        // The target is a type concept, which RiC-O gives no property back to an agent from.
        'occupation' => ['openricx:Occupation', 'rico:hasOrHadOccupationOfType', null],
        'legal-status' => ['rico:Relation', 'rico:hasOrHadLegalStatus', null],
        'documentary-form' => [null, 'rico:hasDocumentaryFormType', 'rico:isDocumentaryFormTypeOf'],
        'storage' => ['rico:Relation', 'openricx:isOrWasStoredIn', 'openricx:storesOrStored'],
        'location' => ['rico:Relation', 'rico:hasOrHadLocation', 'rico:isOrWasLocationOf'],
        'performance' => ['rico:PerformanceRelation', 'rico:isOrWasPerformedBy', 'rico:performsOrPerformed'],
        'participation' => ['rico:Relation', 'rico:hasOrHadParticipant', 'rico:isOrWasParticipantIn'],
        'result' => ['rico:Relation', 'rico:resultsOrResultedIn', 'rico:resultsOrResultedFrom'],
        'documentation' => ['rico:ActivityDocumentationRelation', 'rico:documents', 'rico:documentedBy'],
        'subevent' => ['rico:Relation', 'rico:hasOrHadSubevent', 'rico:isOrWasSubeventOf'],
        'material-creator' => ['rico:Relation', 'rico:isRelatedTo', 'rico:isRelatedTo'],
        'accrual' => ['rico:Relation', 'rico:isRelatedTo', 'rico:isRelatedTo'],
        'covers' => ['rico:Relation', 'rico:hasOrHadSubject', 'rico:isOrWasSubjectOf'],
        'partly-covers' => ['rico:Relation', 'rico:hasOrHadSubject', 'rico:isOrWasSubjectOf'],
        'refers-to' => ['rico:RecordResourceToRecordResourceRelation', 'rico:isRecordResourceAssociatedWithRecordResource', 'rico:isRecordResourceAssociatedWithRecordResource'],
        'derivation' => ['rico:DerivationRelation', 'rico:hasOrHadDerivedInstantiation', 'rico:isOrWasDerivedFromInstantiation'],
    ];

    public function up(): void
    {
        foreach (['relation-type.ttl', 'file-availability.ttl'] as $file) {
            app(SkosImporter::class)->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        Schema::table('relation_types', function (Blueprint $table) {
            $table->text('ric_class')->nullable();
            $table->text('ric_property')->nullable();
            $table->text('ric_inverse')->nullable();
        });
        // An institution's own type takes its base's mapping, as it takes everything but its wording.
        DB::statement('ALTER TABLE relation_types ADD CONSTRAINT relation_types_local_mapping CHECK (base_id IS NULL OR (ric_class IS NULL AND ric_property IS NULL AND ric_inverse IS NULL))');

        $type = fn (string $notation) => DB::table('concepts')->where('iri', self::VOCAB."relation-type/$notation")->value('id');
        $derivation = $type('derivation');
        DB::table('relation_types')->insert(['concept_id' => $derivation, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('relation_type_pairs')->insert(['relation_type_id' => $derivation, 'source_kind' => 'instantiation', 'target_kind' => 'instantiation']);
        $af = json_decode(file_get_contents(lang_path('af.json')), true);
        foreach (['en' => ['has the derived copy', 'is derived from'], 'af' => [$af['has the derived copy'], $af['is derived from']]] as $language => [$forward, $inverse]) {
            DB::table('relation_type_phrases')->insert(['id' => (string) Str::uuid7(), 'relation_type_id' => $derivation, 'language' => $language,
                'forward' => $forward, 'inverse' => $inverse, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (self::MAPPING as $notation => [$class, $property, $inverse]) {
            DB::table('relation_types')->where('concept_id', $type($notation))->whereNull('institution_id')
                ->update(['ric_class' => $class, 'ric_property' => $property, 'ric_inverse' => $inverse]);
        }
    }

    public function down(): void
    {
        $derivation = DB::table('concepts')->where('iri', self::VOCAB.'relation-type/derivation')->value('id');
        DB::table('relation_types')->where('concept_id', $derivation)->delete();
        DB::statement('ALTER TABLE relation_types DROP CONSTRAINT relation_types_local_mapping');
        Schema::table('relation_types', fn (Blueprint $table) => $table->dropColumn(['ric_class', 'ric_property', 'ric_inverse']));
    }
};
