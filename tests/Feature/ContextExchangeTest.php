<?php

// OpenRiC Workspace - authority records as EAC-CPF and activities as EAC-F, out and back in (roadmap phase 11a):
// what the Workspace exports reads back unchanged and writes nothing, EAC-F is valid against the EAS 2026-07 schema,
// relations keep their direction, a ZIP of every record imports whole, and what a file holds that the Workspace has
// no place for is listed, never lost silently.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\EacF;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Import;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Relation;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContextExchangeTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        foreach (['role-type', 'activity-type'] as $fixture) {
            app(SkosImporter::class)->import(file_get_contents(base_path("tests/Fixtures/$fixture.ttl")));
        }
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
    }

    private function upload(string $content, string $format, string $name = 'file.xml', bool $commit = true): Import
    {
        $location = $this->actingAs($this->editor)->post('/imports', ['file' => UploadedFile::fake()->createWithContent($name, $content), 'format' => $format, 'language' => 'en'])
            ->assertRedirect()->assertSessionHasNoErrors()->headers->get('Location');
        $import = Import::findOrFail(basename($location));
        if ($commit) {
            $this->actingAs($this->editor)->post("/imports/{$import->id}/commit")->assertRedirect(route('imports.show', $import));
        }

        return $import->fresh();
    }

    /** The file without what changes each time it is made: when, and by whom. */
    private function stable(string $xml): string
    {
        return preg_replace('~<maintenanceHistory>.*</maintenanceHistory>~s', '', preg_replace('~maintenanceStatus(="[a-z]+"|>[a-z]+</maintenanceStatus>)~', '', $xml));
    }

    private function relations(): array
    {
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = $records->create($this->institution, Vocab::BASE.'level-of-description/fonds', 'Council records', 'en', null, $holder, 'C');
        $council = app(AgentService::class)->create($this->institution, 'corporate-body', 'Riverside Town Council', 'en', 'RTC');
        $committee = app(AgentService::class)->create($this->institution, 'corporate-body', 'Licensing Committee', 'en');

        return [$fonds, $council, $committee];
    }

    public function test_an_authority_record_reads_back_from_eac_cpf_unchanged_with_its_relations_the_right_way_round(): void
    {
        [$fonds, $council, $committee] = $this->relations();
        $agents = app(AgentService::class);
        $agents->describe($committee, ['agent-parallel-name' => 'Lisensiekomitee', 'agent-history' => "Set up in 1910.\n\nDissolved in 1950.", 'agent-mandates' => 'Trading Act 1910',
            'agent-sources' => 'Council minutes'], 'en');
        app(DateService::class)->add($committee, ['property' => 'existence', 'normalized' => '1910/1950']);
        // The committee is a subdivision of the council: the direction matters.
        app(RelationService::class)->create($council, 'group-subdivision', $committee, [], $this->editor);
        app(RelationService::class)->create($fonds, 'creation', $committee, [], $this->editor);
        $audit = DB::table('audit_log')->count();

        $xml = $this->actingAs($this->editor)->get("/agents/{$committee->id}/eac-cpf")->assertOk()->getContent();
        $this->assertStringContainsString('xlink:role="target"', $xml);
        $import = $this->upload($xml, 'eac-cpf');
        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'rejected' => 0], $import->report['actions']);
        $this->assertSame([], collect($import->report['issues'])->where('kind', '!=', 'note')->values()->all(), 'every field read');
        $this->assertSame([$audit, 2], [DB::table('audit_log')->count(), Relation::count()], 'nothing written');
        $this->assertSame($this->stable($xml), $this->stable($this->actingAs($this->editor)->get("/agents/{$committee->id}/eac-cpf")->getContent()));

        // Without its IRI, in a file of its own, it is a new authority record with every name, text, date and relation.
        $copy = str_replace([$committee->iri, 'Licensing Committee', 'Lisensiekomitee'], ['https://elsewhere.example.org/agent/9', 'Hawkers Committee', 'Smouskomitee'], $xml);
        $this->assertEquals(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $this->upload($copy, 'eac-cpf')->report['actions']);
        $new = Agent::whereIn('id', Name::where(['subject_table' => 'agents', 'value' => 'Hawkers Committee'])->select('subject_id'))->sole();
        $this->assertSame(["Set up in 1910.\n\nDissolved in 1950.", 'Smouskomitee', 'Trading Act 1910', 'Council minutes', '1910/1950'],
            [$new->described('agent-history', ['en']), $new->described('agent-parallel-name', ['en']), $new->described('agent-mandates', ['en']), $new->described('agent-sources', ['en']),
                $new->dates()->sole()->normalized_value]);
        $this->assertTrue(Relation::where(['source_id' => $council->id, 'target_id' => $new->id])->whereHas('relationType', fn ($q) => $q->where('notation', 'group-subdivision'))->exists(),
            'the council is still the source');
        $this->assertTrue(Relation::where(['source_id' => $fonds->id, 'target_id' => $new->id])->exists());
    }

    public function test_an_activity_is_valid_eac_f_and_reads_back_unchanged(): void
    {
        [$fonds, $council] = $this->relations();
        $activity = app(ActivityService::class)->create($this->institution, 'Licensing of traders', 'en', Concept::where('iri', Activity::TYPES.'production')->value('id'), $this->editor);
        app(ActivityService::class)->describe($activity, ['activity-parallel-name' => 'Lisensiering van handelaars', 'activity-description' => 'Granting trading licences.',
            'activity-history' => 'From 1910.', 'activity-legislation' => 'Trading Act 1910', 'activity-classification' => 'Regulation', 'activity-sources' => 'Council minutes'], 'en', $this->editor);
        app(DateService::class)->add($activity, ['property' => 'associated', 'normalized' => '1910/1950']);
        $inspection = app(ActivityService::class)->create($this->institution, 'Inspection of premises', 'en', null, $this->editor);
        app(RelationService::class)->create($activity, 'performance', $council, ['from' => '1910'], $this->editor);
        app(RelationService::class)->create($activity, 'result', $fonds, [], $this->editor);
        app(RelationService::class)->create($inspection, 'subevent', $activity, [], $this->editor);

        $xml = $this->actingAs($this->editor)->get("/activities/{$activity->id}/eac-f")->assertOk()->getContent();
        libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadXML($xml);
        $valid = $document->relaxNGValidate(base_path('tests/Fixtures/eas-2026-07/eaf.rng'));
        $this->assertTrue($valid, 'valid EAF 1.0.0: '.collect(libxml_get_errors())->map(fn ($e) => trim($e->message).' (line '.$e->line.')')->join('; '));
        libxml_clear_errors();
        $this->assertStringContainsString('xmlns="'.EacF::NAMESPACE.'"', $xml);

        $audit = DB::table('audit_log')->count();
        $import = $this->upload($xml, 'eac-f');
        $this->assertEquals(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'rejected' => 0], $import->report['actions']);
        $this->assertSame([], collect($import->report['issues'])->where('kind', '!=', 'note')->values()->all(), 'every field read');
        $this->assertSame([$audit, 3], [DB::table('audit_log')->count(), Relation::count()]);
        $this->assertSame($this->stable($xml), $this->stable($this->actingAs($this->editor)->get("/activities/{$activity->id}/eac-f")->getContent()));

        $copy = str_replace([$activity->iri, 'Licensing of traders'], ['https://elsewhere.example.org/activity/9', 'Licensing of hawkers'], $xml);
        $this->assertEquals(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0], $this->upload($copy, 'eac-f')->report['actions']);
        $new = Activity::whereIn('id', Name::where(['subject_table' => 'activities', 'value' => 'Licensing of hawkers'])->select('subject_id'))->sole();
        $this->assertSame(['Granting trading licences.', 'Lisensiering van handelaars', 'Regulation', '1910/1950', Activity::TYPES.'production'],
            [$new->described('activity-description', ['en']), $new->described('activity-parallel-name', ['en']), $new->described('activity-classification', ['en']),
                $new->dates()->sole()->normalized_value, $new->activityType?->iri]);
        $this->assertTrue(Relation::where(['source_id' => $new->id, 'target_id' => $council->id])->exists(), 'performed by the council');
        $this->assertTrue(Relation::where(['source_id' => $inspection->id, 'target_id' => $new->id])->exists(), 'the inspection is still its subevent');
    }

    public function test_a_zip_of_every_record_imports_whole_and_what_has_no_place_is_listed(): void
    {
        [, $council, $committee] = $this->relations();
        $zip = file_get_contents($this->actingAs($this->editor)->get('/exports/eac-cpf.zip')->assertOk()->baseResponse->getFile()->getPathname());
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $zip);
        $archive = new \ZipArchive;
        $archive->open($path);
        $this->assertSame(2, $archive->numFiles);
        // One file a stranger wrote, with an element the Workspace has no place for, and one that is not EAC-CPF at all.
        $archive->addFromString('stranger.xml', '<eac-cpf xmlns="urn:isbn:1-931666-33-4"><control><recordId>X1</recordId></control><cpfDescription><identity><entityType>person</entityType>'
            .'<nameEntry><part>Botha, Anna</part><authorizedForm>local</authorizedForm></nameEntry></identity><description><languagesUsed><languageUsed><language languageCode="afr">Afrikaans</language></languageUsed></languagesUsed></description></cpfDescription></eac-cpf>');
        $archive->addFromString('notes.xml', '<notes>not EAC-CPF</notes>');
        $archive->close();
        $import = $this->upload(file_get_contents($path), 'eac-cpf', 'agents.zip', false);
        $this->assertEquals(['created' => 1, 'updated' => 0, 'unchanged' => 2, 'rejected' => 0], $import->report['actions'], 'a dry run: what would happen');
        $issues = collect($import->report['issues']);
        $this->assertTrue($issues->contains(fn ($i) => $i['field'] === 'languagesUsed' && $i['kind'] === 'left-out' && $i['location'] === 'stranger.xml'));
        $this->assertTrue($issues->contains(fn ($i) => $i['location'] === 'notes.xml' && $i['kind'] === 'rejected'));
        $this->assertSame(2, Agent::count(), 'a dry run writes nothing');
        $this->actingAs($this->editor)->get('/exports/eac-f.zip')->assertOk();
    }
}
