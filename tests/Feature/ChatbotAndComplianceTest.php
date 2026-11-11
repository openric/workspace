<?php

// OpenRiC Workspace - the catalogue's assistant and AI compliance (roadmap phase 17f), after Heratio's ahg-ai-chatbot
// and ahg-ai-compliance: the public assistant exists only where offered; it answers from published records, cited and
// linked, never a draft, with personal information masked before the model sees it and the call kept in the log of
// inferences; with nothing to answer from it asks no model; an answer not found in its sources is said to be so; staff
// ask over drafts they may see; a flagged answer is reviewed, and wrong answers raise an incident; a halted system is
// never called; the register, risks, incidents, models, Annex IV and attestations; old conversations are deleted.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Ai\AiUnavailable;
use App\Ai\Chatbot;
use App\Ai\Compliance;
use App\Ai\Gateway;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\AiIncident;
use App\Models\AiInference;
use App\Models\AiModel;
use App\Models\AiSetting;
use App\Models\AiSystem;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Rights\Embargoes;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotAndComplianceTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $editor;

    private RecordResource $minutes;

    private RecordResource $draft;

    /** @var list<array> what was sent to the gateway */
    private array $sent = [];

    private string $answer = 'The council kept its minutes in bound books [1].';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        config(['workspace.ai.gateway_key' => 'test-key', 'workspace.ai.gateway_url' => 'https://ai.example.org/ai/v1']);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $accounts = app(AccountService::class);
        $this->admin = $accounts->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->admin, 'administrator');
        $this->editor = $accounts->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->editor, 'editor');
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $records = app(RecordService::class);
        $this->minutes = $records->create($this->institution, self::LEVEL.'fonds', 'Minutes of the Riverside council', 'en', null, $holder, 'RPC');
        $records->describe($this->minutes, ['scope-and-content' => 'Bound minute books of the council, 1950 to 1959.'], 'en');
        $this->draft = $records->create($this->institution, self::LEVEL.'item', 'Secret minutes of the council', 'en', $this->minutes, null, 'RPC/9');
        foreach ([$holder, $this->minutes] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        Http::fake(function (ClientRequest $request) {
            $this->sent[] = $request->data();

            return Http::response(['model' => 'qwen3:8b', 'message' => ['role' => 'assistant', 'content' => $this->answer]]);
        });
    }

    private function offer(): void
    {
        AiSetting::where('institution_id', $this->institution->id)->update(['chatbot_public' => true]);
    }

    public function test_the_public_assistant_answers_from_published_records_only_and_masks_personal_information(): void
    {
        $this->get(route('discover.ask'))->assertNotFound();
        $this->offer();
        $this->get(route('discover.ask'))->assertOk()->assertSee('Ask the catalogue')->assertSee('written by AI');
        $this->post(route('discover.ask.store'), ['question' => 'What minutes did the council keep? Write to me at anna@example.org'])->assertRedirect(route('discover.ask'));

        $sent = json_encode($this->sent[0]);
        $this->assertStringContainsString('Minutes of the Riverside council', $sent, 'the published record is a source');
        $this->assertStringNotContainsString('Secret minutes', $sent, 'a draft never is');
        $this->assertStringNotContainsString('anna@example.org', $sent, 'masked before the model sees it');
        $this->assertStringContainsString('[REDACTED:email]', $sent);
        $answer = ChatMessage::whereHas('role', fn ($q) => $q->where('notation', 'assistant'))->sole();
        $this->assertSame(route('discover.record', $this->minutes->id), $answer->sources[0]['url']);
        $this->assertSame('chatbot', $answer->inference_id ? AiInference::find($answer->inference_id)->service->notation : null, 'kept in the log of inferences');
        $this->get(route('discover.ask'))->assertSee('bound books [1]')->assertSee('Minutes of the Riverside council');
    }

    public function test_with_nothing_to_answer_from_no_model_is_asked_and_an_ungrounded_answer_is_said_to_be_so(): void
    {
        $this->offer();
        $this->post(route('discover.ask.store'), ['question' => 'zzzqqq xxyyzz'])->assertRedirect();
        $this->assertSame([], $this->sent);
        $this->get(route('discover.ask'))->assertSee('could not find anything in the catalogue');

        $this->answer = 'Napoleon conquered Australia with elephants and submarines, according to ancient legends everywhere.';
        $this->post(route('discover.ask.store'), ['question' => 'Tell me about the council minutes'])->assertRedirect();
        $this->assertFalse(ChatMessage::whereNotNull('inference_id')->sole()->guardrail['grounded']);
        $this->get(route('discover.ask'))->assertSee('Check this answer:');
    }

    public function test_the_assistant_reads_the_text_of_a_records_pages_the_public_may_have_and_no_other(): void
    {
        $this->offer();
        $programme = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Programme for the spring concert, 1958', 'en', $this->minutes, null, 'RPC/2');
        $page = app(InstantiationService::class)->create($programme, 'Programme, front', 'en', null, 'online', $this->admin);
        app(InstantiationService::class)->describe($page, ['instantiation-transcript' => 'Conductor: Mr E. Fortuin. Accompanist: Mrs J. Botha.'], 'en', $this->admin);
        foreach ([$programme, $page] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        $this->post(route('discover.ask.store'), ['question' => 'Who conducted the spring concert of 1958?'])->assertRedirect();
        $this->assertStringContainsString('Conductor: Mr E. Fortuin', json_encode($this->sent[0]), 'a published page the public may read is a source (#12)');

        // With its files withheld, its text is withheld from the assistant as from everyone else.
        app(Embargoes::class)->impose($programme, ['type' => 'digital-only', 'starts_on' => now()->toDateString()], false, $this->admin);
        $this->post(route('discover.ask.restart'));
        $this->post(route('discover.ask.store'), ['question' => 'Who conducted the spring concert of 1958?'])->assertRedirect();
        $this->assertStringNotContainsString('Fortuin', json_encode(end($this->sent)));
    }

    public function test_the_assistant_searches_with_every_word_of_a_question_before_any_of_them(): void
    {
        $this->offer();
        $records = app(RecordService::class);
        $choir = $records->create($this->institution, self::LEVEL.'item', 'Riverside Choral Society records', 'en', $this->minutes, null, 'RPC/3');
        $helmet = $records->create($this->institution, self::LEVEL.'item', 'Records of a society of antiquaries: a great helm', 'en', $this->minutes, null, 'RPC/4');
        foreach ([$choir, $helmet] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
        $this->post(route('discover.ask.store'), ['question' => 'What records of the Riverside Choral Society do you hold?'])->assertRedirect();
        $sent = json_encode($this->sent[0]);
        $this->assertStringContainsString('[1] Riverside Choral Society records', $sent);
        $this->assertStringNotContainsString('] Records of a society of antiquaries', $sent, 'a record with only some of the words is no source when one has all (#13)');

        // A question no record holds every word of still finds what holds any of them.
        $this->post(route('discover.ask.restart'));
        $this->post(route('discover.ask.store'), ['question' => 'What minutes did the council keep?'])->assertRedirect();
        $this->assertStringContainsString('Minutes of the Riverside council', json_encode(end($this->sent)));
    }

    public function test_staff_ask_over_drafts_they_may_see(): void
    {
        $this->actingAs($this->editor)->get(route('ask'))->assertOk()->assertSee('Ask the assistant');
        $this->actingAs($this->editor)->post(route('ask.store'), ['question' => 'What are the secret minutes?'])->assertRedirect(route('ask'));
        $this->assertStringContainsString('Secret minutes of the council', json_encode($this->sent[0]));
        $this->assertFalse(ChatConversation::sole()->public);
    }

    public function test_a_flagged_answer_is_reviewed_and_wrong_answers_raise_an_incident(): void
    {
        $this->offer();
        $compliance = app(Compliance::class);
        foreach (range(1, 3) as $i) {
            $this->post(route('discover.ask.store'), ['question' => "Council minutes, question $i"]);
            $answer = ChatMessage::whereHas('role', fn ($q) => $q->where('notation', 'assistant'))->whereNull('flagged_at')->sole();
            $this->post(route('discover.ask.flag', $answer), ['note' => 'The books are not bound.'])->assertRedirect();
            $this->actingAs($this->editor)->post(route('ai.answers.review', $answer), ['finding' => 'wrong'])->assertRedirect();
            auth()->logout();
        }
        $this->actingAs($this->editor)->get(route('ai.answers'))->assertOk()->assertSee('The books are not bound.');
        $this->assertCount(1, $compliance->monitor($this->institution));
        $this->assertCount(0, $compliance->monitor($this->institution), 'not twice while one is open');
        $this->assertSame('monitor', AiIncident::sole()->raised_by);
    }

    public function test_a_halted_system_is_never_called(): void
    {
        $this->offer();
        $compliance = app(Compliance::class);
        $systems = $compliance->systems($this->institution);
        $this->assertCount(10, $systems, 'one for each AI service');
        $this->assertSame('limited', $systems->firstWhere('service.notation', 'chatbot')->riskClass->notation);

        $this->actingAs($this->admin)->post(route('ai.systems.halt', $systems->firstWhere('service.notation', 'summarize')), ['reason' => 'Summaries invent dates.'])->assertRedirect();
        try {
            app(Gateway::class)->call($this->institution, 'summarize', 'ollama/api/chat', ['model' => 'qwen3:8b'], ['user' => $this->admin], fn ($a) => ['output' => '']);
            $this->fail('a halted system was called');
        } catch (AiUnavailable $e) {
            $this->assertStringContainsString('halted', $e->getMessage());
        }
        $this->assertSame([], $this->sent);

        $this->actingAs($this->admin)->post(route('ai.systems.halt', $systems->firstWhere('service.notation', 'chatbot')), ['reason' => 'Under review.']);
        auth()->logout();
        $this->get(route('discover.ask'))->assertNotFound();
    }

    public function test_the_register_risks_incidents_models_annex_iv_and_attestations(): void
    {
        $this->actingAs($this->editor)->get(route('ai.compliance'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('ai.compliance'))->assertOk()->assertSee('The catalogue&#039;s assistant', false);
        $system = AiSystem::whereHas('service', fn ($q) => $q->where('notation', 'summarize'))->sole();
        $this->actingAs($this->admin)->post(route('ai.risks.store', $system), ['description' => 'A summary states a date the record does not give', 'severity' => 'medium',
            'likelihood' => 'high', 'use' => 'intended', 'mitigation' => 'Every summary is reviewed.', 'residual' => 'low', 'status' => 'mitigated'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('ai.incidents.store', $system), ['description' => 'A summary dated the fonds 1850.', 'severity' => 'low'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('ai.incidents.resolve', AiIncident::sole()), ['resolution' => 'Rejected in review.'])->assertRedirect();

        // A model is in the register once it has answered.
        $this->offer();
        auth()->logout();
        $this->post(route('discover.ask.store'), ['question' => 'Tell me about the council minutes']);
        $this->actingAs($this->admin)->get(route('ai.compliance'))->assertSee('qwen3:8b');
        $this->assertSame(1, AiModel::sole()->calls);

        $annex = $this->actingAs($this->admin)->get(route('ai.systems.annex', $system))->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=utf-8')->getContent();
        foreach (['1. General description', '4. Risk management (Article 9)', 'A summary states a date', '(resolved 20', '6. Record-keeping (Article 12)'] as $part) {
            $this->assertStringContainsString($part, $annex);
        }

        $this->actingAs($this->editor)->get(route('ai.attestation'))->assertOk()->assertSee('automation bias');
        $this->actingAs($this->editor)->post(route('ai.attest'), ['read' => '1'])->assertRedirect();
        $this->assertNotNull(app(Compliance::class)->attestation($this->editor));
    }

    public function test_old_conversations_are_deleted(): void
    {
        $this->offer();
        $this->post(route('discover.ask.store'), ['question' => 'Tell me about the council minutes']);
        ChatConversation::query()->update(['last_message_at' => now()->subDays(91)]);
        $this->assertSame(1, app(Chatbot::class)->prune());
        $this->assertSame(0, ChatMessage::count());
    }
}
