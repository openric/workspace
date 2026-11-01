<?php

// OpenRiC Workspace - the studios of a research project (writing, review, publication, method, question builder,
// argument builder, source triage), its time machine and export, and research journals with the directory of
// journals to aim at (roadmap phases 19g and 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Bibliography;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchJournal;
use App\Models\ResearchProject;
use App\Models\ResearchPublicationSubmission;
use App\Models\ResearchTargetJournal;
use App\Models\ResearchWritingDoc;
use App\Models\User;
use App\Research\Claims;
use App\Research\Collections;
use App\Research\Projects;
use App\Research\Studios;
use App\Search\SearchIndex;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResearchStudiosTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $admin;

    private User $reader;

    private User $other;

    private RecordResource $letter;

    private ResearchProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo('2026-11-08 09:00');
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
        $this->project = app(Projects::class)->create($this->reader, ['title' => 'Archival bridges of the Cape', 'description' => 'Records management of bridge building in the Cape archives.',
            'visibility' => 'collaborators']);
        $member = app(Projects::class)->invite($this->reader, $this->project, 'olu@example.org', 'contributor');
        app(Projects::class)->answer($this->other, $member->fresh(['status']), true);
    }

    public function test_the_question_builder_method_studio_and_argument_builder(): void
    {
        // Each save of the brief is a new version, never changing the last; the diagnosis reads it.
        $save = fn (array $data) => $this->actingAs($this->other)->post(route('research.studios.question.save', $this->project), $data);
        $save(['broad_topic' => 'The history of bridges', 'primary_question' => 'Why bridges?'])->assertSessionHasNoErrors();
        $save(['broad_topic' => 'Bridges of the Cape', 'primary_question' => 'How did the parish council pay for the iron bridge between 1895 and 1910?', 'research_gap' => 'No study of rates and tolls.',
            'scope_boundaries' => 'Riverside parish, 1895 to 1910', 'change_reason' => 'Narrowed'])->assertSessionHasNoErrors();
        $brief = $this->project->fresh()->questionBrief;
        $this->assertSame(2, $brief->current_version);
        $this->assertSame('Why bridges?', $brief->versions()->where('version_no', 1)->value('primary_question'));
        $this->assertSame(['too-broad', 'already-answered', 'method-weak', 'evidence-alignment'], array_column(Studios::diagnose(['primary_question' => 'Why bridges?']), 'key'));
        $this->assertContains('ethics', array_column(Studios::diagnose(['primary_question' => 'What consent did patients give?']), 'key'));
        $this->actingAs($this->reader)->get(route('research.studios.question', $this->project))->assertOk()->assertSee('Version 2 of 2')->assertSee('Narrowed')->assertSee('Method scaffolding thin');

        // A method protocol from the archival template, its areas written with the template's prompts.
        $this->actingAs($this->other)->post(route('research.studios.method.save', $this->project), ['template' => 'no-such-method'])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.studios.method.save', $this->project), ['template' => 'archival-method', 'title' => 'Reading the council papers'])->assertSessionHasNoErrors();
        $protocol = $this->project->methodProtocols()->sole();
        $this->actingAs($this->other)->post(route('research.studios.method.save', [$this->project, $protocol]), ['fields' => ['design' => 'Series by series, in original order.', 'not_an_area' => 'x']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['design' => 'Series by series, in original order.'], $protocol->fresh()->fields);
        $this->actingAs($this->reader)->get(route('research.studios.method', [$this->project, 'protocol' => $protocol->id]))->assertOk()->assertSee('Record selection');

        // The argument: steps on claims, and the warnings of a thin chain.
        $claims = app(Claims::class);
        $strong = $claims->save($this->reader, $this->project, ['text' => 'Rates paid for the iron bridge.', 'status' => 'supported']);
        $claims->attach($this->reader, $strong, ['source_type' => 'record', 'iri' => $this->letter->iri]);
        $weak = $claims->save($this->reader, $this->project, ['text' => 'Councils elsewhere should do the same.', 'status' => 'idea', 'confidence' => 0.2]);
        $step = fn (string $s, array $data) => $this->actingAs($this->other)->post(route('research.studios.argument.step', [$this->project, $s]), $data);
        $step('save', ['title' => 'Paying for the bridge', 'central_thesis' => 'Rates, not tolls, paid for it.'])->assertSessionHasNoErrors();
        $step('add', ['slot' => 'evidence', 'claim_id' => $strong->id])->assertSessionHasNoErrors();
        $step('add', ['slot' => 'implication', 'claim_id' => $weak->id])->assertSessionHasNoErrors();
        $step('add', ['slot' => 'problem'])->assertSessionHasNoErrors();
        $step('add', ['slot' => 'gap', 'claim_id' => '00000000-0000-4000-8000-000000000000'])->assertSessionHasErrors('research');
        $warnings = app(Studios::class)->warnings($this->project->fresh()->argument);
        $messages = implode("\n", array_column($warnings, 'message'));
        $this->assertStringContainsString('missing 6 of the nine steps', $messages);
        $this->assertStringContainsString('The "Implication" claim has no evidence', $messages);
        $this->assertStringContainsString('conclusion rests on a weak claim (no evidence, low confidence, status Idea)', $messages);
        $this->assertStringContainsString('The "Problem" step has no claim', $messages);
        $this->assertStringNotContainsString('"Evidence" claim has no evidence', $messages);
        $this->actingAs($this->reader)->get(route('research.studios.argument', $this->project))->assertOk()->assertSee('Rates, not tolls, paid for it.');
    }

    public function test_the_writing_review_and_publication_studios_and_source_triage(): void
    {
        $claim = app(Claims::class)->save($this->reader, $this->project, ['text' => 'Rates paid for the iron bridge.', 'status' => 'supported']);
        $this->actingAs($this->reader)->post(route('research.bibliographies.store'), ['name' => 'Thesis sources', 'citation_style' => 'chicago'])->assertSessionHasNoErrors();
        $bibliography = Bibliography::sole();
        $this->actingAs($this->reader)->post(route('research.bibliographies.step', [$bibliography, 'entry']), ['entry_type' => 'book', 'title' => 'Bridges of the Cape', 'authors' => 'Smit, Jan', 'date' => '1950',
            'publisher' => 'Van Schaik'])->assertSessionHasNoErrors();
        app(Projects::class)->assign($this->reader, $bibliography, $this->project);
        $entry = $bibliography->entries()->sole();

        $this->actingAs($this->other)->post(route('research.studios.writing.step', [$this->project, 'doc']), ['title' => 'Chapter one', 'doc_type' => 'thesis-chapter'])->assertRedirect();
        $doc = ResearchWritingDoc::sole();
        $this->actingAs($this->other)->post(route('research.studios.writing.step', [$this->project, 'section', $doc->id]), ['heading' => 'Funding', 'body' => 'The council raised a rate.'])->assertSessionHasNoErrors();
        $section = $doc->sections()->sole();
        $this->actingAs($this->other)->post(route('research.studios.writing.step', [$this->project, 'pull', $section->id]), ['kind' => 'claim', 'pull_id' => $claim->id])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.studios.writing.step', [$this->project, 'pull', $section->id]), ['kind' => 'source', 'pull_id' => $entry->id])->assertSessionHasNoErrors();
        $this->assertSame("The council raised a rate.\n\nRates paid for the iron bridge. [Claim]\n\nSmit, Jan (1950) Bridges of the Cape. Van Schaik.", $section->fresh()->body);
        $this->actingAs($this->other)->post(route('research.studios.writing.step', [$this->project, 'version', $doc->id]), ['note' => 'First full draft'])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.studios.writing.step', [$this->project, 'update-section', $section->id]), ['heading' => 'Funding', 'body' => 'Rewritten.'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('The council raised a rate.', json_encode($doc->versions()->sole()->snapshot));
        $markdown = $this->actingAs($this->reader)->get(route('research.studios.doc', [$doc, 'format' => 'markdown']))->assertOk()->getContent();
        $this->assertStringContainsString("# Chapter one\n\n_Thesis chapter - Draft_\n\n## Funding\n\nRewritten.", $markdown);

        // The review studio: a comment on a claim, a reply in its thread, resolved.
        $this->actingAs($this->admin)->post(route('research.studios.review.step', $this->project), ['body' => 'x'])->assertSessionHasErrors('research');
        $this->actingAs($this->other)->post(route('research.studios.review.step', $this->project), ['body' => 'Which rate book?', 'claim_id' => $claim->id])->assertSessionHasNoErrors();
        $comment = $this->project->reviewComments()->sole();
        $this->actingAs($this->reader)->post(route('research.studios.review.step', $this->project), ['body' => 'The 1898 book.', 'thread_id' => $comment->id])->assertSessionHasNoErrors();
        $this->assertSame($claim->id, $this->project->reviewComments()->whereNotNull('thread_id')->sole()->claim_id);
        $this->actingAs($this->reader)->post(route('research.studios.review.step', [$this->project, $comment]))->assertSessionHasNoErrors();
        $this->assertTrue($comment->fresh()->resolved);

        // The publication studio: venues by the project's words; a manuscript moved as the transitions allow.
        $target = ResearchTargetJournal::create(['institution_id' => $this->institution->id, 'title' => 'Journal of the Society of Archivists', 'subject_scope' => 'Archives, records management, bridges',
            'status_id' => Concept::where('iri', Vocab::BASE.'target-journal-status/active')->value('id')]);
        $this->actingAs($this->reader)->get(route('research.studios.publication', $this->project))->assertOk()->assertSee('Journal of the Society of Archivists')->assertSee('words in common');
        $this->actingAs($this->other)->post(route('research.studios.publication.step', [$this->project, 'save']), ['manuscript_title' => 'Iron over the river', 'target_journal_id' => $target->id])->assertSessionHasNoErrors();
        $submission = ResearchPublicationSubmission::sole();
        $this->assertSame([$target->title, 7], [$submission->venue_name, $submission->requirements()->count()]);
        $move = fn (string $to) => $this->actingAs($this->other)->post(route('research.studios.publication.step', [$this->project, 'move', $submission]), ['to' => $to]);
        $move('published')->assertSessionHasErrors('research');
        $move('submitted')->assertSessionHasNoErrors();
        $move('reviewed')->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.studios.publication.step', [$this->project, 'response', $submission]), ['reviewer_label' => 'Reviewer 1', 'point' => 'Cite the rate books.',
            'response' => 'Added', 'revision_note' => 'Section 2'])->assertSessionHasNoErrors();
        $move('accepted')->assertSessionHasNoErrors();
        $submission->refresh();
        $this->assertSame(['accepted', '2026-11-08', '2026-11-08'], [$submission->status->notation, $submission->submitted_at->toDateString(), $submission->decision_at->toDateString()]);

        // Source triage of the project's works and items.
        $collection = app(Collections::class)->save($this->reader, ['name' => 'Bridge sources']);
        $item = app(Collections::class)->add($this->reader, $collection, $this->letter);
        app(Projects::class)->assign($this->reader, $collection, $this->project);
        $this->actingAs($this->other)->post(route('research.studios.triage.save', $this->project), ['type' => 'collection-item', 'id' => $item->id, 'triage_category' => 'essential', 'read_status' => 'deeply-read'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.studios.triage.save', $this->project), ['type' => 'bibliography-entry', 'id' => $entry->id, 'triage_category' => 'background'])->assertSessionHasNoErrors();
        $this->actingAs($this->other)->post(route('research.studios.triage.save', $this->project), ['type' => 'collection-item', 'id' => $claim->id])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->get(route('research.studios.triage', [$this->project, 'category' => 'essential']))->assertOk()->assertSee('RA PC/1')->assertDontSee('Bridges of the Cape');

        // The time machine, and the project's export with its brief, claims and argument.
        app(Studios::class)->saveBrief($this->reader, $this->project, ['primary_question' => 'How was the bridge paid for?']);
        $this->actingAs($this->reader)->get(route('research.studios.time-machine', [$this->project, 'as_of' => '2026-11-08']))->assertOk()->assertSee('Research brief, version 1')
            ->assertSee('Claim made');
        $json = json_decode($this->actingAs($this->reader)->get(route('research.projects.export', [$this->project, 'json']))->assertOk()->getContent(), true);
        $this->assertSame('How was the bridge paid for?', $json['questionBrief']['primary_question']);
        $this->assertSame('Rates paid for the iron bridge.', $json['claims'][0]['claim']);
    }

    public function test_research_journals_and_the_directory(): void
    {
        $this->actingAs($this->reader)->post(route('research.journals.directory.save'), ['seed' => 1])->assertSessionHasErrors('research');
        $this->actingAs($this->admin)->post(route('research.journals.directory.save'), ['seed' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('research.journals.directory.save'), ['seed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(12, ResearchTargetJournal::count());
        $jsasa = ResearchTargetJournal::where('title', 'Journal of the South African Society of Archivists')->sole();
        $jsasa->update(['max_words' => 50, 'abstract_max_words' => 5]);
        $this->actingAs($this->reader)->get(route('research.journals.directory', ['market' => 'za', 'q' => 'archives']))->assertOk()->assertSee('ESARBICA Journal')->assertDontSee('Cultural History');

        $this->actingAs($this->reader)->post(route('research.journals.store'), ['title' => 'Bridge paper', 'kind' => 'manuscript', 'issn' => '12345', 'target_journal_id' => $jsasa->id])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.journals.store'), ['title' => 'Bridge paper', 'kind' => 'manuscript', 'target_journal_id' => $jsasa->id, 'institutional' => 1])->assertSessionHasErrors('research');
        $this->actingAs($this->reader)->post(route('research.journals.store'), ['title' => 'Bridge paper', 'kind' => 'manuscript', 'target_journal_id' => $jsasa->id])->assertRedirect();
        $journal = ResearchJournal::sole();
        $this->actingAs($this->reader)->post(route('research.journals.step', [$journal, 'issue']), ['volume' => '1', 'number' => '1', 'title' => 'First'])->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('research.journals.step', [$journal, 'article']), ['title' => 'Iron over the river', 'authors' => 'Rita', 'abstract' => 'How the council paid for the bridge.',
            'body_markdown' => "## Funding\n\nThe council raised a rate. <script>alert(1)</script>", 'reference_style' => 'APA'])->assertRedirect();
        $article = $journal->articles()->sole();
        $this->assertSame(8, $article->word_count);
        $page = $this->actingAs($this->reader)->get(route('research.journals.show', [$journal, 'article' => $article->id]))->assertOk();
        $page->assertSee('Reference style should be Harvard for this journal.')->assertSee('The abstract has 7 words; this journal allows 5.')->assertSee('<h2>Funding</h2>', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($this->reader)->get(route('research.journals.show', $journal))->assertOk()->assertSee('Not yet in an issue');
        $this->actingAs($this->other)->get(route('research.journals.show', $journal))->assertNotFound();
        $this->actingAs($this->other)->post(route('research.journals.step', [$journal, 'issue']), ['volume' => '2'])->assertSessionHasErrors('research');
    }
}
