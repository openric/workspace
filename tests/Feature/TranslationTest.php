<?php

// OpenRiC Workspace - translation (roadmap phase 17c), after Heratio's ahg-translation, against a gateway that answers
// as its NLLB-200 translate route does: a unit's chosen elements are translated one by one, each proposed as the
// element in the other language with its receipt, an element already in that language left alone, and an accepted
// translation is the unit's value in that language; nothing is asked while AI is off; an interface string's own
// wording is proposed by a translator, accepted by an administrator, seen by that institution alone, kept in its
// history and put back to the Workspace's when emptied; and a machine suggestion is offered for a string.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\AiSetting;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\User;
use App\Proposals\ReviewService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $translator;

    private RecordResource $letter;

    /** @var list<array> what the gateway was sent */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        config(['workspace.ai.gateway_key' => 'test-key', 'workspace.ai.gateway_url' => 'https://ai.example.org/ai/v1']);
        [$this->institution, $this->admin] = $this->institution('Riverside Archives', 'riverside');
        $this->translator = app(AccountService::class)->create($this->institution, 'Translator', 'translator@riverside.example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->translator, 'translator');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the bridge', 'en', null, $holder, 'RA1');
        app(RecordService::class)->describe($this->letter, ['scope-and-content' => 'Names the witness at the marriage.', 'archival-history' => 'Kept by the clerk.'], 'en');
        app(RecordService::class)->describe($this->letter, ['archival-history' => 'Deur die klerk bewaar.'], 'af');
        Http::fake(function (ClientRequest $request) {
            $this->sent[] = $request->data();

            return Http::response(['translated' => '[af] '.$request->data()['text'], 'model' => 'nllb-200-distilled-600M']);
        });
    }

    public function test_the_chosen_elements_are_translated_and_proposed_one_by_one(): void
    {
        $this->actingAs($this->admin)->post(route('records.translate.start', $this->letter), ['source' => 'en', 'target' => 'af', 'elements' => ['title']])->assertSessionHasErrors('ai');
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        $this->actingAs($this->admin)->get(route('records.translate', $this->letter))->assertOk()->assertSee('Names the witness at the marriage.')->assertSee('isiZulu');
        $this->actingAs($this->admin)->post(route('records.translate.start', $this->letter), ['source' => 'en', 'target' => 'af', 'elements' => ['title', 'scope-and-content', 'archival-history']])
            ->assertRedirect(route('records.show', $this->letter));

        $this->assertSame([['text' => 'Letter about the bridge', 'source' => 'en', 'target' => 'af'], ['text' => 'Names the witness at the marriage.', 'source' => 'en', 'target' => 'af']],
            $this->sent, 'the archival history already in Afrikaans is left alone');
        $proposals = Proposal::where('origin_label', 'ai-translate')->with('element', 'inference')->get()->keyBy(fn ($p) => $p->element->notation);
        $this->assertSame(['title', 'scope-and-content'], $proposals->keys()->all());
        $this->assertSame(['af', '[af] Names the witness at the marriage.', 'nllb-200-distilled-600M'],
            [$proposals['scope-and-content']->language, $proposals['scope-and-content']->value, $proposals['scope-and-content']->inference->model]);
        $this->assertStringContainsString('from the en scope and content', $proposals['scope-and-content']->evidence);

        app(ReviewService::class)->accept($proposals['scope-and-content'], $this->admin, ['value' => 'Noem die getuie by die huwelik.']);
        $this->assertSame('Noem die getuie by die huwelik.', $this->letter->fresh()->described('scope-and-content', ['af']));
        $this->assertSame('Names the witness at the marriage.', $this->letter->fresh()->described('scope-and-content', ['en']), 'the original stays');

        $this->actingAs($this->admin)->post(route('records.translate.start', $this->letter), ['source' => 'en', 'target' => 'xx', 'elements' => ['title']])->assertSessionHasErrors('target');
    }

    public function test_the_interface_is_worded_by_the_institution_for_itself(): void
    {
        $this->actingAs($this->translator)->get(route('translation.strings', ['locale' => 'af', 'q' => 'Archival descriptions']))->assertOk()->assertSee('Argiefbeskrywings');
        $this->actingAs($this->translator)->post(route('translation.save'), ['locale' => 'af', 'key' => 'Archival descriptions', 'value' => 'Beskrywings van argief'])->assertRedirect();
        $change = DB::table('interface_string_changes')->sole();
        $this->actingAs($this->translator)->post(route('translation.review', [$change->id, 'accept']))->assertForbidden();
        $this->actingAs($this->admin)->get(route('translation.strings', ['locale' => 'af']))->assertSee('Beskrywings van argief');
        $this->actingAs($this->admin)->post(route('translation.review', [$change->id, 'accept']))->assertRedirect();

        $this->assertSame('Beskrywings van argief', $this->wording($this->admin, 'af', 'Archival descriptions'));
        [, $otherAdmin] = $this->institution('Hillside Archives', 'hillside');
        $this->assertSame('Argiefbeskrywings', $this->wording($otherAdmin, 'af', 'Archival descriptions'), 'another institution keeps the Workspace\'s wording');

        $this->actingAs($this->admin)->get(route('translation.history', ['locale' => 'af', 'key' => 'Archival descriptions']))->assertOk()->assertSee('Beskrywings van argief')->assertSee('accepted');
        $this->actingAs($this->admin)->post(route('translation.save'), ['locale' => 'af', 'key' => 'Archival descriptions', 'value' => ''])->assertRedirect();
        $this->assertSame('Argiefbeskrywings', $this->wording($this->admin, 'af', 'Archival descriptions'), 'back to the Workspace\'s');
        $this->actingAs($this->admin)->post(route('translation.save'), ['locale' => 'af', 'key' => 'No such string in the Workspace', 'value' => 'x'])->assertSessionHasErrors('value');
    }

    public function test_a_machine_suggestion_is_offered_for_a_string(): void
    {
        AiSetting::create(['institution_id' => $this->institution->id, 'enabled' => true]);
        $this->actingAs($this->translator)->getJson(route('translation.suggest', ['key' => 'Archival descriptions', 'locale' => 'zu']))->assertOk()
            ->assertJson(['ok' => true, 'translated' => '[af] Archival descriptions']);
        $this->assertSame(['text' => 'Archival descriptions', 'source' => 'en', 'target' => 'zu'], $this->sent[0]);
    }

    private function wording(User $user, string $locale, string $key): string
    {
        $this->actingAs($user);
        app('translator')->setLoaded([]);
        app('translator')->getLoader()->flush();

        return __($key, [], $locale);
    }

    /** @return array{Institution, User} */
    private function institution(string $name, string $host): array
    {
        $institution = Institution::create(['name' => $name, 'base_iri' => "https://{$host}.example.org"]);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', "admin@{$host}.example.org", 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');

        return [$institution, $admin];
    }
}
