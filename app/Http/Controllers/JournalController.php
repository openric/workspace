<?php

// OpenRiC Workspace - research journals and the directory of journals to aim at (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchJournalController and ResearchTargetJournalController.

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\ResearchJournal;
use App\Models\ResearchJournalArticle;
use App\Models\ResearchJournalIssue;
use App\Models\ResearchTargetJournal;
use App\Research\Journals;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class JournalController extends Controller
{
    public function __construct(private Journals $journals) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('journals.index', ['journals' => $this->journals->visible($user), 'run' => $this->journals->mayRun($user)] + $this->choices($user));
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $journal = $this->journals->saveJournal($request->user(), $this->validated($request) + ['institutional' => $request->boolean('institutional')]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.journals.show', $journal)->with('status', __('Saved.'));
    }

    public function show(Request $request, ResearchJournal $journal): View
    {
        $user = $request->user();
        $edit = $this->journals->mayEdit($user, $journal);
        abort_unless($edit || $journal->institution_id === $user->institution_id && $journal->status->notation === 'published', 404);

        return view('journals.show', ['journal' => $journal->load('kind', 'status', 'targetJournal', 'coverRecord'), 'contents' => $this->journals->contents($journal), 'edit' => $edit,
            'service' => $this->journals, 'issueStatuses' => Concept::choices(Vocab::BASE.'issue-status/')->get(), 'articleStatuses' => Concept::choices(Vocab::BASE.'article-status/')->get(),
            'article' => $request->filled('article') ? $journal->articles()->with('status', 'issue', 'targetJournal')->find($request->query('article')) : null] + $this->choices($user));
    }

    public function step(Request $request, ResearchJournal $journal, string $step, ?string $id = null): RedirectResponse
    {
        $user = $request->user();
        $issue = $id && $step === 'issue' ? $journal->issues()->findOrFail($id) : null;
        $article = $id && $step === 'article' ? $journal->articles()->findOrFail($id) : null;
        $data = $request->validate(['volume' => ['nullable', 'string', 'max:40'], 'number' => ['nullable', 'string', 'max:40'], 'title' => ['nullable', 'string', 'max:500'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:5000'], 'status' => ['nullable', 'string', 'max:64'], 'sort_order' => ['nullable', 'integer'],
            'issue_id' => ['nullable', 'uuid'], 'authors' => ['nullable', 'string', 'max:5000'], 'abstract' => ['nullable', 'string', 'max:20000'], 'keywords' => ['nullable', 'string', 'max:500'],
            'body_markdown' => ['nullable', 'string', 'max:1000000'], 'reference_style' => ['nullable', 'string', 'max:40'], 'doi' => ['nullable', 'string', 'max:128'], 'target_journal_id' => ['nullable', 'uuid']]);
        if ($step === 'article' && ! $article) {
            try {
                $article = $this->journals->saveArticle($user, $journal, $data);
            } catch (InvalidArgumentException $e) {
                return back()->withInput()->withErrors(['research' => $e->getMessage()]);
            }

            return redirect()->route('research.journals.show', [$journal, 'article' => $article->id])->with('status', __('Saved.'));
        }

        return $this->attempt(fn () => match ($step) {
            'update' => $this->journals->saveJournal($user, $this->validated($request), $journal),
            'issue' => $this->journals->saveIssue($user, $journal, $data, $issue),
            'article' => $this->journals->saveArticle($user, $journal, $data, $article),
            'delete' => $this->journals->deleteJournal($user, $journal),
        }, __('Saved.'), $step === 'delete' ? 'research.journals.index' : null);
    }

    public function remove(Request $request, string $kind, string $id): RedirectResponse
    {
        $thing = ($kind === 'issues' ? ResearchJournalIssue::class : ResearchJournalArticle::class)::findOrFail($id);

        return $this->attempt(fn () => $this->journals->remove($request->user(), $thing), __('Removed.'));
    }

    // The directory.

    public function directory(Request $request): View
    {
        $user = $request->user();

        return view('journals.directory', ['journals' => $this->journals->directory($user, $request->only(['q', 'market', 'open_access'])), 'run' => $this->journals->mayRun($user),
            'editing' => $request->filled('edit') ? ResearchTargetJournal::where(['id' => $request->query('edit'), 'institution_id' => $user->institution_id])->first() : null,
            'statuses' => Concept::choices(Vocab::BASE.'target-journal-status/')->get()]);
    }

    public function saveTarget(Request $request, ?ResearchTargetJournal $journal = null): RedirectResponse
    {
        if ($request->boolean('seed')) {
            try {
                $n = $this->journals->seedDhet($request->user());
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }

            return back()->with('status', trans_choice('{1} :count journal added or brought up to date.|[2,*] :count journals added or brought up to date.', $n, ['count' => $n]));
        }
        abort_if($journal && $journal->institution_id !== $request->user()->institution_id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:300'], 'issn' => ['nullable', 'string', 'max:9'], 'eissn' => ['nullable', 'string', 'max:9'],
            'max_words' => ['nullable', 'integer', 'min:1'], 'abstract_max_words' => ['nullable', 'integer', 'min:1'], 'open_access' => ['nullable', 'boolean'], 'status' => ['nullable', 'string', 'max:64'],
            'accreditation_market' => ['nullable', 'string', 'max:8'], 'homepage_url' => ['nullable', 'url', 'max:1000'], 'submission_url' => ['nullable', 'url', 'max:1000'],
            ...array_fill_keys(['subtitle', 'publisher', 'languages', 'subject_scope', 'article_types', 'accreditation', 'reference_style', 'structure_notes', 'peer_review', 'apc_amount', 'turnaround', 'notes'],
                ['nullable', 'string', 'max:5000'])]);

        return $this->attempt(fn () => $this->journals->saveTarget($request->user(), $data, $journal), __('Saved.'), 'research.journals.directory');
    }

    private function choices($user): array
    {
        return ['kinds' => Concept::choices(Vocab::BASE.'journal-kind/')->get(), 'statuses' => Concept::choices(Vocab::BASE.'journal-status/')->get(),
            'targets' => ResearchTargetJournal::where('institution_id', $user->institution_id)->orderBy('title')->get()];
    }

    private function validated(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:255'], 'kind' => ['nullable', 'string', 'max:64'], 'status' => ['nullable', 'string', 'max:64'],
            'target_journal_id' => ['nullable', 'uuid'], 'cover_iri' => ['nullable', 'string', 'max:2048'], 'issn' => ['nullable', 'string', 'max:9'], 'eissn' => ['nullable', 'string', 'max:9'],
            'editor_email' => ['nullable', 'email', 'max:255'], 'doi' => ['nullable', 'string', 'max:128'],
            ...array_fill_keys(['subtitle', 'publisher', 'description', 'aims_scope', 'editor_name'], ['nullable', 'string', 'max:5000'])]);
    }

    private function attempt(callable $do, string $done, ?string $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route($route) : back())->with('status', $done);
    }
}
