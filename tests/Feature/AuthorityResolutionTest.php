<?php

// OpenRiC Workspace - resolving names to authority records and places (roadmap phase 17b2), after Heratio's
// ahg-authority-resolution: a proposed name becomes a mention with the passage it stands in, the dates, places and
// words of role near it; its candidates are the institution's records of that name, an inverted one included, ranked
// by name and by evidence, so the Maria Botha of the right century and place comes first and the one who died before
// the letter is marked as contradicting it; linking accepts the proposal and keeps the decision as it was seen, every
// candidate with it; a name a model proposed keeps the model's assertion on the relation, which the reviewer accepted;
// a parked name is flagged when a new candidate appears; a rejected one is kept as feedback for the model and exported;
// a new place is filled from GeoNames, each field with its source; a mention may be given to another archivist; and
// only administrators set the outside authorities, of which the stubs cannot be switched on.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Ai\Inferences;
use App\Authority\Mentions;
use App\Authority\NerFeedback;
use App\Authority\ParkQueue;
use App\Authority\Settings;
use App\Description\AgentService;
use App\Description\PlaceService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\AuthorityAssignmentMail;
use App\Models\Agent;
use App\Models\AuthorityDecision;
use App\Models\AuthorityMention;
use App\Models\Institution;
use App\Models\Place;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\User;
use App\Proposals\ProposalService;
use App\Vocabulary\SkosImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthorityResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const LETTER = 'A letter from Maria Botha, daughter of Jan Botha, written in Pretoria in 1902 to the Riverside Parish Council about the bridge.';

    private Institution $institution;

    private User $admin;

    private User $colleague;

    private User $editor;

    private RecordResource $letter;

    private Agent $maria;

    private Agent $olderMaria;

    private Place $pretoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        Mail::fake();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        $this->admin = $accounts->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->admin, 'administrator');
        $this->colleague = $accounts->create($this->institution, 'Thandi', 'thandi@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->colleague, 'administrator');
        $this->editor = $accounts->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Botha family papers', 'en', null, $holder, 'BFP');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the bridge', 'en', $fonds, null, '1');
        app(RecordService::class)->describe($this->letter, ['scope-and-content' => self::LETTER], 'en');

        $agents = app(AgentService::class);
        $this->maria = $agents->create($this->institution, 'person', 'Botha, Maria', 'en');
        $agents->describe($this->maria, ['agent-dates-of-existence' => '1870-1950', 'agent-history' => 'Born in Pretoria, the daughter of Jan Botha.', 'agent-places' => 'Pretoria; Riverside'], 'en');
        $this->olderMaria = $agents->create($this->institution, 'person', 'Maria Botha', 'en');
        $agents->describe($this->olderMaria, ['agent-dates-of-existence' => '1700-1760'], 'en');
        $agents->create($this->institution, 'corporate-body', 'Botha and Sons, Maria Street', 'en');
        $transvaal = app(PlaceService::class)->create($this->institution, 'Transvaal', 'en');
        $this->pretoria = app(PlaceService::class)->create($this->institution, 'Pretoria', 'en', $transvaal);
    }

    public function test_a_mention_stands_in_its_context_and_the_evidence_ranks_its_candidates(): void
    {
        $mention = $this->mention('PERSON', 'Maria Botha');
        $this->assertStringContainsString('A letter from', $mention->text_before);
        $this->assertStringStartsWith(', daughter of Jan Botha', $mention->text_after);
        $this->assertSame(['1902'], collect($mention->nearby_dates)->pluck('value')->all());
        $this->assertSame(['Pretoria'], collect($mention->nearby_places)->pluck('value')->all());
        $this->assertContains('daughter of', collect($mention->role_tokens)->pluck('token')->all());

        $candidates = $mention->candidates()->get();
        $this->assertSame([$this->maria->id, $this->olderMaria->id], $candidates->pluck('candidate_id')->all(), 'only people, and the inverted name found; the right one first');
        $this->assertSame(1.0, $candidates[1]->name_similarity, 'the name as written');
        $this->assertSame(1.0, $candidates[0]->name_similarity, 'the inverted name, by its words in order');
        $this->assertEquals(['temporal' => 'match', 'geographic' => 'match', 'relational' => 'absent', 'role' => 'match', 'conflict' => 'silent'], $candidates[0]->signals);
        $this->assertSame('conflict', $candidates[1]->signals['conflict']);
        $this->assertSame([1902], $candidates[1]->evidence['conflict']['mention_years_after_end']);
        $this->assertSame([1.0, 0.7], [$candidates[0]->composite, $candidates[1]->composite]);

        $place = $this->mention('GPE', 'Pretoria');
        $this->assertSame([$this->pretoria->id], $place->candidates()->pluck('candidate_id')->all());
        $this->assertSame('absent', $place->candidates()->first()->signals['hierarchical'], 'no broader place is named near it');
        $this->actingAs($this->admin)->get(route('authority.review', $mention))->assertOk()->assertSee('Botha, Maria')->assertSee('contradicts')->assertSee('named in 1902, after its end');
        $this->actingAs($this->admin)->get(route('authority.queue'))->assertOk()->assertSee('Maria Botha');
    }

    public function test_linking_accepts_the_proposal_and_keeps_the_decision_as_it_was_seen(): void
    {
        $mention = $this->mention('PERSON', 'Maria Botha');
        // A model found it: the relation keeps the model's assertion, which the reviewer accepted.
        $inference = app(Inferences::class)->record(['institution_id' => $this->institution->id, 'service' => 'ner', 'endpoint' => 'https://ai.example.org/ai/v1/ner/extract',
            'model' => 'en_core_web_sm', 'input' => self::LETTER, 'output' => '[]', 'succeeded' => true]);
        $mention->proposal->update(['inference_id' => $inference->id]);
        $best = $mention->candidates()->first();
        $this->actingAs($this->admin)->post(route('authority.link', [$mention, $best]))->assertRedirect(route('authority.review', $mention));

        $this->assertSame('linked', $mention->fresh()->state->notation);
        $this->assertSame('accepted', $mention->proposal->fresh()->status->notation);
        $relation = Relation::where(['source_id' => $this->letter->id, 'target_id' => $this->maria->id])->sole();
        $assertion = $relation->assertions()->with('origin')->sole();
        $this->assertSame(['model', 'en_core_web_sm', $this->admin->id], [$assertion->origin->notation, $assertion->origin_label, $assertion->reviewed_by_id]);
        $decision = AuthorityDecision::sole();
        $this->assertSame(['link', 'agents', $this->maria->id, 1.0], [$decision->decision->notation, $decision->chosen_table, $decision->chosen_id, $decision->top_score]);
        $this->assertCount(2, $decision->candidates_snapshot);
        $this->assertTrue($decision->candidates_snapshot[0]['chosen']);
        $this->assertSame('match', $decision->evidence_snapshot[$best->id]['signals']['temporal']);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('authority_decisions')->update(['reason' => 'changed'])), QueryException::class, 'kept as they were made');
        $this->actingAs($this->admin)->post(route('authority.reject', $mention))->assertSessionHasErrors('decision');
    }

    public function test_a_parked_name_is_flagged_when_a_new_candidate_appears(): void
    {
        $mention = $this->mention('ORG', 'Riverside Parish Council');
        $this->assertSame(0, $mention->candidates()->count());
        $this->actingAs($this->admin)->post(route('authority.park', $mention), ['reason' => ''])->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('authority.park', $mention), ['reason' => 'The council is not yet described.'])->assertRedirect();
        $this->assertSame('parked', $mention->fresh()->state->notation);
        $this->assertSame(0, app(ParkQueue::class)->scan());
        app(AgentService::class)->create($this->institution, 'corporate-body', 'Riverside Parish Council', 'en');
        $this->assertSame(1, app(ParkQueue::class)->scan());
        $this->actingAs($this->admin)->get(route('authority.parked', ['new_only' => 1]))->assertOk()->assertSee('The council is not yet described.')->assertSee('new candidate');
        $this->actingAs($this->admin)->post(route('authority.unpark', $mention))->assertRedirect(route('authority.review', $mention));
        $this->assertSame('pending', $mention->fresh()->state->notation);
        $this->assertSame(1, $mention->candidates()->count());
    }

    public function test_a_rejected_name_is_kept_and_exported_as_feedback_for_the_model(): void
    {
        $mention = $this->mention('PERSON', 'Parish Council');
        $this->actingAs($this->admin)->post(route('authority.reject', $mention), ['reason' => 'An organisation, not a person.'])->assertRedirect();
        $this->assertSame(['rejected', 'rejected'], [$mention->fresh()->state->notation, $mention->proposal->fresh()->status->notation]);
        $feedback = DB::table('ner_feedback')->sole();
        $this->assertSame(['Parish Council', 'PERSON', 'An organisation, not a person.'], [$feedback->value, $feedback->label, $feedback->reason]);
        $this->assertSame(self::LETTER, $feedback->source_text);

        ['count' => $count, 'path' => $file] = app(NerFeedback::class)->export('conll', $this->institution->id);
        $this->assertSame(1, $count);
        $this->assertStringContainsString("Parish\tB-REJ-PERSON\nCouncil\tI-REJ-PERSON", file_get_contents($file));
        $this->assertNotNull(DB::table('ner_feedback')->value('exported_at'));
        unlink($file);
    }

    public function test_a_new_place_is_filled_from_geonames_with_each_fields_source(): void
    {
        $mention = $this->mention('GPE', 'Riverside');
        $this->actingAs($this->admin)->post(route('authority.settings.save'), ['top_n' => 5, 'sources' => ['geonames' => ['enabled' => 1, 'username' => 'riverside', 'precedence' => 0],
            'gnd' => ['enabled' => 1]]])->assertRedirect();
        $sources = app(Settings::class)->sources($this->institution->id);
        $this->assertTrue($sources['geonames']['enabled']);
        $this->assertFalse($sources['gnd']['enabled'], 'a stub is never switched on');
        Http::fake(['secure.geonames.org/*' => Http::response(['geonames' => [['geonameId' => 993800, 'name' => 'Riverside', 'lat' => '-25.75', 'lng' => '28.19',
            'countryName' => 'South Africa', 'adminName1' => 'Gauteng', 'fcl' => 'P', 'fcode' => 'PPL']]])]);

        $this->actingAs($this->admin)->get(route('authority.create-form', $mention))->assertOk()->assertSee('https://www.geonames.org/993800')->assertSee('-25.75');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'username=riverside'));
        $provenance = ['latitude' => json_encode(['origin' => 'geonames', 'source' => 'geonames', 'uri' => 'https://www.geonames.org/993800', 'licence' => 'CC-BY-4.0',
            'licence_url' => 'https://creativecommons.org/licenses/by/4.0/', 'retrieved_at' => '2026-11-05T08:00:00Z'])];
        $this->actingAs($this->admin)->post(route('authority.create', $mention), ['fields' => ['name' => 'Riverside', 'latitude' => '-25.75', 'longitude' => ''], 'provenance' => $provenance])
            ->assertSessionHasErrors('decision');
        $this->actingAs($this->admin)->post(route('authority.create', $mention), ['fields' => ['name' => 'Riverside', 'latitude' => '-25.75', 'longitude' => '28.19'], 'provenance' => $provenance])
            ->assertRedirect(route('authority.review', $mention));

        $place = Place::whereKey(Relation::where('target_id', $this->letter->id)->where('source_table', 'places')->value('source_id'))->sole();
        $this->assertSame([-25.75, 28.19], [(float) $place->latitude, (float) $place->longitude]);
        $this->assertSame('new-record-created', $mention->fresh()->state->notation);
        $source = DB::table('authority_field_sources')->sole();
        $this->assertSame(['places', $place->id, 'latitude', 'https://www.geonames.org/993800', 'CC-BY-4.0'], [$source->subject_table, $source->subject_id, $source->field, $source->source_iri, $source->licence]);

        $person = $this->mention('PERSON', 'Jan Botha');
        $this->actingAs($this->admin)->post(route('authority.create', $person), ['fields' => ['authorized_form_of_name' => 'Botha, Jan']])->assertSessionHasErrors('decision');
    }

    public function test_a_name_is_given_to_another_archivist_and_settings_are_for_administrators(): void
    {
        $mention = $this->mention('PERSON', 'Maria Botha');
        $this->actingAs($this->admin)->post(route('authority.assign'), ['archivist' => $this->colleague->id, 'mentions' => [$mention->id], 'note' => 'You know the Bothas.'])->assertRedirect();
        $this->assertSame($this->colleague->id, $mention->fresh()->assigned_to_id);
        Mail::assertQueued(AuthorityAssignmentMail::class, fn ($m) => $m->hasTo('thandi@riverside.example.org'));
        $this->actingAs($this->colleague)->get(route('authority.queue', ['mine' => 1]))->assertOk()->assertSee('Maria Botha');
        $this->actingAs($this->admin)->get(route('authority.queue', ['mine' => 1]))->assertOk()->assertDontSee('Maria Botha');
        $translator = app(AccountService::class)->create($this->institution, 'Translator', 'translator@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($translator, 'translator');
        $this->actingAs($this->admin)->post(route('authority.assign'), ['archivist' => $translator->id, 'mentions' => [$mention->id]])->assertSessionHasErrors('archivist');

        $this->actingAs($this->editor)->get(route('authority.settings'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('authority.settings'))->assertOk()->assertSee('daughter of')->assertSee('dogter van')->assertSee('not yet searched');
    }

    private function mention(string $type, string $value): AuthorityMention
    {
        app(ProposalService::class)->propose($this->letter, $this->admin, null, ['source' => 'api-test', 'entities' => [
            ['type' => 'DATE', 'value' => '1902'], ['type' => 'GPE', 'value' => 'Pretoria'], ['type' => $type, 'value' => $value]]]);

        return app(Mentions::class)->promote(Proposal::where('value', $value)->sole());
    }
}
