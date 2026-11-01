<?php

// OpenRiC Workspace - researcher submissions and their review (roadmap phase 19e, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's ahgResearcherPlugin researcher module (dashboard, submissions, newSubmission, viewSubmission, addItem,
// editItem, importExchange, pending, publish) and Heratio's ResearcherSubmissionController: one's submissions and
// their items and files; the reviewers' queue, their decisions and the publication.

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Repository;
use App\Models\ResearchCollection;
use App\Models\ResearcherSubmission;
use App\Models\ResearcherSubmissionFile;
use App\Models\ResearcherSubmissionItem;
use App\Relations\EntityKinds;
use App\Research\ResearchFiles;
use App\Research\Submissions;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class SubmissionController extends Controller
{
    public function __construct(private Submissions $submissions) {}

    /** PSIS's dashboard and submissions list: one's own, by status. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $status = $request->query('status');

        return view('submissions.index', ['submissions' => ResearcherSubmission::where('user_id', $user->id)->with('status')
            ->when(is_string($status) && $status !== '', fn ($q) => $q->whereHas('status', fn ($s) => $s->where('iri', Submissions::STATUSES.$status)))->orderByDesc('updated_at')->get(),
            'stats' => $this->submissions->stats($user), 'statuses' => Concept::choices(Submissions::STATUSES)->get(), 'status' => $status, 'reviewer' => $this->submissions->mayReview($user),
            'collections' => ResearchCollection::where('user_id', $user->id)->orderBy('name')->get(), 'repositories' => $this->repositories($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $submission = $this->submissions->create($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.submissions.show', $submission)->with('status', __('Saved.'));
    }

    public function fromCollection(Request $request): RedirectResponse
    {
        $collection = ResearchCollection::where(['id' => $request->input('collection_id'), 'user_id' => $request->user()->id])->first() ?? abort(404);
        try {
            $submission = $this->submissions->fromCollection($request->user(), $collection);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.submissions.show', $submission)->with('status', __('Saved.'));
    }

    /** PSIS's importExchange: an exchange file made offline becomes a draft. */
    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate(['exchange_file' => ['required', 'file', 'max:51200'], 'repository_id' => ['nullable', 'uuid']]);
        try {
            $result = $this->submissions->importExchange($request->user(), $request->file('exchange_file'), $data);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['research' => $e->getMessage()]);
        }
        $c = $result['counts'];

        return redirect()->route('research.submissions.show', $result['submission'])->with('status', __('Imported: :descriptions descriptions, :notes notes, :creators creators, :repositories repositories and :files files.', $c));
    }

    public function show(Request $request, ResearcherSubmission $submission): View
    {
        $user = $request->user();
        $reviewer = $this->submissions->mayReview($user) && $submission->institution_id === $user->institution_id;
        abort_unless($submission->user_id === $user->id || $reviewer, 404);
        $submission->load('status', 'sourceType', 'user', 'reviewer', 'repository', 'parentRecord', 'sourceFile', 'import', 'items.itemType', 'items.level', 'items.referenceRecord',
            'items.files.file', 'items.parentItem', 'reviews.action', 'reviews.reviewer');

        return view('submissions.show', ['submission' => $submission, 'own' => $submission->user_id === $user->id, 'reviewer' => $reviewer,
            'open' => $submission->user_id === $user->id && in_array($submission->status->notation, ['draft', 'returned'], true),
            'levels' => $this->submissions->levels($submission->institution_id), 'types' => Concept::choices(Submissions::TYPES)->get(), 'repositories' => $this->repositories($request),
            'editing' => $request->filled('item') ? $submission->items->firstWhere('id', $request->query('item')) : null]);
    }

    public function update(Request $request, ResearcherSubmission $submission): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(fn () => $this->submissions->update($request->user(), $submission, $data), __('Saved.'));
    }

    public function step(Request $request, ResearcherSubmission $submission, string $step): RedirectResponse
    {
        $user = $request->user();

        return $this->attempt(fn () => match ($step) {
            'submit' => $this->submissions->submit($user, $submission),
            'delete' => $this->submissions->delete($user, $submission),
        }, $step === 'submit' ? __('Sent for review.') : __('Deleted.'), $step === 'delete' ? 'research.submissions.index' : null);
    }

    public function saveItem(Request $request, ResearcherSubmission $submission, ?ResearcherSubmissionItem $item = null): RedirectResponse
    {
        $data = $request->validate(['item_type' => ['nullable', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:255'], 'identifier' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:2048'], 'parent_item_id' => ['nullable', 'uuid'], 'scope_and_content' => ['nullable', 'string', 'max:65535'],
            'extent_and_medium' => ['nullable', 'string', 'max:500'], 'date_display' => ['nullable', 'string', 'max:255'], 'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'], 'creators' => ['nullable', 'string', 'max:5000'], 'subjects' => ['nullable', 'string', 'max:5000'], 'places' => ['nullable', 'string', 'max:5000'],
            'genres' => ['nullable', 'string', 'max:5000'], 'access_conditions' => ['nullable', 'string', 'max:5000'], 'reproduction_conditions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:65535'], 'repository_name' => ['nullable', 'string', 'max:500'], 'repository_address' => ['nullable', 'string', 'max:2000'],
            'repository_contact' => ['nullable', 'string', 'max:500'], 'reference_iri' => ['nullable', 'string', 'max:2048']]);
        if ($item && $item->submission_id !== $submission->id) {
            abort(404);
        }
        try {
            $this->submissions->saveItem($request->user(), $submission, $data, $item);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.submissions.show', $submission)->with('status', __('Saved.'));
    }

    public function itemStep(Request $request, ResearcherSubmissionItem $item, string $step): RedirectResponse
    {
        $data = $request->validate(['file' => ['nullable', 'file', 'max:'.ResearchFiles::MAX_KILOBYTES], 'caption' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => match ($step) {
            'remove' => $this->submissions->removeItem($request->user(), $item),
            'file' => $this->submissions->addFile($request->user(), $item, $request->file('file') ?? throw new InvalidArgumentException(__('Choose a file.')), $data['caption'] ?? null),
        }, __('Saved.'));
    }

    public function removeFile(Request $request, ResearcherSubmissionFile $file): RedirectResponse
    {
        return $this->attempt(fn () => $this->submissions->removeFile($request->user(), $file), __('Removed.'));
    }

    /** A file of a submission, for its researcher and its reviewers. */
    public function file(Request $request, ResearcherSubmissionFile $file): Response
    {
        $submission = $file->item->submission;
        $user = $request->user();
        abort_unless($file->file && ($submission->user_id === $user->id || $this->submissions->mayReview($user) && $submission->institution_id === $user->institution_id), 404);

        return app(ResearchFiles::class)->respond($file->file, true);
    }

    // Review.

    /** PSIS's pending: the reviewers' queue. */
    public function pending(Request $request): View
    {
        abort_unless($this->submissions->mayReview($request->user()), 403);
        $status = $request->query('status');

        return view('submissions.pending', ['submissions' => $this->submissions->pending($request->user(), is_string($status) && $status !== '' ? $status : null)->get(),
            'statuses' => Concept::choices(Submissions::STATUSES)->get(), 'status' => $status]);
    }

    public function review(Request $request, ResearcherSubmission $submission, string $action): RedirectResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => $this->submissions->review($request->user(), $submission, $action, $data['comment'] ?? null), __('Saved.'));
    }

    /** PSIS's publish: a dry run to see what the import will do, then the publication. */
    public function publish(Request $request, ResearcherSubmission $submission): RedirectResponse
    {
        $options = $request->validate(['repository_id' => ['nullable', 'uuid'], 'language' => ['nullable', 'string', 'max:35'], 'publish' => ['nullable', 'boolean'],
            'create_terms' => ['nullable', 'boolean'], 'dry_run' => ['nullable', 'boolean']]);
        $options['publish'] = $request->boolean('publish');
        $options['create_terms'] = $request->boolean('create_terms');
        try {
            $this->submissions->publish($request->user(), $submission, $options, $request->boolean('dry_run'));
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return back()->with('status', $request->boolean('dry_run') ? __('The dry run is done; its report is below.') : __('Published.'));
    }

    private function repositories(Request $request)
    {
        return Repository::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->get()
            ->sortBy(fn ($r) => mb_strtolower(EntityKinds::label($r, Vocab::languages())))->values();
    }

    private function validated(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'repository_id' => ['nullable', 'uuid'],
            'parent_iri' => ['nullable', 'string', 'max:2048']]);
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
