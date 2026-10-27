<?php

// OpenRiC Workspace - import units of description: upload, dry run and report, then import (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Uploading always makes a dry run; nothing is written until the person who
// uploaded the file reads the report and imports it. Every import, run or
// not, stays listed with its report.

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Exchange\Import\ImportService;
use App\Jobs\JobsService;
use App\Jobs\RunImport;
use App\Models\Agent;
use App\Models\BackgroundJob;
use App\Models\Concept;
use App\Models\Import;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ImportController extends Controller
{
    private const LEVELS = Vocab::BASE.'level-of-description/';

    public function __construct(private ImportService $imports) {}

    public function index(Request $request): View
    {
        abort_unless($this->mayImport($request), 403);

        return view('imports.index', ['imports' => Import::where('institution_id', $request->user()->institution_id)->with('user')->latest()->limit(100)->get()]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->mayImport($request), 403);
        $parent = $request->query('parent') ? $this->unit($request, (string) $request->query('parent')) : null;

        return view('imports.create', ['parent' => $parent, 'formats' => $this->formats($request), 'repositories' => $this->repositories($request),
            'agentTypes' => Concept::choices(Agent::TYPES)->get()->filter(fn ($t) => in_array($t->notation, ['person', 'family', 'corporate-body'], true)),
            'levels' => Concept::choices(self::LEVELS)->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->mayImport($request), 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'format' => ['required', Rule::in(array_keys($this->formats($request)))],
            'source' => ['nullable', 'string', 'max:255'],
            'repository' => [Rule::requiredIf(fn () => ImportService::permission((string) $request->input('format')) === 'description'), 'nullable', 'uuid'],
            'parent' => ['nullable', 'uuid'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'agent_type' => ['nullable', Rule::in(['person', 'family', 'corporate-body'])],
            'create_terms' => ['nullable', 'boolean'],
            'match_identifier' => ['nullable', 'boolean'],
            'default_level' => ['nullable', Rule::in(Concept::choices(self::LEVELS)->pluck('notation')->all())],
        ]);
        $describes = ImportService::permission($data['format']) === 'description';
        $repository = $describes ? ($this->repositories($request)->firstWhere('id', $data['repository']) ?? abort(404)) : null;
        $parent = $describes && isset($data['parent']) ? $this->unit($request, $data['parent']) : null;
        $content = (string) file_get_contents($request->file('file')->getRealPath());
        if (! mb_check_encoding($content, 'UTF-8')) {
            return back()->withInput()->withErrors(['file' => 'The file is not UTF-8. Save it as UTF-8 (in a spreadsheet, "CSV UTF-8") and upload it again.']);
        }
        $name = $request->file('file')->getClientOriginalName();
        $import = Import::create([
            'institution_id' => $user->institution_id, 'user_id' => $user->id, 'format' => $data['format'],
            'source' => trim((string) ($data['source'] ?? '')) ?: $name, 'file_name' => $name, 'file_sha256' => hash('sha256', $content),
            'content' => $content, 'language' => $data['language'], 'repository_id' => $repository?->id, 'parent_id' => $parent?->id,
            'options' => ['agent_type' => $data['agent_type'] ?? null, 'create_terms' => (bool) ($data['create_terms'] ?? false),
                'match_identifier' => (bool) ($data['match_identifier'] ?? false), 'default_level' => $describes ? ($data['default_level'] ?? null) : null],
        ]);
        $job = $this->queue($import, false);

        return redirect()->route('imports.show', $import)->with('status', $job->active()
            ? __('The dry run is under way. This page shows the report when it is done.')
            : __('Dry run done: nothing has been written yet. Read the report, then import.'));
    }

    public function show(Request $request, Import $import): View
    {
        $this->own($request, $import);

        $job = app(JobsService::class)->latestFor($import);

        return view('imports.show', ['import' => $import->load('user', 'repository', 'parent'), 'job' => $job,
            'mayCommit' => $import->committed_at === null && $import->user_id === $request->user()->id && ! $job?->active()]);
    }

    public function commit(Request $request, Import $import): RedirectResponse
    {
        $this->own($request, $import);
        abort_unless($import->user_id === $request->user()->id, 403);
        if ($import->committed_at !== null) {
            return redirect()->route('imports.show', $import)->withErrors(['import' => 'This file was imported already. Upload it again to import it again.']);
        }
        if (app(JobsService::class)->latestFor($import)?->active()) {
            return redirect()->route('imports.show', $import)->withErrors(['import' => __('This file is being read already; wait until it is done.')]);
        }
        $job = $this->queue($import, true);
        if ($job->active()) {
            return redirect()->route('imports.show', $import)->with('status', __('The import is under way. This page shows the report when it is done.'));
        }
        $actions = $import->refresh()->report['actions'] ?? ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0];

        return redirect()->route('imports.show', $import)->with('status', __('Imported: :created created, :updated updated, :unchanged unchanged, :rejected not imported.', $actions));
    }

    /** The dry run or the import as a background job (roadmap phase 5); where the queue runs at once, it is done on return. */
    private function queue(Import $import, bool $commit): BackgroundJob
    {
        $job = app(JobsService::class)->create($import->institution_id, 'import', __($commit ? 'Import of :name' : 'Dry run of :name', ['name' => $import->file_name]), $import->user, $import);
        RunImport::dispatch($job->id, $import->id, $commit);

        return $job->refresh();
    }

    /** Another institution's import does not exist here. */
    private function own(Request $request, Import $import): void
    {
        abort_unless($import->institution_id === $request->user()->institution_id, 404);
        abort_unless($this->mayImport($request), 403);
    }

    /** The formats the account may import: each needs the create permission of the kind of entity it makes. */
    private function formats(Request $request): array
    {
        return array_filter(ImportService::FORMATS, fn ($name, $format) => $request->user()->mayAnywhere('create', ImportService::permission($format)), ARRAY_FILTER_USE_BOTH);
    }

    private function mayImport(Request $request): bool
    {
        return $this->formats($request) !== [];
    }

    private function unit(Request $request, string $id): RecordResource
    {
        $unit = RecordResource::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->find($id) ?? abort(404);
        abort_unless($request->user()->may('create', 'description', $unit), 403);

        return $unit;
    }

    private function repositories(Request $request)
    {
        return Repository::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->with('names.nameType', 'descriptionValues.element')->get()
            ->filter(fn (Repository $r) => $request->user()->may('create', 'description', $r))->values();
    }
}
