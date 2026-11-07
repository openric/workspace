<?php

// OpenRiC Workspace - offline catalogues (roadmap phase 11c), after Heratio's PortableExportController: the packages
// an institution has made, a new one of everything, one repository, one unit or the clipboard, built in the background;
// downloading and deleting them; and links that let someone without an account download a read-only or editable one.
// Changed from Heratio: an archive (which may hold drafts and masters) is never given a link; a link's token is shown
// once and kept as its SHA-256; deleting a package purges it from the object store.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Clipboard\ClipboardService;
use App\Description\Bcp47;
use App\Exchange\OfflineCatalogue\Builder;
use App\Files\FileStore;
use App\Jobs\BuildOfflineCatalogue;
use App\Jobs\JobsService;
use App\Models\Concept;
use App\Models\OfflineCatalogue;
use App\Models\OfflineCatalogueShare;
use App\Models\RecordResource;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OfflineCatalogueController extends Controller
{
    public function __construct(private FileStore $store) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $unit = $request->query('record') ? RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')->find($request->query('record')) : null;

        return view('offline-catalogues.index', [
            'catalogues' => OfflineCatalogue::where('institution_id', $user->institution_id)->with('mode.labels', 'status.labels', 'user', 'repository.names.nameType', 'record.names.nameType',
                'shares')->orderByDesc('created_at')->limit(100)->get(),
            'modes' => Concept::choices(Builder::MODES)->get(),
            'repositories' => Repository::where('institution_id', $user->institution_id)->whereNull('deleted_at')->with('names.nameType', 'descriptionValues.element')->get(),
            'unit' => $unit, 'clipboard' => count(app(ClipboardService::class)->items($user)['records']),
            'mayMasters' => $user->mayAnywhere('read-master', 'description'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in(['read-only', 'editable', 'archive'])],
            'scope' => ['required', Rule::in(['everything', 'repository', 'unit', 'clipboard'])],
            'repository_id' => ['nullable', 'required_if:scope,repository', 'uuid'],
            'record_resource_id' => ['nullable', 'required_if:scope,unit', 'uuid'],
            'language' => ['nullable', 'string', 'max:35'],
            'include_files' => ['boolean'], 'include_masters' => ['boolean'],
        ]);
        $language = trim((string) ($data['language'] ?? '')) ?: null;
        if ($language !== null && ! Bcp47::valid($language)) {
            return back()->withInput()->withErrors(['language' => __('Give a BCP 47 language tag, such as en or af.')]);
        }
        $repository = $data['scope'] === 'repository' ? Repository::where('institution_id', $user->institution_id)->whereNull('deleted_at')->findOrFail($data['repository_id']) : null;
        $unit = $data['scope'] === 'unit' ? RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')->findOrFail($data['record_resource_id']) : null;
        $items = $data['scope'] === 'clipboard' ? app(ClipboardService::class)->items($user)['records'] : null;
        if ($items === []) {
            return back()->withInput()->withErrors(['scope' => __('The clipboard holds no descriptions.')]);
        }
        $concept = fn (string $iri) => Concept::where('iri', $iri)->value('id');

        $catalogue = DB::transaction(fn () => AuditLog::actingAs($user, function () use ($request, $user, $data, $repository, $unit, $items, $language, $concept) {
            $catalogue = OfflineCatalogue::create(['institution_id' => $user->institution_id, 'user_id' => $user->id, 'title' => $data['title'],
                'mode_id' => $concept(Builder::MODES.$data['mode']), 'status_id' => $concept(Builder::STATUS.'pending'),
                'repository_id' => $repository?->id, 'record_resource_id' => $unit?->id, 'scope_items' => $items, 'language' => $language,
                'include_files' => $request->boolean('include_files', true),
                // Only an archive carries masters, and only for an account that may download them anywhere.
                'include_masters' => $data['mode'] === 'archive' && ($data['include_masters'] ?? false) && $user->mayAnywhere('read-master', 'description')]);
            $job = app(JobsService::class)->create($user->institution_id, 'offline-catalogue', __('Offline catalogue :title', ['title' => $data['title']]), $user, $catalogue);
            $catalogue->update(['background_job_id' => $job->id]);
            BuildOfflineCatalogue::dispatch($job->id, $catalogue->id);

            return $catalogue;
        }));

        return redirect()->route('offline-catalogues.index')->with('status', __('The offline catalogue ":title" is being made. This page shows when it is ready.', ['title' => $catalogue->title]));
    }

    public function download(Request $request, OfflineCatalogue $catalogue): BinaryFileResponse
    {
        $this->own($request, $catalogue);

        return $this->send($catalogue);
    }

    public function destroy(Request $request, OfflineCatalogue $catalogue): RedirectResponse
    {
        $this->own($request, $catalogue);
        abort_if($catalogue->status?->notation === 'running', 409, __('It is being made; delete it once it is done.'));
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $catalogue->delete()));
        $this->store->ocfl()->exists($catalogue->objectId()) && $this->store->ocfl()->purge($catalogue->objectId());

        return redirect()->route('offline-catalogues.index')->with('status', __('Deleted.'));
    }

    /** A link for someone without an account; never to an archive, which may hold drafts and masters. */
    public function share(Request $request, OfflineCatalogue $catalogue): RedirectResponse
    {
        $this->own($request, $catalogue);
        abort_if($catalogue->mode->notation === 'archive', 403, __('An archive is for the institution\'s staff; it is never given a link.'));
        abort_unless($catalogue->status->notation === 'completed', 409);
        $data = $request->validate(['label' => ['nullable', 'string', 'max:255'], 'expires_at' => ['nullable', 'date', 'after:today'], 'max_downloads' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $token = Str::random(48);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => OfflineCatalogueShare::create(['offline_catalogue_id' => $catalogue->id,
            'token_hash' => hash('sha256', $token), 'label' => $data['label'] ?? null, 'max_downloads' => $data['max_downloads'] ?? null,
            'expires_at' => isset($data['expires_at']) ? $data['expires_at'].' 23:59:59' : null, 'created_by_id' => $request->user()->id])));

        // The token is shown once: only its hash is kept.
        return redirect()->route('offline-catalogues.index')->with('link', route('offline-catalogues.shared', $token));
    }

    public function revoke(Request $request, OfflineCatalogue $catalogue, OfflineCatalogueShare $share): RedirectResponse
    {
        $this->own($request, $catalogue);
        abort_unless($share->offline_catalogue_id === $catalogue->id, 404);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $share->update(['revoked_at' => now()])));

        return redirect()->route('offline-catalogues.index')->with('status', __('The link no longer opens.'));
    }

    /** The package, for whoever holds a link that still opens. */
    public function shared(string $token): BinaryFileResponse
    {
        $share = OfflineCatalogueShare::where('token_hash', hash('sha256', $token))->with('catalogue.mode', 'catalogue.status')->first();
        abort_unless($share !== null && $share->catalogue !== null, 404);
        abort_unless($share->open() && $share->catalogue->mode->notation !== 'archive', 410);
        // Counted before it is sent, in one statement, so a limit cannot be passed by asking at once.
        $counted = OfflineCatalogueShare::whereKey($share->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('max_downloads')->orWhereColumn('download_count', '<', 'max_downloads'))
            ->update(['download_count' => DB::raw('download_count + 1'), 'last_downloaded_at' => now()]);
        abort_unless($counted === 1, 410);

        return $this->send($share->catalogue);
    }

    private function send(OfflineCatalogue $catalogue): BinaryFileResponse
    {
        abort_unless($catalogue->status?->notation === 'completed' && $catalogue->storage_path, 404);
        $path = $this->store->path($catalogue->storage_path);
        abort_unless(is_file($path), 404);
        $name = trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $catalogue->title), '-') ?: 'offline-catalogue';

        return response()->download($path, $name.'.zip', ['Content-Type' => 'application/zip', 'X-Content-SHA256' => (string) $catalogue->sha256, 'Cache-Control' => 'private, no-store']);
    }

    /** Another institution's package does not exist here (ADR 0005). */
    private function own(Request $request, OfflineCatalogue $catalogue): void
    {
        abort_unless($catalogue->institution_id === $request->user()->institution_id, 404);
        $catalogue->loadMissing('mode', 'status');
    }
}
