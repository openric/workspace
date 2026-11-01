<?php

// OpenRiC Workspace - favourites (roadmap phase 19a, ADR 0017), after Heratio's FavoritesController (ahg-favorites):
// browse, keep and forget, notes, folders, move, clear, share a folder by a link, export and import. Any signed-in
// account keeps its own; a shared folder opens to anyone holding its link.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Favourite;
use App\Models\FavouriteFolder;
use App\Models\Institution;
use App\Relations\EntityKinds;
use App\Research\Favourites;
use App\Research\FavouritesExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class FavouritesController extends Controller
{
    public function __construct(private Favourites $favourites) {}

    public function index(Request $request): View
    {
        $folder = (string) $request->query('folder', '');
        try {
            $items = $this->favourites->browse($request->user(), $folder, $request->query('q'), (string) $request->query('sort', 'added'));
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return view('favourites.index', ['items' => $items, 'folders' => $this->favourites->folders($request->user()), 'folder' => $folder,
            'current' => $folder !== '' && $folder !== 'unfiled' ? FavouriteFolder::find($folder) : null,
            'unfiled' => Favourite::where('user_id', $request->user()->id)->whereNull('folder_id')->count(),
            'total' => Favourite::where('user_id', $request->user()->id)->count()]);
    }

    /** Keep an entity, or forget it: from any page that shows it. */
    public function toggle(Request $request, string $kind, string $id): RedirectResponse
    {
        $definition = EntityKinds::get(EntityKinds::bySegment($kind) ?? '') ?? abort(404);
        $entity = $definition['class']::where('institution_id', $request->user()->institution_id)->findOrFail($id);
        try {
            $kept = $this->favourites->toggle($request->user(), $entity, $request->input('folder_id'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['favourites' => $e->getMessage()]);
        }

        return back()->with('status', $kept ? __('Added to your favourites.') : __('Removed from your favourites.'));
    }

    public function addLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048'], 'title' => ['required', 'string', 'max:500'], 'folder_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->favourites->addLink($request->user(), $data['url'], $data['title'], $data['folder_id'] ?? null), __('Added to your favourites.'));
    }

    public function notes(Request $request, Favourite $favourite): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => $this->favourites->notes($request->user(), $favourite, $data['notes'] ?? null), __('Saved.'));
    }

    /** Remove or move the chosen favourites (Heratio's bulk actions). */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid'], 'action' => ['required', 'in:remove,move'], 'folder_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $data['action'] === 'remove' ? $this->favourites->remove($request->user(), $data['ids'])
            : $this->favourites->move($request->user(), $data['ids'], $data['folder_id'] ?? null), $data['action'] === 'remove' ? __('Removed.') : __('Moved.'));
    }

    public function clear(Request $request): RedirectResponse
    {
        $this->favourites->clear($request->user());

        return redirect()->route('favourites.index')->with('status', __('Your favourites are cleared.'));
    }

    public function saveFolder(Request $request, ?FavouriteFolder $folder = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'parent_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->favourites->saveFolder($request->user(), $data, $folder), __('Saved.'));
    }

    public function deleteFolder(Request $request, FavouriteFolder $folder): RedirectResponse
    {
        $this->attempt(fn () => $this->favourites->deleteFolder($request->user(), $folder), '');

        return redirect()->route('favourites.index')->with('status', __('The folder is deleted; its favourites are unfiled.'));
    }

    public function share(Request $request, FavouriteFolder $folder): RedirectResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:'.Favourites::SHARE_DAYS_MAX], 'public' => ['nullable', 'boolean']]);
        try {
            $token = $this->favourites->share($request->user(), $folder, $request->boolean('public') ? null : (int) ($data['days'] ?? Favourites::SHARE_DAYS));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['favourites' => $e->getMessage()]);
        }

        return back()->with('status', __('Anyone with this link may see the folder; it is shown only now:'))->with('share_link', route('favourites.shared', $token));
    }

    public function unshare(Request $request, FavouriteFolder $folder): RedirectResponse
    {
        return $this->attempt(fn () => $this->favourites->unshare($request->user(), $folder), __('The folder is no longer shared.'));
    }

    /** A shared folder, for anyone holding its link. */
    public function shared(string $token): View
    {
        $folder = $this->favourites->shared($token) ?? abort(404);

        return view('favourites.shared', ['folder' => $folder, 'items' => $this->favourites->sharedItems($folder), 'institution' => Institution::find($folder->institution_id)]);
    }

    public function export(Request $request, string $format): Response
    {
        abort_unless(isset(FavouritesExport::FORMATS[$format]), 404);
        try {
            $folder = $this->favourites->folderOf($request->user(), $request->query('folder'));
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return app(FavouritesExport::class)->response($request->user(), $folder, $format);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate(['file' => ['nullable', 'file', 'max:2048'], 'iris' => ['nullable', 'string', 'max:200000'], 'folder_id' => ['nullable', 'uuid']]);
        $content = $request->file('file')?->get() ?? (string) ($data['iris'] ?? '');
        try {
            $added = $this->favourites->import($request->user(), $content, $data['folder_id'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['favourites' => $e->getMessage()]);
        }

        return back()->with('status', trans_choice('{0} No record was added.|{1} :count record was added.|[2,*] :count records were added.', $added, ['count' => $added]));
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['favourites' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
