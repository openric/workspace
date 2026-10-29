<?php

// OpenRiC Workspace - community protocols (Heratio's TermProtocolService and TermProtocolGate): a withholding condition on a
// term hides it and every record tagged with it from the public, from search, from the API and from accounts that may not
// change them, and keeps the files back; a Label brings its default condition, which a record may override; who may
// change the material always sees it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\ConceptProtocol;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\RelationService;
use App\Rights\CommunityLabelService;
use App\Rights\CommunityProtocols;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private Institution $institution;

    private User $editor;

    private User $researcher;

    private RecordResource $letter;

    private Concept $rites;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        $this->editor = $accounts->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        $this->researcher = $accounts->create($this->institution, 'Researcher', 'researcher@example.org', 'correct horse battery staple');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->letter = app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/item', 'Account of the initiation', 'en', null, $holder, 'L1');
        $subjects = app(ConceptService::class)->schemeOfKind($this->institution, 'subject');
        $this->rites = app(ConceptService::class)->create($subjects, ['label' => 'Initiation rites', 'language' => 'en']);
        app(RelationService::class)->create($this->letter, 'subject', $this->rites, [], $this->editor);
        foreach ([$holder, $this->letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    public function test_a_withholding_protocol_on_a_term_hides_it_and_the_records_tagged_with_it(): void
    {
        $this->get(self::SITE."/discover/records/{$this->letter->id}")->assertOk();
        $this->actingAs($this->editor)->get("/concepts/{$this->rites->id}")->assertOk()->assertSee('None: the term is open.')->assertSee('Sacred / secret');

        // Choosing a group, not a condition, is refused.
        $this->actingAs($this->editor)->post("/concepts/{$this->rites->id}/protocol", ['access_condition' => 'withheld'])->assertSessionHasErrors('concept');
        $this->actingAs($this->editor)->post("/concepts/{$this->rites->id}/protocol", ['access_condition' => 'sacred-secret', 'label' => 'tk_s', 'region' => 'southern_africa'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Community protocol saved.');
        $this->assertSame(['sacred-secret', 'tk_s', 'southern_africa'], [ConceptProtocol::sole()->accessCondition->notation, ConceptProtocol::sole()->label->notation, ConceptProtocol::sole()->region]);

        // The public, the API and a researcher see neither the record nor the term; the editor sees both.
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$this->letter->id}")->assertNotFound();
        $this->get(self::SITE.'/discover')->assertOk()->assertDontSee('Account of the initiation');
        $this->getJson(self::SITE."/api/ric/v1/records/{$this->letter->id}")->assertNotFound();
        $this->actingAs($this->researcher)->get("/records/{$this->letter->id}")->assertNotFound();
        $this->actingAs($this->researcher)->get('/records')->assertOk()->assertDontSee('Account of the initiation');
        $this->actingAs($this->researcher)->get("/concepts/{$this->rites->id}")->assertNotFound();
        $this->actingAs($this->editor)->get("/records/{$this->letter->id}")->assertOk();
        $this->actingAs($this->editor)->get("/concepts/{$this->rites->id}")->assertOk()->assertSee('withheld from the public, with every unit of description tagged with it');

        // Open with no Label removes the protocol, and the record comes back.
        $this->actingAs($this->editor)->post("/concepts/{$this->rites->id}/protocol", ['access_condition' => 'open'])->assertSessionHasNoErrors();
        $this->assertSame(0, ConceptProtocol::count());
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$this->letter->id}")->assertOk();
        $this->getJson(self::SITE."/api/ric/v1/records/{$this->letter->id}")->assertOk();
    }

    public function test_a_condition_that_shows_keeps_the_record_in_the_catalogue(): void
    {
        $this->actingAs($this->editor)->post("/concepts/{$this->rites->id}/protocol", ['access_condition' => 'attribution', 'label' => 'tk_a'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$this->letter->id}")->assertOk();
        $this->assertFalse(app(CommunityProtocols::class)->withholds($this->letter));
    }

    public function test_a_label_brings_its_default_condition_and_a_record_may_override_it(): void
    {
        $labels = app(CommunityLabelService::class);
        // Secret / sacred withholds by default (Heratio's map); the record need not be tagged with any term.
        $applied = $labels->apply($this->letter, ['label' => 'tk_s', 'applied_by' => 'community'], $this->editor);
        $this->assertSame('sacred-secret', app(CommunityProtocols::class)->conditionOf($applied)->notation);
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$this->letter->id}")->assertNotFound();
        $this->actingAs($this->editor)->get("/records/{$this->letter->id}")->assertSee('Access condition: Sacred / secret (withheld from the public)');

        // An Attribution Label is open by default, but this record's is restricted.
        $labels->remove($applied, $this->editor);
        $this->actingAs($this->editor)->post("/records/{$this->letter->id}/community-labels", ['label' => 'tk_a', 'applied_by' => 'community', 'access_condition' => 'restricted'])->assertRedirect();
        auth()->logout();
        $this->get(self::SITE."/discover/records/{$this->letter->id}")->assertNotFound();
        $this->assertSame('attribution', app(CommunityProtocols::class)->labelDefault(Concept::where('notation', 'tk_a')->firstOrFail())->notation);
    }
}
