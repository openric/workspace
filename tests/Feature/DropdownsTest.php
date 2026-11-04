<?php

// OpenRiC Workspace - the Dropdown manager (roadmap phase 20d), after Heratio's ahg-dropdown-manage: an institution words
// a bundled list its way in each language, orders it and gives it a default, and its forms then offer it so, while
// another institution's do not change and the term keeps its IRI; in an open list it adds terms of its own, which nobody
// else sees, and hides terms, which a fixed list refuses; only who administers the institution manages it; and no code
// relies on the terms of a list the config calls open.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Models\Vocabulary;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use App\Vocabulary\Dropdowns;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DropdownsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Institution $other;

    private User $admin;

    private User $editor;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        [$this->institution, $this->admin, $this->editor] = $this->institution('Riverside Archives', 'riverside');
        [$this->other, $this->otherAdmin] = $this->institution('Hillside Archives', 'hillside');
    }

    protected function tearDown(): void
    {
        Dropdowns::flush();
        parent::tearDown();
    }

    public function test_an_institution_words_orders_and_defaults_a_list_for_itself_alone(): void
    {
        $types = $this->vocabulary('repository-type');
        $national = $this->concept('repository-type', 'national');
        $regional = $this->concept('repository-type', 'regional');
        $this->actingAs($this->admin)->get(route('dropdowns.index'))->assertOk()->assertSee('repository-type');
        $this->actingAs($this->admin)->get(route('dropdowns.edit', $types))->assertOk()->assertSee('An open list');
        $this->actingAs($this->admin)->post(route('dropdowns.save', $types), ['default' => $regional->id, 'terms' => [
            $national->id => ['labels' => ['en' => 'State archive', 'af' => 'Staatsargief'], 'sort_order' => 1],
            $regional->id => ['labels' => ['en' => ''], 'sort_order' => 5],
        ]])->assertRedirect();
        Dropdowns::flush();

        $choices = Concept::choices(Vocab::BASE.'repository-type/')->get();
        $this->assertSame([$regional->id, $national->id], $choices->take(2)->pluck('id')->all(), 'the default first, then by the order given');
        $this->assertSame('State archive', $national->fresh()->label(['en']));
        $this->assertSame('Staatsargief', $national->fresh()->label(['af']));
        $this->assertSame(Vocab::BASE.'repository-type/national', $national->fresh()->iri, 'the term keeps its IRI');
        $this->assertTrue(DB::table('vocabulary_overlays')->where('concept_id', $regional->id)->exists(), 'an order alone is kept');

        $this->actingAs($this->otherAdmin);
        Dropdowns::flush();
        $this->assertNotSame('State archive', $national->fresh()->label(['en']), 'another institution is not changed');
        $this->assertNotSame($regional->id, Concept::choices(Vocab::BASE.'repository-type/')->first()->id);
    }

    public function test_an_open_list_takes_terms_of_its_own_and_hides_terms(): void
    {
        $types = $this->vocabulary('repository-type');
        $national = $this->concept('repository-type', 'national');
        $this->actingAs($this->admin)->post(route('dropdowns.add', $types), ['label' => 'Church archive', 'language' => 'en', 'broader' => $national->id])->assertRedirect();
        $own = Concept::where(['vocabulary_id' => $types->id, 'notation' => 'church-archive'])->sole();
        $this->assertSame($this->institution->id, $own->institution_id);
        $this->assertSame('https://riverside.example.org/vocab/repository-type/church-archive', $own->iri);
        $this->assertSame($national->id, $own->broader_id);
        $this->actingAs($this->admin)->post(route('dropdowns.add', $types), ['label' => 'Church archive', 'language' => 'en'])->assertSessionHasErrors('label');

        $this->actingAs($this->admin)->post(route('dropdowns.save', $types), ['terms' => [$national->id => ['hidden' => 1]]])->assertRedirect();
        Dropdowns::flush();
        $ours = Concept::choices(Vocab::BASE.'repository-type/')->pluck('id');
        $this->assertTrue($ours->contains($own->id));
        $this->assertSame($own->id, Concept::notated(Vocab::BASE.'repository-type/', 'church-archive')?->id, 'a form that posts the code finds it');
        $this->assertFalse($ours->contains($national->id), 'a hidden term is no longer offered');

        $this->actingAs($this->otherAdmin);
        Dropdowns::flush();
        $theirs = Concept::choices(Vocab::BASE.'repository-type/')->pluck('id');
        $this->assertFalse($theirs->contains($own->id), 'nobody else sees our own term');
        $this->assertTrue($theirs->contains($national->id));
        $this->assertNull(Concept::notated(Vocab::BASE.'repository-type/', 'church-archive'));
        $this->actingAs($this->otherAdmin)->post(route('dropdowns.add', $types), ['label' => 'Church archive', 'language' => 'en'])->assertRedirect();
        $this->assertSame(2, Concept::where(['vocabulary_id' => $types->id, 'notation' => 'church-archive'])->count(), 'each institution may add the same code');
        $this->actingAs($this->otherAdmin)->post(route('dropdowns.remove', [$types, $own]))->assertSessionHasErrors('label');

        $this->actingAs($this->admin)->post(route('dropdowns.remove', [$types, $own]))->assertRedirect();
        Dropdowns::flush();
        $this->assertNotNull($own->fresh()->deprecated_at, 'removed, but kept for what uses it');
        $this->assertFalse(Concept::choices(Vocab::BASE.'repository-type/')->pluck('id')->contains($own->id));
    }

    public function test_a_fixed_list_is_worded_but_never_added_to_or_hidden(): void
    {
        $types = $this->vocabulary('agent-type');
        $person = $this->concept('agent-type', 'person');
        $this->actingAs($this->admin)->get(route('dropdowns.edit', $types))->assertOk()->assertSee('A fixed list');
        $this->actingAs($this->admin)->post(route('dropdowns.add', $types), ['label' => 'Ship', 'language' => 'en'])->assertSessionHasErrors('label');
        $this->assertFalse(Concept::where(['vocabulary_id' => $types->id, 'notation' => 'ship'])->exists());
        $this->actingAs($this->admin)->post(route('dropdowns.save', $types), ['terms' => [$person->id => ['labels' => ['en' => 'Individual'], 'hidden' => 1]]])->assertRedirect();
        Dropdowns::flush();
        $this->assertTrue(Concept::choices(Vocab::BASE.'agent-type/')->pluck('id')->contains($person->id), 'not hidden');
        $this->assertSame('Individual', $person->fresh()->label(['en']));
        $this->assertContains('person', Vocab::notations('agent-type'), 'what the code validates against is unchanged');
    }

    public function test_only_who_administers_the_institution_manages_its_lists(): void
    {
        $types = $this->vocabulary('repository-type');
        $this->actingAs($this->editor)->get(route('dropdowns.index'))->assertForbidden();
        $this->actingAs($this->editor)->post(route('dropdowns.add', $types), ['label' => 'Church archive', 'language' => 'en'])->assertForbidden();
        $local = app(ConceptService::class)->createScheme($this->institution, 'subject', 'Local subjects', 'en', null, $this->admin);
        $this->actingAs($this->admin)->get(route('dropdowns.edit', $local))->assertNotFound();
    }

    /**
     * A list the config calls open is one the code never names a term of, so a term hidden or worded otherwise breaks
     * nothing; and what resolves a code from a form into it goes through Concept::notated, so a term of the
     * institution's own resolves too.
     */
    public function test_no_code_relies_on_the_terms_of_an_open_list(): void
    {
        $open = (array) config('workspace.open_vocabularies');
        $bundled = array_map(fn ($f) => basename($f, '.ttl'), (array) config('workspace.bundled_vocabularies'));
        $this->assertSame([], array_values(array_diff($open, $bundled)), 'every open list is a bundled one');
        [$named, $resolved] = [[], []];
        foreach (['app', 'routes', 'resources/views', 'config'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                $path = substr($file->getPathname(), strlen(base_path()) + 1);
                foreach ($open as $name) {
                    $q = preg_quote($name, '#');
                    if (preg_match("#['\"/]{$q}/[a-z0-9]|\\(\\s*['\"]{$q}['\"]\\s*,\\s*['\"]|notations\\(\\s*['\"]{$q}['\"]#", $source, $m)) {
                        $named[] = "{$path}: {$m[0]}";
                    }
                    preg_match_all("#(notated\\(\\s*)?(?:Vocab::BASE\\.)?['\"]{$q}/?['\"]\\s*[.,]\\s*\\\$#", $source, $all, PREG_SET_ORDER);
                    // A code joined to the list's IRI, or handed to a helper in a file that still looks a code up by its IRI.
                    $byIri = preg_match("#where\\(\\s*'iri'\\s*,[^;]*\\.\\s*\\\$#", $source) === 1;
                    foreach ($all as $m) {
                        ($m[1] ?? '') === '' && (str_contains($m[0], '/') || $byIri) && $resolved[] = "{$path}: {$m[0]}";
                    }
                }
            }
        }
        $this->assertSame([], $named, 'move a list the code relies on out of workspace.open_vocabularies');
        $this->assertSame([], $resolved, 'resolve a code from a form into an open list with Concept::notated');
    }

    /** @return array{Institution, User, User} */
    private function institution(string $name, string $host): array
    {
        $institution = Institution::create(['name' => $name, 'base_iri' => "https://{$host}.example.org"]);
        app(RoleService::class)->seedDefaults($institution);
        $admin = app(AccountService::class)->create($institution, 'Admin', "admin@{$host}.example.org", 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($admin, 'administrator');
        $editor = app(AccountService::class)->create($institution, 'Editor', "editor@{$host}.example.org", 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'editor');

        return [$institution, $admin, $editor];
    }

    private function vocabulary(string $name): Vocabulary
    {
        return Vocabulary::where('iri', Vocab::BASE.$name.'/')->sole();
    }

    private function concept(string $vocabulary, string $notation): Concept
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->sole();
    }
}
