<?php

// OpenRiC Workspace - the interface in more than one language (WS-005, roadmap phase 1): the language chosen by the
// URL, the session, a cookie, the account or the browser; every string the interface translates has its Afrikaans.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterfaceLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://archives.example.org';

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => self::SITE]);
        app(RoleService::class)->seedDefaults($institution);
        $this->editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
    }

    public function test_the_url_chooses_the_language_and_it_is_kept(): void
    {
        $this->get(self::SITE.'/discover')->assertOk()->assertSee('Search the catalogue')->assertHeader('Content-Language', 'en');

        $this->get(self::SITE.'/discover?lang=af')->assertOk()->assertSee('<html lang="af">', false)
            ->assertSee('Deursoek die katalogus')->assertDontSee('Search the catalogue')
            ->assertHeader('Content-Language', 'af')->assertCookie('locale', 'af');
        // Kept for the session, without the parameter.
        $this->get(self::SITE.'/discover')->assertSee('Deursoek die katalogus');
        // A language the interface does not speak is ignored.
        $this->flushSession();
        $this->get(self::SITE.'/discover?lang=xx')->assertSee('Search the catalogue')->assertHeader('Content-Language', 'en');
    }

    public function test_the_browser_chooses_when_nothing_else_has(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,af-ZA;q=0.8,en;q=0.5')->get(self::SITE.'/discover')
            ->assertSee('Deursoek die katalogus')->assertCookieMissing('locale');
        $this->flushSession();
        $this->withHeader('Accept-Language', 'de, fr;q=0.9')->get(self::SITE.'/discover')->assertSee('Search the catalogue');
    }

    public function test_the_switch_keeps_the_choice_for_the_account_and_returns_to_the_page(): void
    {
        $this->actingAs($this->editor)->post(self::SITE.'/language', ['lang' => 'af', 'redirect_to' => '/discover?q=minutes'])
            ->assertRedirect(self::SITE.'/discover?q=minutes')->assertCookie('locale', 'af');
        $this->assertSame('af', $this->editor->fresh()->interface_language);
        $this->post(self::SITE.'/language', ['lang' => 'en', 'redirect_to' => 'https://elsewhere.example.com/'])->assertRedirect(self::SITE);

        // On a new device, the account's choice is the language.
        $this->editor->update(['interface_language' => 'af']);
        $this->flushSession();
        $this->actingAs($this->editor->fresh())->get(self::SITE.'/')->assertSee('Teken uit')->assertSee('Bestuur')->assertSee('Beskryf');
    }

    public function test_every_translated_string_has_its_afrikaans(): void
    {
        $af = json_decode(file_get_contents(base_path('lang/af.json')), true);
        $keys = [];
        foreach (['resources/views', 'app'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)+)'/", file_get_contents($file->getPathname()), $found);
                    $keys = [...$keys, ...array_map(fn ($k) => str_replace("\\'", "'", $k), $found[1])];
                }
            }
        }
        // Translated where they are shown: relation phrases.
        foreach (config('workspace.relation_types') as $type) {
            $keys = [...$keys, $type['forward'], $type['inverse']];
        }
        $missing = array_values(array_diff(array_unique($keys), array_keys($af)));
        $this->assertSame([], $missing, 'strings with no Afrikaans in lang/af.json');
        // Every placeholder of a string is in its translation.
        foreach ($af as $english => $afrikaans) {
            preg_match_all('/:([a-z]+)/', $english, $placeholders);
            foreach ($placeholders[1] as $placeholder) {
                $this->assertStringContainsString(':'.$placeholder, $afrikaans, "the Afrikaans of \"$english\" lacks :$placeholder");
            }
        }
    }
}
