<?php

// OpenRiC Workspace - preservation planning (roadmap phase 15c, ADR 0022): a master normalised by the rules into a
// preservation master and an access copy beside it in its OCFL object, each a conversion and a PREMIS normalisation
// event, a tool that fails a failure event; a migration plan approved by a second person and run, its files migrated by
// the pathway and checked for fixity; the object store replicated to a folder, compared with it, and a folder that is
// not a storage root refused; and the unit's rights as PREMIS rights statements.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Files\FileStore;
use App\Files\Ocfl\StorageRoot;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\FileFormat;
use App\Models\FormatConversion;
use App\Models\Institution;
use App\Models\MigrationPathway;
use App\Models\NormalisationRule;
use App\Models\PreservationEvent;
use App\Models\RecordResource;
use App\Models\ReplicationTarget;
use App\Models\User;
use App\Preservation\Conversions;
use App\Preservation\MigrationPlans;
use App\Preservation\Replication;
use App\Rights\RightsService;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;
use Tests\TestCase;

class PreservationPlanningTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private RecordResource $letter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-planning-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store.'/objects']);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = $this->administrator('Admin', 'admin@example.org');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', null, $holder, 'L1');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function administrator(string $name, string $email): User
    {
        $user = app(AccountService::class)->create($this->institution, $name, $email, 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($user, 'administrator');

        return $user;
    }

    private function png(string $colour): string
    {
        $image = new Imagick;
        $image->newImage(80, 60, new ImagickPixel($colour));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        return $path;
    }

    private function types(DigitalFile $file): array
    {
        return PreservationEvent::where('digital_file_id', $file->id)->with('type', 'outcome')->get()->map(fn ($e) => $e->type->notation.':'.$e->outcome->notation)->all();
    }

    public function test_a_master_is_normalised_by_the_rules_into_a_preservation_master_and_an_access_copy(): void
    {
        $this->institution->update(['normalise_on_ingest' => true]);
        $this->actingAs($this->admin);
        $scan = app(InstantiationService::class)->upload($this->letter, $this->png('#d8c9a3'), 'letter.png', 'en', 'Scan', null, 'online', $this->admin);
        $scan->load('files.use');
        $master = $scan->file('master');
        $preservation = $scan->file('preservation');
        $access = $scan->file('access');
        $this->assertNotNull($scan->file('reference'), 'the viewing copies are made as before');

        // Heratio's rules: a PNG's preservation master a TIFF, its access copy a JPEG, kept in the instantiation's OCFL object.
        $this->assertSame(['image/tiff', 'letter-preservation.tiff'], [$preservation->mime_type, $preservation->original_name]);
        $this->assertSame('image/jpeg', $access->mime_type);
        $this->assertStringStartsWith(StorageRoot::objectRoot($scan->iri).'/', $preservation->storage_path);
        $this->assertContains('files/preservation/letter-preservation.tiff', array_keys(app(FileStore::class)->ocfl()->inventory($scan->iri)->headVersion()->paths()));
        $this->assertSame($master->sha256, hash_file('sha256', app(FileStore::class)->path($master->storage_path)), 'the master is unchanged');

        $conversion = FormatConversion::where('output_file_id', $preservation->id)->sole();
        $this->assertSame(['completed', 'imagemagick', $master->id, 'preservation'], [$conversion->status->notation, $conversion->tool->notation, $conversion->source_file_id, $conversion->purpose->notation]);
        $this->assertNotNull($conversion->tool_version);
        $this->assertContains('normalization:success', $this->types($preservation));
        $this->assertNotContains('creation:success', $this->types($preservation), 'made by normalisation, not as a viewing copy');
        $event = PreservationEvent::where('digital_file_id', $preservation->id)->whereHas('type', fn ($q) => $q->where('notation', 'normalization'))->sole();
        $this->assertSame($master->id, $event->source_file_id);
        $this->assertStringStartsWith('ImageMagick', $event->agent_name);

        // Read as the master is, delivered with read-master.
        $this->assertTrue(app(InstantiationService::class)->deliverable($this->admin, $preservation));

        // Not made twice.
        $this->assertNull(app(Conversions::class)->normalise($master->fresh(), 'preservation', $this->admin));

        // In PREMIS: the preservation master's source, and the event that made it.
        $xpath = $this->premis();
        $this->assertSame(1, $xpath->query('//p:object[p:objectIdentifier/p:objectIdentifierValue="urn:uuid:'.$preservation->id.'"]/p:relationship[p:relationshipSubType="has source"][p:relatedObjectIdentifier/p:relatedObjectIdentifierValue="urn:uuid:'.$master->id.'"]')->length);
        $this->assertSame(1, $xpath->query('//p:event[p:eventType/@valueURI="http://id.loc.gov/vocabulary/preservation/eventType/nor"][p:linkingObjectIdentifier[p:linkingObjectIdentifierValue="urn:uuid:'.$preservation->id.'"]/p:linkingObjectRole="outcome"]')->length);

        // A tool that cannot make the format is a failed conversion and a failure event on the master: FFmpeg makes no sound of a still image.
        $tool = Concept::where('iri', Vocab::BASE.'conversion-tool/ffmpeg')->value('id');
        NormalisationRule::create(['source_mime' => 'image/png', 'purpose_id' => Concept::where('iri', Vocab::BASE.'normalisation-purpose/preservation')->value('id'),
            'target_format' => 'Nothing', 'target_ext' => 'wav', 'target_mime' => 'audio/x-wav', 'tool_id' => $tool, 'priority' => 1]);
        $this->institution->update(['normalise_on_ingest' => false]);
        $second = app(InstantiationService::class)->upload($this->letter, $this->png('#203040'), 'second.png', 'en', 'Second', null, 'online', $this->admin);
        $failed = app(Conversions::class)->normalise($second->file('master'), 'preservation', $this->admin);
        $this->assertSame('failed', $failed->status->notation);
        $this->assertNotEmpty($failed->error_message);
        $this->assertContains('normalization:failure', $this->types($second->file('master')));
        $this->assertNull($second->fresh()->file('preservation'));

        $this->get(route('preservation.index'))->assertOk()->assertSee('Normalisation rules')->assertSee('letter.png')->assertSee('Nothing');
        // The installation's operators set the rules, pathways and targets.
        config(['workspace.operators' => 'someone-else@example.org']);
        $this->post(route('preservation.rules.store'), ['source_mime' => 'image/webp', 'purpose' => 'preservation', 'target_format' => 'TIFF', 'target_ext' => 'tiff',
            'target_mime' => 'image/tiff', 'tool' => 'imagemagick', 'is_active' => 1])->assertForbidden();
        config(['workspace.operators' => 'admin@example.org']);
        $this->get(route('preservation.index'))->assertOk()->assertSee('Add a rule')->assertSee('Add a pathway')->assertSee('Add a target');
        $this->post(route('preservation.rules.store'), ['source_mime' => 'image/webp', 'purpose' => 'preservation', 'target_format' => 'TIFF', 'target_ext' => 'tiff',
            'target_mime' => 'image/tiff', 'tool' => 'imagemagick', 'options' => '{"quality": 90}', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['quality' => 90], NormalisationRule::where('source_mime', 'image/webp')->sole()->options);
        $this->post(route('preservation.rules.store'), ['purpose' => 'preservation', 'target_format' => 'TIFF', 'target_ext' => 'tiff', 'target_mime' => 'image/tiff', 'tool' => 'imagemagick'])
            ->assertSessionHasErrors('preservation');
        $this->post(route('preservation.pathways.store'), ['source_puid' => 'fmt/40', 'target_puid' => 'fmt/95', 'tool' => 'libreoffice', 'target_ext' => 'pdf',
            'target_mime' => 'application/pdf', 'quality_impact' => 'moderate', 'fidelity_score' => 80])->assertSessionHasNoErrors();
        $this->post(route('preservation.targets.store'), ['name' => 'Off site', 'type' => 'rsync', 'host' => 'backup.example.org', 'username' => 'archive',
            'path' => '/srv/objects', 'ssh_key_path' => '/var/www/.ssh/replica', 'frequency' => 'weekly', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->get(route('preservation.index'))->assertOk()->assertSee('fmt/95')->assertSee('archive@backup.example.org:/srv/objects');
        $this->get(route('instantiations.show', $scan))->assertOk()->assertSee('Preservation master')->assertSee('Normalise the master by the rules');
    }

    public function test_a_migration_plan_is_approved_by_a_second_person_and_run(): void
    {
        $this->actingAs($this->admin);
        $scan = app(InstantiationService::class)->upload($this->letter, $this->png('#d8c9a3'), 'letter.png', 'en', 'Scan', null, 'online', $this->admin);
        // The format as Siegfried would say it, where it is not installed.
        $png = FileFormat::firstOrCreate(['puid' => 'fmt/11'], ['name' => 'Portable Network Graphics', 'version' => '1.0', 'mime_type' => 'image/png']);
        $scan->file('master')->update(['format_id' => $png->id]);
        $pathway = MigrationPathway::create(['source_puid' => 'fmt/11', 'target_puid' => 'fmt/353', 'tool_id' => Concept::where('iri', Vocab::BASE.'conversion-tool/imagemagick')->value('id'),
            'target_ext' => 'tif', 'target_mime' => 'image/tiff', 'quality_impact_id' => Concept::where('iri', Vocab::BASE.'migration-quality-impact/lossless')->value('id'), 'is_recommended' => true]);
        $plans = app(MigrationPlans::class);
        $plan = $plans->save($this->institution, ['name' => 'PNG to TIFF', 'pathway_id' => $pathway->id, 'run_fixity_after' => true], $this->admin);
        $this->assertSame(['draft', 'fmt/11', 'fmt/353', 1], [$plan->status->notation, $plan->source_puid, $plan->target_puid, $plans->files($plan)->count()]);

        // Approved by someone other than who drew it up.
        $this->assertThrows(fn () => $plans->approve($plan, $this->admin), InvalidArgumentException::class, __('A plan is approved by someone other than who drew it up.'));
        $second = $this->administrator('Second', 'second@example.org');
        $plan = $plans->approve($plan, $second);
        $this->assertSame('approved', $plan->status->notation);
        $this->actingAs($second)->post(route('preservation.plans.action', [$plan, 'start']))->assertRedirect();
        $plan = $plan->fresh();
        $this->assertSame('completed', $plan->status->notation);
        $this->assertSame([1, 1, 0], [$plans->counts($plan)['total'], $plans->counts($plan)['completed'], $plans->counts($plan)['failed']]);
        $made = $scan->fresh()->file('preservation');
        $this->assertSame('image/tiff', $made->mime_type);
        $this->assertContains('migration:success', $this->types($made));
        $this->assertContains('fixity-check:success', $this->types($made), 'checked after, as the plan says');
        $this->assertSame($plan->id, FormatConversion::where('output_file_id', $made->id)->sole()->migration_plan_id);
        $this->get(route('preservation.plans.show', $plan))->assertOk()->assertSee('PNG to TIFF')->assertSee('letter.png');
        $this->get(route('formats.index'))->assertOk()->assertSee('fmt/353');
    }

    public function test_the_object_store_is_replicated_to_a_folder_and_compared_with_it(): void
    {
        $this->actingAs($this->admin);
        $scan = app(InstantiationService::class)->upload($this->letter, $this->png('#d8c9a3'), 'letter.png', 'en', 'Scan', null, 'online', $this->admin);
        $replication = app(Replication::class);
        $target = $replication->save(['name' => 'Second disk', 'type' => 'local', 'path' => $this->store.'/replica', 'frequency' => 'daily']);

        $run = $replication->run($target, 'sync', $this->admin);
        $this->assertSame('completed', $run->status->notation, (string) $run->error_message);
        $this->assertFileExists($this->store.'/replica/0=ocfl_1.1');
        $copy = new StorageRoot($this->store.'/replica');
        $this->assertSame([], $copy->verify($scan->iri), 'the copy is a valid OCFL object');
        $this->assertContains('replication:success', $this->types($scan->file('master')));
        $this->assertGreaterThan(0, $run->files_synced);
        $this->assertNotNull($target->fresh()->next_sync_at);

        // Compared: the same, then a changed copy found.
        $this->assertSame('completed', $replication->run($target, 'verify')->status->notation);
        file_put_contents($this->store.'/replica/'.$scan->file('master')->storage_path, 'not the letter');
        $verify = $replication->run($target, 'verify');
        $this->assertSame(['partial', 1], [$verify->status->notation, $verify->files_failed]);

        // A destruction purges the object, and the next copy loses it too.
        app(FileStore::class)->ocfl()->purge($scan->iri);
        $replication->run($target, 'sync');
        $this->assertFalse($copy->exists($scan->iri));

        // A folder that holds something other than a storage root is not copied into.
        mkdir($this->store.'/elsewhere');
        file_put_contents($this->store.'/elsewhere/notes.txt', 'keep me');
        $wrong = $replication->save(['name' => 'Wrong folder', 'type' => 'local', 'path' => $this->store.'/elsewhere', 'frequency' => 'weekly']);
        $refused = $replication->run($wrong, 'sync');
        $this->assertSame('failed', $refused->status->notation);
        $this->assertFileExists($this->store.'/elsewhere/notes.txt');

        // Inside the object store, or a remote path a shell would read, is refused.
        $this->assertThrows(fn () => $replication->save(['name' => 'Inside', 'type' => 'local', 'path' => $this->store.'/objects/copy', 'frequency' => 'daily']), InvalidArgumentException::class);
        $this->assertThrows(fn () => $replication->save(['name' => 'Bad', 'type' => 'rsync', 'host' => 'backup.example.org;rm', 'path' => '/srv/copy', 'frequency' => 'daily']), InvalidArgumentException::class);
        $this->assertSame(2, ReplicationTarget::count());
    }

    public function test_the_units_rights_are_premis_rights_statements(): void
    {
        $this->actingAs($this->admin);
        app(InstantiationService::class)->upload($this->letter, $this->png('#d8c9a3'), 'letter.png', 'en', 'Scan', null, 'online', $this->admin);
        app(RightsService::class)->add($this->letter, ['basis' => 'copyright', 'statement' => 'InC-EDU', 'copyright_status' => 'copyrighted', 'jurisdiction' => 'za',
            'determined_on' => '2026-10-01', 'starts_on' => '1952-01-01', 'ends_on' => '2042-12-31', 'note' => 'Taken for the council.', 'note_language' => 'en',
            'grants' => [['act' => 'disseminate', 'restriction' => 'conditional', 'note' => 'Ask the holder.']]], $this->admin);
        app(RightsService::class)->add($this->letter, ['basis' => 'statute', 'jurisdiction' => 'za', 'citation' => 'National Archives and Records Service of South Africa Act 43 of 1996, s.12'], $this->admin);

        $xpath = $this->premis();
        $this->assertSame(2, $xpath->query('//p:rights/p:rightsStatement')->length);
        $copyright = $xpath->query('//p:rightsStatement[p:rightsBasis="Copyright"]')->item(0);
        $this->assertSame(['copyrighted', 'ZA', '2026-10-01', '1952-01-01'], array_map(fn ($q) => $xpath->query($q, $copyright)->item(0)?->textContent,
            ['p:copyrightInformation/p:copyrightStatus', 'p:copyrightInformation/p:copyrightJurisdiction', 'p:copyrightInformation/p:copyrightStatusDeterminationDate', 'p:copyrightInformation/p:copyrightApplicableDates/p:startDate']));
        $this->assertSame(['disseminate', 'conditional', $this->letter->iri], array_map(fn ($q) => $xpath->query($q, $copyright)->item(0)?->textContent,
            ['p:rightsGranted/p:act', 'p:rightsGranted/p:restriction', 'p:linkingObjectIdentifier/p:linkingObjectIdentifierValue']));
        $this->assertStringContainsString('Act 43 of 1996', $xpath->query('//p:rightsStatement[p:rightsBasis="Statute"]/p:statuteInformation/p:statuteCitation')->item(0)->textContent);
    }

    private function premis(): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML($this->get(route('records.export.download', [$this->letter, 'premis']))->assertOk()->getContent());
        if ($xsd = getenv('PREMIS_XSD')) {
            libxml_use_internal_errors(true);
            $this->assertTrue($dom->schemaValidate($xsd), 'valid PREMIS 3.0: '.implode('; ', array_map(fn ($e) => trim($e->message), libxml_get_errors())));
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('p', 'http://www.loc.gov/premis/v3');

        return $xpath;
    }
}
