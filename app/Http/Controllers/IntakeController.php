<?php

// OpenRiC Workspace - watched folders and the log of what they and the scan API took in (roadmap phase 22a), after
// PSIS's watch folder settings and Heratio's scan inbox; and the scan API itself, after Heratio's ScanApiController:
// a scanner or capture station posts a file, or the pages of one item, with its description or a sidecar, using an API
// key with the write scope, and a record is made of it as a watched folder would.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Ingest\Intake;
use App\Ingest\WatchFolders;
use App\Models\Concept;
use App\Models\ImportMapping;
use App\Models\IntakeFile;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Models\WatchFolder;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class IntakeController extends Controller
{
    public function index(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('intake.index', ['folders' => WatchFolder::where('institution_id', $institution)->with('repository', 'parent', 'level', 'user', 'mapping')->orderBy('label')->get(),
            'log' => IntakeFile::where('institution_id', $institution)->with('folder', 'outcome', 'import', 'record')->orderByDesc('created_at')->limit(100)->get(),
            'root' => WatchFolders::root(), 'repositories' => Repository::where('institution_id', $institution)->whereNull('deleted_at')->get(),
            'levels' => Concept::choices(Vocab::BASE.'level-of-description/')->get(), 'mappings' => ImportMapping::where('institution_id', $institution)->orderBy('name')->get(),
            'accounts' => User::where('institution_id', $institution)->orderBy('name')->get()]);
    }

    public function save(Request $request, ?WatchFolder $folder = null): RedirectResponse
    {
        $institution = $request->user()->institution_id;
        abort_if($folder && $folder->institution_id !== $institution, 404);
        $data = $request->validate(['label' => ['required', 'string', 'max:255'], 'path' => ['required', 'string', 'max:500'],
            'format' => ['required', Rule::in(['csv', 'authority-csv', 'repository-csv'])], 'language' => ['required', 'string', 'max:35'],
            'repository_id' => ['required', 'uuid'], 'parent_iri' => ['nullable', 'string', 'max:500'], 'import_mapping_id' => ['nullable', 'uuid'],
            'level' => ['required', 'string', 'max:64'], 'user_id' => ['required', 'uuid'], 'auto_commit' => ['nullable', 'boolean'], 'is_enabled' => ['nullable', 'boolean']]);
        $fail = fn (string $message) => back()->withInput()->withErrors(['intake' => $message]);
        if (WatchFolders::resolve($data['path']) === null) {
            return $fail(WatchFolders::root() === null ? __('The installation has no intake root (WORKSPACE_INTAKE_ROOT); ask who runs the server.')
                : __('Name a folder that exists under the intake root, relative to it.'));
        }
        $repository = Repository::where('institution_id', $institution)->whereNull('deleted_at')->find($data['repository_id']) ?? abort(404);
        $parent = ($data['parent_iri'] ?? '') ? RecordResource::where(['institution_id' => $institution, 'iri' => trim($data['parent_iri'])])->whereNull('deleted_at')->first() : null;
        if (($data['parent_iri'] ?? '') && ! $parent) {
            return $fail(__('No record has that IRI.'));
        }
        $account = User::where('institution_id', $institution)->find($data['user_id']) ?? abort(404);
        $level = Concept::choices(Vocab::BASE.'level-of-description/')->get()->firstWhere('notation', $data['level']) ?? abort(404);
        $fields = ['label' => trim($data['label']), 'path' => trim(str_replace('\\', '/', $data['path']), '/'), 'format' => $data['format'], 'language' => $data['language'],
            'repository_id' => $repository->id, 'parent_id' => $parent?->id, 'level_id' => $level->id, 'user_id' => $account->id,
            'import_mapping_id' => ImportMapping::where('institution_id', $institution)->find($data['import_mapping_id'] ?? null)?->id,
            'auto_commit' => $request->boolean('auto_commit'), 'is_enabled' => $request->boolean('is_enabled')];
        $folder ? $folder->update($fields) : WatchFolder::create($fields + ['institution_id' => $institution, 'created_by_id' => $request->user()->id]);

        return redirect()->route('intake.index')->with('status', __('Saved.'));
    }

    public function run(Request $request, WatchFolder $folder): RedirectResponse
    {
        abort_unless($folder->institution_id === $request->user()->institution_id, 404);
        $count = app(WatchFolders::class)->run($folder);

        return back()->with('status', trans_choice(':count file taken in or refused.|:count files taken in or refused.', $count, ['count' => $count]));
    }

    /** POST /api/ric/v1/intake: one item's files, with its description or sidecar, made a record (Heratio's scan API). */
    public function api(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_user');
        $request->validate(['files' => ['required', 'array', 'max:100'], 'files.*' => ['file'], 'sidecar' => ['nullable', 'file'],
            'parent' => ['required', 'string', 'max:500'], 'title' => ['nullable', 'string', 'max:1024'], 'identifier' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:64'], 'scope_and_content' => ['nullable', 'string', 'max:20000'], 'date' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:35']]);
        $parent = RecordResource::where(['institution_id' => $user->institution_id, 'iri' => trim((string) $request->input('parent'))])->whereNull('deleted_at')->first();
        if (! $parent) {
            return response()->json(['error' => __('No record has that IRI.')], 422);
        }
        try {
            $meta = $request->file('sidecar') ? Intake::sidecar((string) file_get_contents($request->file('sidecar')->getRealPath())) : [];
            $date = trim((string) $request->input('date'));
            $meta = array_filter(['title' => $request->input('title'), 'identifier' => $request->input('identifier'), 'level' => $request->input('level'),
                'scope' => $request->input('scope_and_content'), 'dates' => $date !== '' ? [['start' => $date, 'end' => null, 'display' => null]] : null]) + $meta;
            $files = collect($request->file('files'))->map(fn ($f) => ['path' => $f->getRealPath(), 'name' => $f->getClientOriginalName()])->sortBy('name', SORT_NATURAL)->values()->all();
            $level = Concept::choices(Vocab::BASE.'level-of-description/')->get()->firstWhere('notation', 'item');
            $record = app(Intake::class)->record($user, $parent->repository ?? collect($parent->ancestors())->first()?->repository, $parent, $level, $meta, $files, $request->input('language') ?: 'en');
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        foreach ($files as $file) {
            IntakeFile::create(['institution_id' => $user->institution_id, 'api_key_id' => $request->attributes->get('api_key')?->id, 'file_name' => mb_substr($file['name'], 0, 500),
                'outcome_id' => Concept::where('iri', Vocab::BASE.'intake-outcome/item-created')->value('id'), 'record_resource_id' => $record->id, 'created_at' => now()]);
        }

        return response()->json(['@id' => $record->iri, 'id' => $record->id, 'files' => count($files)], 201);
    }
}
