<?php

// OpenRiC Workspace - asking a model to help describe (roadmap phase 17b1), against a gateway that answers as the AHG
// AI gateway's NER and Ollama chat routes do: names found in a unit's description and the transcript of its page are
// proposed as access points and dates, with spaCy's score where it gives one and the passage they were found in, and
// accepted as any proposal is; a summary of its pages is proposed as its scope and content, without the model's
// thinking; the condition of the object a picture shows is proposed as the instantiation's physical characteristics,
// graded from the condition-grade vocabulary, and written to the instantiation when accepted; each answer names its
// receipt; and nothing is asked while AI is off.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\AiInference;
use App\Models\AiSetting;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\User;
use App\Proposals\ReviewService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class AiAssistTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const MINUTES = 'Maria Botha of the Riverside Parish Council wrote from Pretoria on 3 May 1902 about the bridge.';

    private Institution $institution;

    private User $admin;

    private User $editor;

    private RecordResource $letter;

    private Instantiation $page;

    /** @var list<array{string, array}> the routes asked and what was sent */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        config(['workspace.ai.gateway_key' => 'test-key', 'workspace.ai.gateway_url' => 'https://ai.example.org/ai/v1']);
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://riverside.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the bridge', 'en', $fonds, null, '1');
        app(RecordService::class)->describe($this->letter, ['archival-history' => 'Kept by the parish clerk.'], 'en');
        $image = new Imagick;
        $image->newImage(300, 200, new ImagickPixel('#e8dcc0'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'page').'.png';
        file_put_contents($path, $image->getImageBlob());
        $this->page = app(InstantiationService::class)->upload($this->letter, $path, 'letter-p1.png', 'en', 'Page one', null, 'online', $this->editor);
        app(InstantiationService::class)->describe($this->page, ['instantiation-transcript' => self::MINUTES], 'en', $this->editor);
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->sent[] = [$path, $request->data()];
            if ($path === '/ai/v1/ner/extract') {
                $at = fn (string $s) => mb_strpos($request->data()['text'], $s);

                return Http::response(['success' => true, 'model' => 'en_core_web_sm', 'model_version' => '3.7.1', 'entities_v2' => [
                    ['value' => 'Maria Botha', 'type' => 'PERSON', 'offset_start' => $at('Maria'), 'offset_end' => $at('Maria') + 11, 'score' => null],
                    ['value' => 'Riverside Parish Council', 'type' => 'ORG', 'offset_start' => $at('Riverside'), 'offset_end' => $at('Riverside') + 24, 'score' => 0.91],
                    ['value' => 'Pretoria', 'type' => 'GPE', 'offset_start' => $at('Pretoria'), 'offset_end' => $at('Pretoria') + 8],
                    ['value' => 'Maria Botha', 'type' => 'PERSON'],
                    ['value' => 'the bridge', 'type' => 'WORK_OF_ART'],
                ]]);
            }
            $data = $request->data();
            $content = ($data['format'] ?? null) === 'json'
                ? json_encode(['grade' => 'Fair', 'damage' => ['foxing', 'a tear at the lower edge'], 'notes' => 'Foxing across the top half.'])
                : "<think>The letter is about a bridge.</think>\nMaria Botha wrote to the Riverside Parish Council in May 1902 about the bridge.";

            return Http::response(['model' => $data['model'], 'message' => ['role' => 'assistant', 'content' => $content]]);
        });
    }

    public function test_names_are_found_and_proposed_with_their_passage_and_score(): void
    {
        $this->actingAs($this->editor)->post(route('records.ask', [$this->letter, 'names']))->assertSessionHasErrors('ai');
        $this->assertSame([], $this->sent, 'nothing is asked while AI is off');
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        $this->actingAs($this->editor)->get(route('records.show', $this->letter))->assertSee('Find names with AI');
        $this->actingAs($this->editor)->post(route('records.ask', [$this->letter, 'names']))->assertRedirect();

        [$path, $body] = $this->sent[0];
        $this->assertSame('/ai/v1/ner/extract', $path);
        $this->assertStringContainsString('Kept by the parish clerk.', $body['text']);
        $this->assertStringContainsString(self::MINUTES, $body['text'], 'the transcripts of its pages are read too');
        $proposals = Proposal::where('origin_label', 'ai-ner')->with('kind')->get()->keyBy('value');
        $this->assertSame(['Maria Botha', 'Pretoria', 'Riverside Parish Council'], $proposals->keys()->sort()->values()->all(), 'repeated and unknown kinds are left out');
        $this->assertSame(['agent', 'agent', 'place'], [$proposals['Maria Botha']->kind->notation, $proposals['Riverside Parish Council']->kind->notation, $proposals['Pretoria']->kind->notation]);
        $this->assertNull($proposals['Maria Botha']->confidence, 'no score is made up');
        $this->assertSame(0.91, $proposals['Riverside Parish Council']->confidence);
        $this->assertStringContainsString('wrote from Pretoria on 3 May', $proposals['Pretoria']->evidence);
        $receipt = AiInference::sole();
        $this->assertSame(['en_core_web_sm', '3.7.1', 'ner'], [$receipt->model, $receipt->model_version, $receipt->service->notation]);
        $this->assertSame([$receipt->id], $proposals->pluck('inference_id')->unique()->values()->all());

        $place = app(ReviewService::class)->accept($proposals['Pretoria'], $this->admin);
        $this->assertSame('Pretoria', $place->described('authorised-name', ['en']));
    }

    public function test_a_summary_of_the_pages_is_proposed_as_scope_and_content(): void
    {
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true, 'chat_model' => 'qwen3:14b']);
        $this->actingAs($this->editor)->post(route('records.ask', [$this->letter, 'summary']))->assertRedirect();
        [$path, $body] = $this->sent[0];
        $this->assertSame(['/ai/v1/ollama/api/chat', 'qwen3:14b', false], [$path, $body['model'], $body['think']]);
        $this->assertStringContainsString(self::MINUTES, $body['messages'][0]['content']);
        $proposal = Proposal::where('origin_label', 'ai-summary')->with('element')->sole();
        $this->assertSame('scope-and-content', $proposal->element->notation);
        $this->assertSame('Maria Botha wrote to the Riverside Parish Council in May 1902 about the bridge.', $proposal->value, 'without the model\'s thinking');
        $this->assertStringContainsString('transcript of 1 page', $proposal->evidence);
        $this->assertSame('summarize', $proposal->inference->service->notation);
    }

    public function test_the_condition_a_picture_shows_is_proposed_and_written_to_the_instantiation(): void
    {
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        $this->actingAs($this->editor)->get(route('instantiations.show', $this->page))->assertSee('Assess its condition with AI');
        $this->actingAs($this->editor)->post(route('instantiations.assess-condition', $this->page))->assertRedirect();
        [, $body] = $this->sent[0];
        $this->assertSame('json', $body['format']);
        $this->assertNotEmpty($body['messages'][0]['images'][0], 'the picture is sent');
        $this->assertStringContainsString('excellent, good, fair, poor, unacceptable', $body['messages'][0]['content'], 'the grades come from the vocabulary');
        $proposal = Proposal::where('origin_label', 'ai-condition')->with('element')->sole();
        $this->assertSame(['instantiation-physical-characteristics', $this->page->id], [$proposal->element->notation, $proposal->instantiation_id]);
        $this->assertSame('Condition: fair. Damage seen: foxing, a tear at the lower edge. Foxing across the top half.', $proposal->value);
        $this->assertStringContainsString('not a trained condition model', $proposal->evidence);

        app(ReviewService::class)->accept($proposal, $this->admin);
        $this->assertStringStartsWith('Condition: fair.', $this->page->fresh()->described('instantiation-physical-characteristics', ['en']));
        $this->assertNull($this->letter->fresh()->described('physical-characteristics', ['en']), 'written to the instantiation, not the unit');
    }

    public function test_only_who_may_update_the_description_asks(): void
    {
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        $viewer = app(AccountService::class)->create($this->institution, 'Translator', 'translator@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($viewer, 'translator');
        $this->actingAs($viewer)->post(route('records.ask', [$this->letter, 'names']))->assertForbidden();
        $this->actingAs($viewer)->post(route('instantiations.assess-condition', $this->page))->assertForbidden();
        $this->actingAs($this->editor)->post('/records/'.$this->letter->id.'/ask/translate')->assertNotFound();
        $this->assertSame([], $this->sent);
    }
}
