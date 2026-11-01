<?php

// OpenRiC Workspace - researcher submissions: drafted, sent, reviewed and published through the import (roadmap
// phase 19e, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\ResearchMail;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\ResearchCollection;
use App\Models\ResearcherSubmission;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Research\Collections;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearcherSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    private Repository $holder;

    private RecordResource $letter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->travelTo('2026-11-04 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $this->other = app(AccountService::class)->create($this->institution, 'Olu', 'olu@example.org', 'correct horse battery staple');
        $this->holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $this->holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $fonds, null, '1');
        app(RecordService::class)->describe($this->letter, ['note' => 'Water damage on the lower edge.'], 'en');
        foreach ([$this->holder, $fonds, $this->letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->admin);
        }
        app(SearchIndex::class)->flush();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_a_submission_is_drafted_returned_approved_and_published_into_the_catalogue(): void
    {
        $this->actingAs($this->reader)->post(route('research.submissions.store'), ['title' => 'Bridge papers', 'repository_id' => $this->holder->id])->assertSessionHasNoErrors();
        $submission = ResearcherSubmission::where('user_id', $this->reader->id)->sole();
        $item = fn (array $data) => $this->actingAs($this->reader)->post(route('research.submissions.items.store', $submission), $data);
        $item(['item_type' => 'description', 'title' => 'Bridge committee papers', 'identifier' => 'BC', 'level' => self::LEVEL.'fonds', 'date_display' => '1895-1902',
            'date_start' => '1895-01-01', 'date_end' => '1902-12-31', 'scope_and_content' => 'Minutes and letters of the committee.'])->assertSessionHasNoErrors();
        $fonds = $submission->items()->where('title', 'Bridge committee papers')->sole();
        $item(['item_type' => 'description', 'title' => 'Tender for the iron bridge', 'identifier' => '1', 'level' => self::LEVEL.'item', 'parent_item_id' => $fonds->id,
            'creators' => 'Jan Smit', 'subjects' => 'Bridges, Tenders, Bridges', 'places' => 'Riverside', 'extent_and_medium' => '3 leaves'])->assertSessionHasNoErrors();
        $tender = $submission->items()->where('title', 'Tender for the iron bridge')->sole();
        $this->assertSame('Bridges, Tenders', $tender->subjects);
        $item(['item_type' => 'creator', 'title' => 'Jan Smit', 'scope_and_content' => 'Engineer of the river crossing.'])->assertSessionHasNoErrors();
        $item(['item_type' => 'note', 'reference_iri' => 'https://archives.example.org/no-such-record', 'scope_and_content' => 'x'])->assertSessionHasErrors('research');
        $item(['item_type' => 'note', 'reference_iri' => $this->letter->iri, 'scope_and_content' => 'The writer is the town clerk, J. de Wet.'])->assertSessionHasNoErrors();
        $item(['item_type' => 'description', 'title' => 'Bad level', 'level' => 'not-a-level'])->assertSessionHasErrors('research');
        $item(['item_type' => 'description', 'title' => 'Backwards', 'date_start' => '1900-01-01', 'date_end' => '1899-01-01'])->assertSessionHasErrors('research');
        // A description cannot be put inside itself, or inside what is inside it.
        $this->actingAs($this->reader)->post(route('research.submissions.items.update', [$submission, $fonds]), ['title' => 'Bridge committee papers', 'parent_item_id' => $tender->id])
            ->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.submissions.item', [$tender, 'file']), ['file' => UploadedFile::fake()->createWithContent('tender.txt', 'Tender of 1898')])
            ->assertSessionHasNoErrors();
        $this->assertSame([2, 1], [$submission->fresh()->total_items, $submission->fresh()->total_files]);

        $this->actingAs($this->other)->get(route('research.submissions.show', $submission))->assertNotFound();
        $this->actingAs($this->reader)->get(route('research.submissions.pending'))->assertForbidden();
        $this->actingAs($this->reader)->get(route('research.submissions.show', $submission))->assertOk()->assertSee('Tender for the iron bridge')->assertSee('inside Bridge committee papers');

        // Sent, it is with the reviewers and cannot be changed; returned with a reason, it can.
        $this->actingAs($this->reader)->post(route('research.submissions.step', [$submission, 'submit']))->assertSessionHasNoErrors();
        $item(['item_type' => 'description', 'title' => 'Too late'])->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->get(route('research.submissions.pending'))->assertOk()->assertSee('Bridge papers');
        $this->actingAs($this->admin)->post(route('research.submissions.review', [$submission, 'return']))->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.submissions.review', [$submission, 'approve']))->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('research.submissions.review', [$submission, 'return']), ['comment' => 'Give the tender its date.'])->assertSessionHasNoErrors();
        Mail::assertQueued(ResearchMail::class, fn ($m) => $m->hasTo('rita@example.org'));
        $this->actingAs($this->reader)->get(route('research.submissions.show', $submission))->assertOk()->assertSee('Give the tender its date.');
        $this->actingAs($this->reader)->post(route('research.submissions.items.update', [$submission, $tender]), ['title' => 'Tender for the iron bridge', 'date_display' => '1898',
            'creators' => 'Jan Smit', 'subjects' => 'Bridges, Tenders', 'places' => 'Riverside', 'parent_item_id' => $fonds->id, 'level' => self::LEVEL.'item', 'identifier' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.submissions.step', [$submission, 'submit']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('research.submissions.review', [$submission, 'take']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('research.submissions.publish', $submission), ['dry_run' => 1])->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('research.submissions.review', [$submission, 'approve']))->assertSessionHasNoErrors();
        $this->assertSame('approved', $submission->fresh()->status->notation);

        // A dry run makes nothing and reports what would happen; publishing makes it all.
        $records = RecordResource::count();
        $this->actingAs($this->admin)->post(route('research.submissions.publish', $submission), ['dry_run' => 1, 'publish' => 1, 'create_terms' => 1, 'language' => 'en'])->assertSessionHasNoErrors();
        $this->assertSame($records, RecordResource::count());
        $this->assertSame(2, $submission->fresh()->import->report['actions']['created']);
        $this->assertNull($submission->fresh()->import->committed_at);
        $this->actingAs($this->admin)->get(route('research.submissions.show', $submission))->assertOk()->assertSee('The dry run: 2 would be created');

        $this->actingAs($this->admin)->post(route('research.submissions.publish', $submission), ['publish' => 1, 'create_terms' => 1, 'language' => 'en'])->assertSessionHasNoErrors();
        $submission->refresh();
        $this->assertSame('published', $submission->status->notation);
        $made = RecordResource::find($submission->items()->where('title', 'Tender for the iron bridge')->value('published_id'));
        $top = RecordResource::find($fonds->fresh()->published_id);
        $this->assertSame('Tender for the iron bridge', $made->described('title', ['en']));
        $this->assertSame($top->id, $made->parent_id);
        $this->assertSame($this->holder->id, $top->repository_id);
        $this->assertSame(1, $made->instantiations()->count());
        $this->assertSame('Tender for the iron bridge', $made->described('title', ['en']));
        $this->assertTrue(Agent::all()->contains(fn ($a) => EntityKinds::label($a) === 'Jan Smit'));
        $this->assertStringContainsString('The writer is the town clerk, J. de Wet.', $this->letter->fresh()->described('note', ['en']));
        $this->assertStringContainsString('Water damage on the lower edge.', $this->letter->fresh()->described('note', ['en']));
        Mail::assertQueued(ResearchMail::class, fn ($m) => $m->hasTo('rita@example.org') && str_contains(json_encode($m->lines), 'is published in the catalogue'));
        $this->actingAs($this->reader)->post(route('research.submissions.step', [$submission, 'delete']))->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('research.submissions.publish', $submission), [])->assertSessionHasErrors('research');
    }

    public function test_a_submission_is_made_from_a_collection_and_from_an_exchange_file(): void
    {
        $collection = app(Collections::class)->save($this->reader, ['name' => 'Bridge sources']);
        app(Collections::class)->add($this->reader, $collection, $this->letter, 'Signed by the town clerk.');
        $this->actingAs($this->other)->post(route('research.submissions.from-collection'), ['collection_id' => $collection->id])->assertNotFound();
        $this->actingAs($this->reader)->post(route('research.submissions.from-collection'), ['collection_id' => $collection->id])->assertSessionHasNoErrors();
        $fromCollection = ResearcherSubmission::where(['user_id' => $this->reader->id, 'title' => 'Bridge sources'])->sole();
        $note = $fromCollection->items()->with('itemType')->sole();
        $this->assertSame(['note', $this->letter->id, 'Signed by the town clerk.'], [$note->itemType->notation, $note->reference_record_id, $note->scope_and_content]);

        $exchange = fn (array $data) => UploadedFile::fake()->createWithContent('researcher-exchange.json', json_encode($data));
        $this->actingAs($this->reader)->post(route('research.submissions.import'), ['exchange_file' => $exchange(['format_version' => '2.0', 'collections' => [['type' => 'notes']]])])
            ->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.submissions.import'), ['exchange_file' => UploadedFile::fake()->createWithContent('x.json', 'not json')])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.submissions.import'), ['repository_id' => $this->holder->id, 'exchange_file' => $exchange([
            'format_version' => '1.0', 'source_config' => ['title' => 'Field trip'], 'export_options' => ['include_images' => true],
            'collections' => [
                ['type' => 'notes', 'items' => [['note' => 'Seen in the field.', 'reference_iri' => $this->letter->iri], ['note' => 'Record unknown.', 'reference_id' => 412]]],
                ['type' => 'new_items', 'items' => [
                    ['id' => 'a', 'title' => 'Photographs of the bridge', 'level_of_description' => 'file', 'subjects' => ['Bridges', 'Photographs'], 'date_start' => '1903-02-30'],
                    ['id' => 'b', 'parent_id' => 'a', 'title' => 'The bridge from the east', 'files' => [['filename' => 'east.txt', 'data' => base64_encode('east view'), 'caption' => 'East view'],
                        ['filename' => '../west.jpg', 'size' => 1024]]],
                ]],
                ['type' => 'new_creators', 'items' => [['name' => 'Anna Botha', 'history' => 'Photographer.']]],
                ['type' => 'new_repositories', 'items' => [['name' => 'Town museum', 'address' => '1 Main Road']]],
                ['type' => 'something-else', 'items' => [['title' => 'ignored']]],
            ]])])->assertSessionHasNoErrors()->assertSessionHas('status', 'Imported: 2 descriptions, 2 notes, 1 creators, 1 repositories and 2 files.');
        $imported = ResearcherSubmission::where('user_id', $this->reader->id)->where('title', 'Import: Field trip')->with('items.itemType', 'items.files', 'sourceType')->sole();
        $this->assertSame('offline', $imported->sourceType->notation);
        $this->assertNotNull($imported->source_file_id);
        $east = $imported->items->firstWhere('title', 'The bridge from the east');
        $this->assertSame($imported->items->firstWhere('title', 'Photographs of the bridge')->id, $east->parent_item_id);
        $this->assertNull($imported->items->firstWhere('title', 'Photographs of the bridge')->date_start);
        $this->assertSame(['east.txt', 'west.jpg'], $east->files->pluck('original_name')->all());
        $this->assertNull($east->files->firstWhere('original_name', 'west.jpg')->file_id);
        $this->assertSame([1, 1], [$imported->total_files, $imported->items->where('itemType.notation', 'note')->whereNotNull('reference_record_id')->count()]);
        // The note that names no record keeps the submission from being sent until it is pointed.
        $this->actingAs($this->reader)->post(route('research.submissions.step', [$imported, 'submit']))->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->get(route('research.submissions.files.show', $east->files->firstWhere('original_name', 'east.txt')))->assertOk();
        $this->actingAs($this->other)->get(route('research.submissions.files.show', $east->files->firstWhere('original_name', 'east.txt')))->assertNotFound();
        $this->actingAs($this->reader)->post(route('research.submissions.step', [$imported, 'delete']))->assertRedirect(route('research.submissions.index'));
        $this->assertSame(1, ResearcherSubmission::where('user_id', $this->reader->id)->count());
        $this->assertSame(1, ResearchCollection::count());
    }
}
