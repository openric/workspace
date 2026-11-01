<?php

// OpenRiC Workspace - research projects, their collaborators, resources, milestones, team, outputs, clipboard,
// export and share link, and working together: discussions, comments and presence (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\ResearchMail;
use App\Models\Bibliography;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchAnnotation;
use App\Models\ResearchCollection;
use App\Models\ResearchComment;
use App\Models\ResearchDiscussion;
use App\Models\ResearchNotebook;
use App\Models\ResearchProject;
use App\Models\ResearchProjectMember;
use App\Models\ResearchWorkspace;
use App\Models\SavedSearch;
use App\Models\User;
use App\Research\Annotations;
use App\Research\Collections;
use App\Research\Favourites;
use App\Research\Projects;
use App\Search\SearchIndex;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearchProjectTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    private RecordResource $letter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        $this->travelTo('2026-11-05 09:00');
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $this->reader = app(AccountService::class)->create($this->institution, 'Rita', 'rita@example.org', 'correct horse battery staple');
        $this->other = app(AccountService::class)->create($this->institution, 'Olu', 'olu@example.org', 'correct horse battery staple');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $fonds, null, '1');
        foreach ([$holder, $fonds, $this->letter] as $entity) {
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

    private function project(): ResearchProject
    {
        $this->actingAs($this->reader)->post(route('research.projects.store'), ['title' => 'The parish bridge', 'project_type' => 'thesis', 'status' => 'active', 'visibility' => 'collaborators',
            'supervisor' => 'Prof. N. Dlamini', 'start_date' => '2026-01-01', 'expected_end_date' => '2027-12-31'])->assertSessionHasNoErrors();

        return ResearchProject::where('owner_id', $this->reader->id)->sole();
    }

    private function join(ResearchProject $project, User $who, string $role): ResearchProjectMember
    {
        $this->actingAs($this->reader)->post(route('research.projects.step', [$project, 'invite']), ['email' => $who->email, 'role' => $role])->assertSessionHasNoErrors();
        $member = $project->members()->where('user_id', $who->id)->sole();
        $this->actingAs($who)->post(route('research.projects.member', [$member, 'accept']))->assertSessionHasNoErrors();

        return $member;
    }

    public function test_a_project_gathers_collaborators_resources_milestones_team_outputs_and_a_clipboard(): void
    {
        $project = $this->project();
        $this->actingAs($this->reader)->post(route('research.projects.store'), ['title' => 'Backwards', 'start_date' => '2026-05-01', 'expected_end_date' => '2026-01-01'])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->get(route('research.projects.show', $project))->assertNotFound();
        $this->actingAs($this->reader)->post(route('research.projects.step', [$project, 'invite']), ['email' => 'olu@example.org', 'role' => 'owner'])->assertSessionHasErrors('research');
        $member = $this->join($project, $this->other, 'contributor');
        Mail::assertQueued(ResearchMail::class, fn ($m) => $m->hasTo('olu@example.org'));
        $this->actingAs($this->other)->post(route('research.projects.step', [$project, 'invite']), ['email' => 'admin@example.org', 'role' => 'viewer'])->assertSessionHasErrors('research');

        // Resources: one's own collection, a record, a link, a document and a note; not someone else's collection.
        $theirs = app(Collections::class)->save($this->other, ['name' => 'Olu sources']);
        $mine = app(Collections::class)->save($this->reader, ['name' => 'Rita sources']);
        $add = fn (array $data, User $who) => $this->actingAs($who)->post(route('research.projects.step', [$project, 'resource']), $data);
        $add(['resource_type' => 'collection', 'resource_id' => $theirs->id], $this->other)->assertSessionHasNoErrors();
        $add(['resource_type' => 'collection', 'resource_id' => $mine->id], $this->other)->assertSessionHasErrors('research');
        $add(['resource_type' => 'record', 'iri' => $this->letter->iri], $this->other)->assertSessionHasNoErrors();
        $add(['resource_type' => 'external-link', 'external_url' => 'https://example.org/bridges', 'title' => 'Bridges of the Cape', 'link_type' => 'academic'], $this->reader)->assertSessionHasNoErrors();
        $add(['resource_type' => 'document', 'file' => UploadedFile::fake()->createWithContent('plan.txt', 'Chapter plan')], $this->other)->assertSessionHasNoErrors();
        $add(['resource_type' => 'note', 'title' => 'Ask the museum', 'notes' => 'About the 1898 photographs.'], $this->other)->assertSessionHasNoErrors();
        $this->assertSame(5, $project->resources()->count());
        $link = $project->resources()->where('title', 'Bridges of the Cape')->sole();
        $this->actingAs($this->other)->post(route('research.projects.resource.remove', $link))->assertSessionHasErrors('research');
        $document = $project->resources()->whereNotNull('file_id')->sole();
        $this->assertSame('Chapter plan', $this->actingAs($this->reader)->get(route('research.projects.resource', $document))->assertOk()->streamedContent());
        $this->actingAs($this->reader)->get(route('research.projects.resource', $project->resources()->whereNotNull('resource_id')->sole()))->assertOk()->assertSee('Olu sources');
        $this->actingAs($this->reader)->post(route('research.projects.resource.remove', $link))->assertSessionHasNoErrors();

        // Milestones: overdue until done; done sets who and when.
        $this->actingAs($this->other)->post(route('research.projects.milestones', $project), ['title' => 'Chapter one', 'milestone_type' => 'deliverable', 'due_date' => '2026-10-01', 'progress_pct' => 40])
            ->assertSessionHasNoErrors();
        $milestone = $project->milestones()->sole();
        $this->assertTrue(app(Projects::class)->isOverdue($milestone));
        $this->actingAs($this->other)->post(route('research.projects.milestones', [$project, $milestone]), ['title' => 'Chapter one', 'milestone_type' => 'deliverable', 'due_date' => '2026-10-01', 'status' => 'completed'])
            ->assertSessionHasNoErrors();
        $milestone->refresh();
        $this->assertSame([100, '2026-11-05', $this->other->id], [$milestone->progress_pct, $milestone->completed_date->toDateString(), $milestone->completed_by_id]);
        $this->assertFalse(app(Projects::class)->isOverdue($milestone));

        // The team, with ORCID iDs checked by their check digit; outputs with their identifiers resolved.
        $team = fn (array $data) => $this->actingAs($this->reader)->post(route('research.projects.team', $project), $data);
        $team(['person_name' => 'Josiah Carberry', 'orcid' => '0000-0002-1825-0098'])->assertSessionHasErrors('research');
        $team(['person_name' => 'Josiah Carberry', 'role' => 'principal-investigator', 'orcid' => 'https://orcid.org/0000-0002-1825-0097', 'is_lead' => 1, 'affiliation' => 'Brown University'])->assertSessionHasNoErrors();
        $team(['person_name' => 'Olu', 'role' => 'student', 'email' => 'OLU@example.org'])->assertSessionHasNoErrors();
        $this->assertSame('0000-0002-1825-0097', $project->teamMembers()->where('person_name', 'Josiah Carberry')->value('orcid'));
        $this->assertSame($this->other->id, $project->teamMembers()->where('person_name', 'Olu')->value('user_id'));
        $this->actingAs($this->other)->post(route('research.projects.outputs', $project), ['title' => 'Iron over the river', 'output_type' => 'journal-article', 'venue' => 'Historia',
            'identifier_type' => 'doi', 'identifier' => 'doi:10.1234/bridge.1898', 'status' => 'published', 'output_date' => '2026-09-01'])->assertSessionHasNoErrors();
        $this->assertSame('https://doi.org/10.1234/bridge.1898', Projects::outputUrl($project->outputs()->with('identifierType')->sole()));

        $this->actingAs($this->other)->post(route('research.projects.step', [$project, 'clip']), ['iris' => $this->letter->iri."\nhttps://archives.example.org/nothing", 'notes' => 'Key letter'])->assertSessionHasNoErrors();
        $this->assertSame(1, $project->clipboard()->count());
        $clip = $project->clipboard()->sole();
        $this->actingAs($this->reader)->post(route('research.projects.clipboard', [$clip, 'pin']))->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.projects.clipboard', [$clip, 'pin']))->assertSessionHasNoErrors();
        $this->assertTrue($clip->fresh()->is_pinned);

        $this->actingAs($this->reader)->get(route('research.projects.show', $project))->assertOk()->assertSee('Chapter one')->assertSee('Josiah Carberry')->assertSee('https://doi.org/10.1234/bridge.1898')
            ->assertSee('Ask the museum');
        $this->assertGreaterThanOrEqual(8, $project->activity()->count());

        // Public, the institution reads it but does not add to it; a share link shows its summary to anyone.
        $this->actingAs($this->admin)->get(route('research.projects.show', $project))->assertNotFound();
        $this->actingAs($this->reader)->post(route('research.projects.step', [$project, 'update']), ['title' => 'The parish bridge', 'project_type' => 'thesis', 'status' => 'active', 'visibility' => 'public'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('research.projects.show', $project))->assertOk()->assertDontSee('Add a resource');
        $add(['resource_type' => 'note', 'title' => 'Not mine'], $this->admin)->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.projects.step', [$project, 'share']), ['share' => 1])->assertSessionHasErrors('research');
        $link = $this->actingAs($this->reader)->post(route('research.projects.step', [$project, 'share']), ['share' => 1])->assertSessionHasNoErrors()->getSession()->get('share_link');
        auth()->logout();
        $this->get($link)->assertOk()->assertSee('Josiah Carberry')->assertSee('Iron over the river')->assertDontSee('Ask the museum');
        $this->actingAs($this->reader)->post(route('research.projects.step', [$project, 'share']), ['share' => 0]);
        auth()->logout();
        $this->get($link)->assertNotFound();
    }

    public function test_a_project_is_exported_one_s_research_put_in_it_and_a_notebook_promoted_to_one(): void
    {
        $project = $this->project();
        $this->join($project, $this->other, 'viewer');
        $collection = app(Collections::class)->save($this->reader, ['name' => 'Bridge sources']);
        app(Collections::class)->add($this->reader, $collection, $this->letter);
        $this->actingAs($this->other)->post(route('research.projects.assign', ['collection', $collection->id]), ['project_id' => $project->id])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.projects.assign', ['collection', $collection->id]), ['project_id' => $project->id])->assertSessionHasNoErrors();
        $this->assertSame($project->id, $collection->fresh()->project_id);
        $theirs = app(Collections::class)->save($this->other, ['name' => 'Olu sources']);
        $this->actingAs($this->other)->post(route('research.projects.assign', ['collection', $theirs->id]), ['project_id' => $project->id])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.bibliographies.store'), ['name' => 'Thesis sources', 'citation_style' => 'chicago'])->assertSessionHasNoErrors();
        $bibliography = Bibliography::where('user_id', $this->reader->id)->sole();
        $this->actingAs($this->reader)->post(route('research.bibliographies.step', [$bibliography, 'entry']), ['entry_type' => 'book', 'title' => 'Bridges of the Cape', 'authors' => 'Smit, Jan', 'date' => '1950'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.projects.assign', ['bibliography', $bibliography->id]), ['project_id' => $project->id])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.projects.team', $project), ['person_name' => 'Rita', 'role' => 'researcher', 'is_lead' => 1])->assertSessionHasNoErrors();

        $markdown = $this->actingAs($this->other)->get(route('research.projects.export', [$project, 'markdown']))->assertOk()->getContent();
        $this->assertStringContainsString('# The parish bridge', $markdown);
        $this->assertStringContainsString('Prof. N. Dlamini', $markdown);
        $this->assertStringContainsString($this->letter->iri, $markdown);
        $bibtex = $this->actingAs($this->reader)->get(route('research.projects.export', [$project, 'bibtex']))->assertOk()->getContent();
        $this->assertStringContainsString('Letter about the parish bridge', $bibtex);
        $this->assertStringContainsString('Bridges of the Cape', $bibtex);
        $json = json_decode($this->actingAs($this->reader)->get(route('research.projects.export', [$project, 'json']))->assertOk()->getContent(), true);
        $this->assertSame('Rita', $json['team'][0]['name']);
        $zip = $this->actingAs($this->reader)->get(route('research.projects.export', [$project, 'zip']))->assertOk()->assertHeader('Content-Type', 'application/zip')->getContent();
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $zip);
        $archive = new \ZipArchive;
        $archive->open($path);
        $names = array_map(fn ($i) => $archive->getNameIndex($i), range(0, $archive->numFiles - 1));
        $archive->close();
        unlink($path);
        $this->assertEqualsCanonicalizing(['the-parish-bridge/README.md', 'the-parish-bridge/manifest.json', 'the-parish-bridge/project.md', 'the-parish-bridge/project.json',
            'the-parish-bridge/sources.bib', 'the-parish-bridge/sources.ris', 'the-parish-bridge/sources.json'], $names);
        $this->assertSame(4, $project->exports()->count());
        $this->actingAs($this->admin)->get(route('research.projects.export', [$project, 'json']))->assertNotFound();

        // A saved search put in the project; favourites sent to a collection with their notes, and to the project as its records, once each.
        $this->actingAs($this->reader)->post(route('research.searches.store'), ['name' => 'Bridges', 'search_query' => 'bridge'])->assertSessionHasNoErrors();
        $search = SavedSearch::where('user_id', $this->reader->id)->sole();
        $this->actingAs($this->other)->post(route('research.projects.assign', ['search', $search->id]), ['project_id' => $project->id])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.projects.assign', ['search', $search->id]), ['project_id' => $project->id])->assertSessionHasNoErrors();
        $this->assertSame($project->id, $search->fresh()->project_id);
        $favourite = app(Favourites::class)->add($this->reader, $this->letter, null, 'Signed by the clerk');
        $fresh = app(Collections::class)->save($this->reader, ['name' => 'From favourites']);
        $this->actingAs($this->reader)->post(route('research.collections.from-favourites'), ['ids' => [$favourite->id], 'collection_id' => $fresh->id])->assertSessionHas('status', '1 was added.');
        $this->assertSame('Signed by the clerk', $fresh->items()->sole()->notes);
        $this->actingAs($this->reader)->post(route('research.projects.from-favourites'), ['ids' => [$favourite->id], 'project_id' => $project->id])->assertSessionHas('status', '1 record was added.');
        $this->actingAs($this->reader)->post(route('research.projects.from-favourites'), ['ids' => [$favourite->id], 'project_id' => $project->id])->assertSessionHas('status', 'No record was added.');

        // A research workspace names the project; it opens for who may read the project.
        $this->actingAs($this->reader)->post(route('research.workspaces.store'), ['name' => 'Bridge group', 'visibility' => 'public'])->assertSessionHasNoErrors();
        $workspace = ResearchWorkspace::sole();
        $this->actingAs($this->reader)->post(route('research.workspaces.step', [$workspace, 'resource']), ['resource_type' => 'project', 'resource_id' => $project->id])->assertSessionHasNoErrors();
        $named = $workspace->resources()->sole();
        $this->assertSame('The parish bridge', $named->title);
        $this->actingAs($this->other)->get(route('research.workspaces.resource', $named))->assertRedirect(route('research.projects.show', $project));
        $this->actingAs($this->admin)->get(route('research.workspaces.resource', $named))->assertRedirect(route('research.projects.show', $project));
        $this->actingAs($this->admin)->get(route('research.projects.show', $project))->assertNotFound();

        // A notebook promoted: its records a collection of the new project, its notes the project's notes; once only.
        $this->actingAs($this->reader)->post(route('research.notebooks.store'), ['title' => 'Field notes'])->assertSessionHasNoErrors();
        $notebook = ResearchNotebook::where('user_id', $this->reader->id)->sole();
        $this->actingAs($this->reader)->post(route('research.notebooks.step', [$notebook, 'item']), ['item_type' => 'source-pin', 'iri' => $this->letter->iri, 'title' => 'The letter'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.notebooks.step', [$notebook, 'item']), ['item_type' => 'note', 'body' => 'Compare with the 1902 survey.'])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.notebooks.promote', $notebook))->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.notebooks.promote', $notebook))->assertSessionHasNoErrors();
        $promoted = ResearchProject::where('title', 'Field notes')->sole();
        $this->assertSame([$promoted->id, 'public'], [$notebook->fresh()->promoted_to_project_id, $promoted->projectType->notation]);
        $made = ResearchCollection::where('project_id', $promoted->id)->sole();
        $this->assertSame($this->letter->id, $made->items()->sole()->subject_id);
        $this->assertSame('Compare with the 1902 survey.', $promoted->resources()->whereHas('resourceType', fn ($t) => $t->where('notation', 'note'))->value('notes'));
        $this->actingAs($this->reader)->post(route('research.notebooks.promote', $notebook))->assertRedirect(route('research.projects.show', $promoted));
        $this->assertSame(1, ResearchProject::where('title', 'Field notes')->count());
    }

    public function test_members_discuss_comment_on_evidence_and_see_who_is_looking(): void
    {
        $project = $this->project();
        $this->join($project, $this->other, 'viewer');
        $discuss = fn (User $who, array $data, $home = null) => $this->actingAs($who)->post(route('research.discussions.store', $home ?? ['projects', $project->id]), $data);
        $discuss($this->admin, ['content' => 'Outsider'])->assertSessionHasErrors('research');
        $discuss($this->reader, ['subject' => 'Scope', 'content' => 'Should we include the 1902 survey?'])->assertSessionHasNoErrors();
        $topic = ResearchDiscussion::where('subject', 'Scope')->sole();
        $discuss($this->other, ['content' => 'Yes, it names the engineer.', 'parent_id' => $topic->id])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.discussions.step', [$topic, 'pin']))->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.discussions.step', [$topic, 'pin']))->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.discussions.step', [$topic, 'resolve']))->assertSessionHasNoErrors();
        $this->assertSame([true, true, $this->reader->id], [$topic->fresh()->is_pinned, $topic->fresh()->is_resolved, $topic->fresh()->resolved_by_id]);
        $this->actingAs($this->other)->get(route('research.projects.show', $project))->assertOk()->assertSee('Should we include the 1902 survey?')->assertSee('Yes, it names the engineer.');

        // A research workspace has its discussions too, for its members.
        $this->actingAs($this->reader)->post(route('research.workspaces.store'), ['name' => 'Bridge group', 'visibility' => 'members'])->assertSessionHasNoErrors();
        $workspace = ResearchWorkspace::sole();
        $discuss($this->other, ['content' => 'Not a member'], ['workspaces', $workspace->id])->assertSessionHasErrors('research');
        $discuss($this->reader, ['subject' => 'Welcome', 'content' => 'Our shared sources go here.'], ['workspaces', $workspace->id])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->get(route('research.workspaces.show', $workspace))->assertOk()->assertSee('Our shared sources go here.');

        // Comments on the evidence, followed by polling, with who is looking.
        $collection = app(Collections::class)->save($this->reader, ['name' => 'Bridge sources']);
        $outside = app(Collections::class)->save($this->reader, ['name' => 'Not in the project']);
        app(Projects::class)->assign($this->reader, $collection, $project);
        $first = $this->actingAs($this->other)->getJson(route('research.projects.poll', $project))->assertOk()->json();
        $this->actingAs($this->reader)->getJson(route('research.projects.poll', $project))->assertOk();
        $this->actingAs($this->other)->postJson(route('research.projects.evidence-comments', $project), ['body' => 'Is this the 1898 bridge?', 'collection_id' => $outside->id])->assertStatus(422);
        $this->travel(5)->seconds();
        $this->actingAs($this->other)->postJson(route('research.projects.evidence-comments', $project), ['body' => 'Is this the 1898 bridge?', 'collection_id' => $collection->id])->assertCreated();
        $poll = $this->actingAs($this->reader)->getJson(route('research.projects.poll', [$project, 'since' => $first['cursor']]))->assertOk()->json();
        $this->assertSame(['Is this the 1898 bridge?'], array_column($poll['comments'], 'body'));
        $this->assertEqualsCanonicalizing(['Rita', 'Olu'], array_column($poll['presence'], 'name'));
        $this->travel(2)->minutes();
        $later = $this->actingAs($this->reader)->getJson(route('research.projects.poll', [$project, 'since' => $poll['cursor']]))->assertOk()->json();
        $this->assertSame([[], ['Rita']], [$later['comments'], array_column($later['presence'], 'name')]);
        $this->actingAs($this->admin)->getJson(route('research.projects.poll', $project))->assertNotFound();

        // Comments on an annotation: by who may read it, and its author is told.
        $annotation = app(Annotations::class)->save($this->reader, $this->letter, ['content' => 'The engineer signed it.', 'visibility' => 'public']);
        $private = app(Annotations::class)->save($this->reader, $this->letter, ['content' => 'Only mine.', 'visibility' => 'private']);
        $this->actingAs($this->other)->post(route('research.comments.store', ['annotation', $private->id]), ['content' => 'Peeking'])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.comments.store', ['annotation', $annotation->id]), ['content' => 'Which engineer?'])->assertSessionHasNoErrors();
        Mail::assertQueued(ResearchMail::class, fn ($m) => $m->hasTo('rita@example.org') && str_contains(json_encode($m->lines), 'Which engineer?'));
        $this->actingAs($this->reader)->get(route('research.studio', ['records', $this->letter->id]))->assertOk()->assertSee('Which engineer?');
        $comment = ResearchComment::sole();
        $this->actingAs($this->admin)->post(route('research.comments.step', [$comment, 'delete']))->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.comments.step', [$comment, 'resolve']))->assertSessionHasNoErrors();
        $this->assertTrue($comment->fresh()->is_resolved);
        $this->assertSame(2, ResearchAnnotation::count());
    }
}
