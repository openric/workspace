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
use App\Relations\RelationTypes;
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

    public function test_messages_and_validation_errors_come_in_the_language_of_the_interface(): void
    {
        $this->editor->update(['interface_language' => 'af']);
        $editor = $this->editor->fresh();
        // Laravel's validation messages, with the field's Afrikaans name.
        $this->actingAs($editor)->post(self::SITE.'/account/api-keys', ['name' => ''])
            ->assertSessionHasErrors(['name' => 'Die naam-veld is verpligtend.']);
        // A message written in code.
        $this->actingAs($editor)->post(self::SITE.'/account/password', ['current_password' => 'wrong', 'password' => 'a new long password', 'password_confirmation' => 'a new long password'])
            ->assertSessionHasErrors(['current_password' => 'Dit is nie u huidige wagwoord nie.']);
        $this->actingAs($editor)->post(self::SITE.'/account/password', ['current_password' => 'correct horse battery staple', 'password' => 'a new long password', 'password_confirmation' => 'a new long password'])
            ->assertSessionHas('status', 'U wagwoord is verander.');
    }

    public function test_the_institution_chooses_the_languages_it_offers(): void
    {
        $institution = Institution::where('base_iri', self::SITE)->firstOrFail();
        // Offered only English: Afrikaans is not taken, and there is no switch.
        $institution->update(['interface_languages' => ['en']]);
        $this->get(self::SITE.'/discover?lang=af')->assertSee('Search the catalogue')->assertDontSee('name="lang"', false);

        // An administrator offers more, under Settings; English stays whatever is sent.
        $admin = app(AccountService::class)->create($institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $this->actingAs($admin)->get(self::SITE.'/settings')->assertOk()->assertSee('isiZulu')->assertSee('Sesotho');
        $this->actingAs($admin)->post(self::SITE.'/settings', ['name' => 'Riverside Archives', 'interface_languages' => ['af', 'zu']])->assertRedirect();
        $this->assertSame(['en', 'af', 'zu'], $institution->fresh()->interface_languages);
        $this->actingAs($admin)->post(self::SITE.'/settings', ['name' => 'Riverside Archives', 'interface_languages' => ['xx']])->assertSessionHasErrors('interface_languages.0');

        // The switch names each language in itself; a string isiZulu does not have yet is shown in English.
        auth()->logout();
        $this->flushSession();
        $page = $this->get(self::SITE.'/discover?lang=zu')->assertOk()->assertSee('<html lang="zu">', false)->assertSee('>isiZulu<', false)->assertSee('>Afrikaans<', false);
        $page->assertSee(json_decode(file_get_contents(lang_path('zu.json')), true)['Search']);
        $page->assertSee('Search names, titles and reference codes');
    }

    public function test_every_translated_string_has_its_afrikaans(): void
    {
        $af = json_decode(file_get_contents(base_path('lang/af.json')), true);
        $keys = [];
        foreach (['resources/views', 'app'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $keys = [...$keys, ...$this->translationKeys(file_get_contents($file->getPathname()))];
                }
            }
        }
        // Every bundled relation type reads in Afrikaans (relation_type_phrases).
        $this->assertSame([], array_keys(array_filter(RelationTypes::all(null), fn (array $type) => ! isset($type['phrases']['af']))), 'relation types with no Afrikaans wording');
        // Translated where they are shown: the storage environment labels (__($label)) and the publication messages (PublicationController).
        $keys = [...$keys, 'Lowest temperature (°C)', 'Highest temperature (°C)', 'Lowest relative humidity (%)', 'Highest relative humidity (%)',
            ...array_merge(...array_map(fn ($done) => ["Nothing to mark $done.", ":count value $done.", ":count values $done."], ['reviewed', 'published', 'withdrawn']))];
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

    /**
     * The keys of a file: every quoted string in the first argument of each __() call, so a key chosen at run time
     * (__($n === 1 ? 'one' : 'many')) counts too; strings that are array keys or indexes or compared with are not keys.
     *
     * @return list<string>
     */
    private function translationKeys(string $source): array
    {
        $keys = [];
        preg_match_all('/(?<![\w>$])__\(/', $source, $calls, PREG_OFFSET_CAPTURE);
        foreach ($calls[0] as [$call, $at]) {
            [$i, $depth, $quote, $arg] = [$at + strlen($call), 0, null, ''];
            for (; $i < strlen($source); $i++) {
                $c = $source[$i];
                if ($quote !== null) {
                    $arg .= $c;
                    if ($c === '\\') {
                        $arg .= $source[++$i];
                    } elseif ($c === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if ($c === "'" || $c === '"') {
                    $quote = $c;
                } elseif ($c === '(' || $c === '[') {
                    $depth++;
                } elseif ($c === ')' || $c === ']' || $c === ',') {
                    if ($depth === 0) {
                        break;
                    }
                    if ($c !== ',') {
                        $depth--;
                    }
                }
                $arg .= $c;
            }
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $arg, $literals, PREG_OFFSET_CAPTURE);
            foreach ($literals[1] as $n => [$literal, $offset]) {
                $before = rtrim(substr($arg, 0, $literals[0][$n][1]));
                $after = ltrim(substr($arg, $literals[0][$n][1] + strlen($literals[0][$n][0])));
                if ($literal === '' || ($before !== '' && preg_match('/(\[|\(|===|==|!==)$/', $before)) || preg_match('/^(\]|=>|===|==|!==)/', $after)) {
                    continue;
                }
                $keys[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $literal);
            }
        }

        return $keys;
    }
}
