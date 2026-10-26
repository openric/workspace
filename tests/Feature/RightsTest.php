<?php

// OpenRiC Workspace - rights and Local Contexts Labels on records and instantiations (WS-007, roadmap phase 2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\CommunityLabel;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Right;
use App\Models\User;
use App\Rights\CommunityLabelService;
use App\Rights\RightsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class RightsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $editor;

    private RecordResource $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->record = app(RecordService::class)->create($this->institution, 'https://workspace.openric.org/vocab/level-of-description/item', 'Photograph of the council', 'en', null, $holder, 'P1');
    }

    public function test_a_right_with_its_statement_licence_holder_and_grants(): void
    {
        $photographer = app(AgentService::class)->create($this->institution, 'person', 'Botha, Maria', 'en');
        $scan = app(InstantiationService::class)->create($this->record, 'Scan', 'en');
        $right = app(RightsService::class)->add($scan, ['basis' => 'copyright', 'statement' => 'InC-EDU', 'licence' => 'CC-BY-NC-4.0', 'copyright_status' => 'copyrighted',
            'jurisdiction' => 'za', 'determined_on' => '2026-10-01', 'starts_on' => '1952-01-01', 'ends_on' => '2042-12-31', 'holder' => $photographer->id,
            'note' => 'Taken for the council.', 'note_language' => 'en',
            'grants' => [['act' => 'render', 'restriction' => 'allow'], ['act' => 'publish', 'restriction' => 'conditional', 'note' => 'Ask the holder.'], ['act' => 'modify', 'restriction' => 'disallow']]], $this->editor);

        $right = $right->fresh(['statement', 'licence', 'holder', 'grants.act', 'grants.restriction']);
        $this->assertStringStartsWith('https://archives.example.org/rule/', $right->iri);
        $this->assertSame(['http://rightsstatements.org/vocab/InC-EDU/1.0/', 'https://creativecommons.org/licenses/by-nc/4.0/', 'ZA', $photographer->id],
            [$right->statement->iri, $right->licence->iri, $right->jurisdiction, $right->holder_id]);
        $this->assertSame(['render' => 'allow', 'publish' => 'conditional', 'modify' => 'disallow'],
            $right->grants->mapWithKeys(fn ($g) => [$g->act->notation => $g->restriction->notation])->all());
        $this->assertTrue($right->inForce());
        $this->assertFalse($right->inForce('2043-01-01'));
        $this->assertDatabaseHas('audit_log', ['subject_id' => $right->id, 'actor_id' => $this->editor->id]);

        // Saving replaces the grants, as Heratio's saveRightsRecord does.
        app(RightsService::class)->update($right, ['basis' => 'licence', 'licence' => 'CC-BY-4.0', 'grants' => [['act' => 'use', 'restriction' => 'allow']]], $this->editor);
        $this->assertSame(['use'], $right->fresh()->grants()->with('act')->get()->pluck('act.notation')->all());
        $this->assertCount(1, app(RightsService::class)->of($scan));

        // Removed, it is kept with its IRI, but leaves the subject's rights.
        app(RightsService::class)->remove($right, $this->editor);
        $this->assertCount(0, app(RightsService::class)->of($scan));
        $this->assertNotNull(Right::find($right->id)->deleted_at);
        $this->expectException(QueryException::class);
        DB::table('rights')->where('id', $right->id)->delete();
    }

    public function test_a_right_is_refused_what_its_vocabularies_and_rules_do_not_allow(): void
    {
        $rights = app(RightsService::class);
        foreach ([
            [['basis' => 'magic'], 'is not in the vocabulary'],
            [['basis' => 'copyright', 'statement' => 'CC-BY-4.0'], 'is not in the vocabulary'],
            [['basis' => 'copyright', 'jurisdiction' => 'South Africa'], 'two-letter country code'],
            [['basis' => 'copyright', 'starts_on' => '2020-01-01', 'ends_on' => '2019-01-01'], 'cannot end before it starts'],
            [['basis' => 'copyright', 'starts_on' => '2020-02-30'], 'YYYY-MM-DD'],
            [['basis' => 'copyright', 'holder' => '00000000-0000-0000-0000-000000000000'], 'rights holder'],
            [['basis' => 'copyright', 'note' => 'A note.'], 'language of the note'],
            [['basis' => 'copyright', 'grants' => [['act' => 'render'], ['act' => 'render']]], 'each act is granted once'],
        ] as [$fields, $message]) {
            try {
                $rights->add($this->record, $fields, $this->editor);
                $this->fail('accepted '.json_encode($fields));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame(0, Right::count());
    }

    public function test_a_community_applies_a_local_contexts_label_once(): void
    {
        $community = app(AgentService::class)->create($this->institution, 'corporate-body', 'Riverside Khoe Council', 'en');
        $labels = app(CommunityLabelService::class);
        $this->assertSame(['bc', 'tk'], $labels->choices()->keys()->sort()->values()->all());
        $label = $labels->apply($this->record, ['label' => 'tk_s', 'applied_by' => 'community', 'community' => $community->id, 'project' => 'abc-123', 'note' => 'Seen by elders only.'], $this->editor);
        $label = $label->fresh(['label', 'appliedBy']);
        $this->assertSame(['https://localcontexts.org/label/tk-secret-sacred/', 'community', $community->id], [$label->label->iri, $label->appliedBy->notation, $label->community_id]);

        $this->expectException(InvalidArgumentException::class);
        $labels->apply($this->record, ['label' => 'tk_s'], $this->editor);
    }

    public function test_a_group_of_labels_is_not_a_label(): void
    {
        $group = Concept::choices(CommunityLabelService::LABELS)->where('notation', 'tk')->firstOrFail();
        $this->expectException(QueryException::class);
        CommunityLabel::create(['institution_id' => $this->institution->id, 'subject_table' => 'record_resources', 'subject_id' => $this->record->id,
            'label_id' => $group->id, 'applied_by_id' => Concept::choices(CommunityLabelService::APPLIERS)->firstOrFail()->id]);
    }

    public function test_the_screens_add_change_and_remove_rights_and_labels(): void
    {
        $scan = app(InstantiationService::class)->create($this->record, 'Scan', 'en');
        $this->actingAs($this->editor)->get("/instantiations/{$scan->id}")->assertOk()->assertSee('Add a right')->assertSee('No rights recorded.');
        $this->actingAs($this->editor)->post("/instantiations/{$scan->id}/rights", ['basis' => 'licence', 'licence' => 'CC-BY-4.0',
            'grants' => ['render' => ['restriction' => 'allow'], 'modify' => ['restriction' => ''], 'publish' => ['restriction' => 'conditional', 'note' => 'With credit.']]])
            ->assertRedirect("/instantiations/{$scan->id}#rights");
        $right = Right::firstOrFail();
        $this->assertSame(['publish', 'render'], $right->grants()->with('act')->get()->pluck('act.notation')->sort()->values()->all());
        $this->actingAs($this->editor)->get("/instantiations/{$scan->id}")->assertSee('Attribution 4.0 International')->assertSee('With credit.')
            ->assertSee('https://creativecommons.org/licenses/by/4.0/', false);

        $this->actingAs($this->editor)->post("/instantiations/{$scan->id}/rights/{$right->id}", ['basis' => 'licence', 'licence' => 'CC-BY-SA-4.0'])->assertRedirect();
        $this->assertSame('CC-BY-SA-4.0', $right->fresh()->licence->notation);
        $this->assertSame(0, $right->fresh()->grants()->count());
        $this->actingAs($this->editor)->post("/instantiations/{$scan->id}/rights", ['basis' => 'copyright', 'jurisdiction' => 'ZZZ'])->assertSessionHasErrors();
        $this->actingAs($this->editor)->post("/records/{$this->record->id}/rights/{$right->id}/remove")->assertNotFound();
        $this->actingAs($this->editor)->post("/instantiations/{$scan->id}/rights/{$right->id}/remove")->assertRedirect();
        $this->assertNotNull($right->fresh()->deleted_at);

        $this->actingAs($this->editor)->post("/records/{$this->record->id}/community-labels", ['label' => 'tk_a', 'applied_by' => 'institution'])->assertRedirect("/records/{$this->record->id}#labels");
        $this->actingAs($this->editor)->get("/records/{$this->record->id}")->assertSee('TK Attribution')->assertSee('https://localcontexts.org/label/tk-attribution/', false);
        $label = CommunityLabel::firstOrFail();
        $this->actingAs($this->editor)->post("/records/{$this->record->id}/community-labels/{$label->id}/remove")->assertRedirect();
        $this->assertSame(0, CommunityLabel::count());

        // Only those who may change the record.
        $translator = app(AccountService::class)->create($this->institution, 'Translator', 'translator@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($translator, 'translator');
        $this->actingAs($translator)->post("/records/{$this->record->id}/rights", ['basis' => 'copyright'])->assertForbidden();
        $this->actingAs($translator)->post("/records/{$this->record->id}/community-labels", ['label' => 'tk_a', 'applied_by' => 'institution'])->assertForbidden();
    }
}
