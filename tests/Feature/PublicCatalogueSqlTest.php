<?php

// OpenRiC Workspace - what the public may read, decided in SQL (PublicCatalogue::ids(), v0.0.146), is exactly what
// ExportView::readable() decides one entity at a time: over every rule it applies (a published name, deletion, full
// embargoes and their exceptions, inherited and uninherited classifications, community protocols on terms and labels,
// an embargoed authority record, and the Public role's read permission on a record, the units above it, its
// repository and everything, a denial winning at its level).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Api\PublicCatalogue;
use App\Description\AgentService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Embargo;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\RecordClassification;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Relations\RelationService;
use App\Rights\Clearances;
use App\Rights\CommunityProtocols;
use App\Rights\Embargoes;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicCatalogueSqlTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $editor;

    private Repository $riverside;

    private Repository $hill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $this->riverside = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->hill = app(RepositoryService::class)->create($this->institution, 'Hill Archives', 'en', 'HA');
        foreach ([$this->riverside, $this->hill] as $repository) {
            app(PublicationService::class)->apply($repository, 'publish', null, false, $this->editor);
        }
    }

    private function unit(string $title, ?RecordResource $parent = null, ?Repository $holder = null, string $level = 'item', bool $publish = true): RecordResource
    {
        $unit = app(RecordService::class)->create($this->institution, self::LEVEL.$level, $title, 'en', $parent, $parent ? null : ($holder ?? $this->riverside), Str::slug($title));
        if ($publish) {
            app(PublicationService::class)->apply($unit, 'publish', null, false, $this->editor);
        }

        return $unit;
    }

    private function embargo(RecordResource $unit, string $type, array $fields = []): Embargo
    {
        return Embargo::create(['institution_id' => $this->institution->id, 'record_resource_id' => $unit->id,
            'type_id' => Concept::where('iri', Embargoes::TYPES.$type)->value('id'), 'starts_on' => now()->subDay()->toDateString(), ...$fields]);
    }

    private function classify(RecordResource $unit, string $code, bool $inherit): void
    {
        RecordClassification::create(['record_resource_id' => $unit->id, 'level_id' => app(Clearances::class)->levels($this->institution->id)->firstWhere('code', $code)->id,
            'classified_at' => now(), 'inherit_to_children' => $inherit]);
    }

    private function permit(string $kind, bool $grant, ?string $subject = null, ?string $subjectTable = null, ?string $repository = null): void
    {
        $public = Role::where('institution_id', $this->institution->id)->whereHas('template', fn ($q) => $q->where('iri', Vocab::role('public')))->sole();
        Permission::create(['institution_id' => $this->institution->id, 'role_id' => $public->id, 'is_grant' => $grant, 'subject_id' => $subject, 'subject_table' => $subjectTable,
            'repository_id' => $repository, 'kind_id' => Concept::where('iri', Vocab::kind($kind))->value('id'), 'action_id' => Concept::where('iri', Vocab::permission('read'))->value('id')]);
    }

    /** @return array<string, array{sql: list<string>, php: list<string>}> the labels each way decides the public may read, by kind */
    private function both(): array
    {
        // A fresh request and caches, so the check in PHP reads the fixtures as they now stand.
        app()->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.7']));
        app()->forgetScopedInstances();
        Clearances::flush();
        $catalogue = new PublicCatalogue($this->institution);
        $out = [];
        foreach (PublicCatalogue::KINDS as $kind => $class) {
            $label = fn ($entity) => (string) $catalogue->view->label($entity);
            $sql = $class::whereIn('id', $catalogue->ids($kind)->pluck('e.id'))->with(PublicCatalogue::WITH)->get()->map($label)->sort()->values()->all();
            $php = $class::where('institution_id', $this->institution->id)->whereNull('deleted_at')->with(PublicCatalogue::WITH)->get()
                ->filter(fn ($entity) => $catalogue->view->readable($entity))->map($label)->sort()->values()->all();
            $out[$kind] = ['sql' => $sql, 'php' => $php];
        }

        return $out;
    }

    public function test_sql_and_the_check_in_php_agree_on_every_rule(): void
    {
        $open = $this->unit('Open fonds', level: 'fonds');
        $this->unit('Open item', $open);
        // Embargoes: only a full one in force, with no exception for the visitor's address, hides a record.
        $this->embargo($this->unit('Full embargo item', $open), 'full');
        $this->embargo($this->unit('Metadata embargo item', $open), 'metadata-only');
        $excepted = $this->embargo($this->unit('Excepted embargo item', $open), 'full');
        DB::table('embargo_exceptions')->insert(['id' => (string) Str::uuid(), 'embargo_id' => $excepted->id, 'ip_from' => '10.0.0.1', 'ip_to' => '10.0.0.50']);
        $elsewhere = $this->embargo($this->unit('Excepted elsewhere item', $open), 'full');
        DB::table('embargo_exceptions')->insert(['id' => (string) Str::uuid(), 'embargo_id' => $elsewhere->id, 'ip_from' => '192.168.0.1', 'ip_to' => '192.168.0.9']);
        $this->embargo($this->unit('Lifted embargo item', $open), 'full', ['lifted_at' => now()]);
        $this->embargo($this->unit('Future embargo item', $open), 'full', ['starts_on' => now()->addDays(3)->toDateString()]);
        $this->embargo($this->unit('Ended embargo item', $open), 'full', ['starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->subDays(2)->toDateString()]);
        // Classifications: one passed to children hides them too; one not passed hides only its own unit; level 0 hides nothing.
        $secret = $this->unit('Secret series', $open, level: 'series');
        $this->unit('Secret letter', $this->unit('Secret file', $secret, level: 'file'));
        $this->classify($secret, 'SECRET', true);
        $confidential = $this->unit('Confidential series', $open, level: 'series');
        $this->unit('Passed-over letter', $confidential);
        $this->classify($confidential, 'CONFIDENTIAL', false);
        $this->classify($this->unit('Public-level file', $open, level: 'file'), 'PUBLIC', true);
        $lower = $this->unit('Own lower letter', $secret);
        $this->classify($lower, 'PUBLIC', true);
        // Community protocols: a record tagged with a withheld term, and one labelled with a withholding condition.
        $sacred = Concept::where('iri', CommunityProtocols::CONDITIONS.'sacred-secret')->value('id');
        $rites = app(ConceptService::class)->create(app(ConceptService::class)->schemeOfKind($this->institution, 'subject'), ['label' => 'Initiation rites', 'language' => 'en']);
        app(RelationService::class)->create($this->unit('Tagged letter', $open), 'subject', $rites, [], $this->editor);
        DB::table('concept_protocols')->insert(['id' => (string) Str::uuid(), 'institution_id' => $this->institution->id, 'concept_id' => $rites->id, 'access_condition_id' => $sacred]);
        DB::table('community_labels')->insert(['id' => (string) Str::uuid(), 'institution_id' => $this->institution->id, 'subject_table' => 'record_resources',
            'subject_id' => $this->unit('Labelled letter', $open)->id, 'label_id' => Concept::where('notation', 'tk_s')->value('id'), 'access_condition_id' => $sacred, 'applied_by_id' => Concept::where('iri', 'like', Vocab::BASE.'label-applier/%')->value('id')]);
        // Not published, and deleted.
        $this->unit('Draft letter', $open, publish: false);
        DB::table('record_resources')->where('id', $this->unit('Deleted letter', $open)->id)->update(['deleted_at' => now()]);
        // The Public role: denied a repository's records, granted one series inside it, which its units follow; denied one fonds; denied a repository.
        $hill = $this->unit('Hill fonds', holder: $this->hill, level: 'fonds');
        $granted = $this->unit('Hill series', $hill, level: 'series');
        $this->unit('Hill item', $granted);
        $this->unit('Hill other item', $hill);
        $this->permit('description', false, repository: $this->hill->id);
        $this->permit('description', true, $granted->id, 'record_resources');
        $denied = $this->unit('Denied fonds', level: 'fonds');
        $this->unit('Denied child', $this->unit('Denied series', $denied, level: 'series'));
        $this->permit('description', false, $denied->id, 'record_resources');
        $this->permit('repository', false, $this->hill->id, 'repositories');
        // Authority records under embargo until a date to come, and one whose embargo has passed.
        $agents = app(AgentService::class);
        $agents->create($this->institution, 'person', 'Open person', 'en');
        $agents->create($this->institution, 'person', 'Embargoed person', 'en')->update(['embargo_until' => now()->addMonth()->toDateString()]);
        $agents->create($this->institution, 'person', 'Formerly embargoed person', 'en')->update(['embargo_until' => now()->subMonth()->toDateString()]);
        foreach (Agent::all() as $agent) {
            app(PublicationService::class)->apply($agent, 'publish', null, false, $this->editor);
        }

        $both = $this->both();
        foreach ($both as $kind => ['sql' => $sql, 'php' => $php]) {
            $this->assertSame($php, $sql, "$kind: SQL and PHP differ");
        }
        // And the fixtures did what each rule says, so the agreement is not an agreement on nothing.
        $this->assertSame(['Ended embargo item', 'Excepted embargo item', 'Future embargo item', 'Hill item', 'Hill series', 'Lifted embargo item', 'Metadata embargo item',
            'Open fonds', 'Open item', 'Own lower letter', 'Passed-over letter', 'Public-level file'], $both['records']['sql']);
        $this->assertSame(['Riverside Archives'], $both['repositories']['sql']);
        $this->assertSame(['Formerly embargoed person', 'Open person'], $both['agents']['sql']);

        // With no read permission for the Public role at all, nothing of the kind is public.
        Permission::where('kind_id', Concept::where('iri', Vocab::kind('authority'))->value('id'))->delete();
        $this->assertSame([], $this->both()['agents']['sql']);
    }

    public function test_a_long_list_is_counted_and_paged_in_the_database(): void
    {
        $fonds = $this->unit('Open fonds', level: 'fonds');
        foreach (range(1, 6) as $n) {
            $this->unit("Item $n", $fonds);
        }
        $this->embargo(RecordResource::where('parent_id', $fonds->id)->firstOrFail(), 'full');
        $catalogue = new PublicCatalogue($this->institution);

        $this->assertSame(6, $catalogue->query('records')->count());
        $this->assertSame(['Open fonds'], $catalogue->query('records')->whereNull('parent_id')->with(PublicCatalogue::WITH)->get()->map(fn ($u) => $catalogue->view->label($u))->all());
        $this->assertSame(2, $catalogue->query('records')->orderBy('e.created_at')->limit(2)->get()->count());
        $this->assertSame(['Item 3'], $catalogue->query('records', 'Item 3')->with(PublicCatalogue::WITH)->get()->map(fn ($u) => $catalogue->view->label($u))->all());
    }
}
