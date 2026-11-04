<?php

// OpenRiC Workspace - the Help Center (roadmap phase 20b), after Heratio's ahg-help HelpController and its Help Center at
// heratio.org/help: the categories and the guides in each, a guide with its contents, related guides and the guides
// before and after it, a search of the guides and their sections, help for the screen in hand, the system map (how a
// record moves through the Workspace) and the system breakdown (what it does, by kind of entity), and linking guides
// by hand. Guides for staff are shown only to who is signed in; the rest are the public's too.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Help\HelpLibrary;
use App\Help\SystemMaps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function __construct(private HelpLibrary $library)
    {
        // Each page reads the library in again first if its files have changed.
        $library->ensureCurrent();
    }

    public function index(Request $request): View
    {
        $staff = $this->staff($request);

        return $this->view($request, 'help.index', ['categories' => $this->library->categories($staff), 'recent' => $this->library->recent($staff),
            'total' => DB::table('help_articles')->when(! $staff, fn ($q) => $q->where('staff_only', false))->count()]);
    }

    public function category(Request $request, string $category): View
    {
        $name = collect($this->library->categories($this->staff($request)))->pluck('category')->first(fn ($c) => SystemMaps::slug($c) === $category) ?? abort(404);

        return $this->view($request, 'help.category', ['category' => $name, 'articles' => $this->library->inCategory($name, $this->staff($request))]);
    }

    public function article(Request $request, string $slug): View
    {
        $staff = $this->staff($request);
        $article = $this->library->find($slug, $staff) ?? abort(404);

        return $this->view($request, 'help.article', ['article' => $article, 'toc' => json_decode($article->toc, true), 'related' => $this->library->related($article->id, $staff),
            'adjacent' => $this->library->adjacent($article, $staff)]);
    }

    public function search(Request $request): View
    {
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 200);

        return $this->view($request, 'help.search', ['query' => $query, 'results' => $this->library->search($query, $this->staff($request))]);
    }

    /** Help for the screen in hand: the guide for its route, else the Help Center (Heratio's /help/context). */
    public function context(Request $request): RedirectResponse
    {
        $article = $this->library->forRoute((string) $request->query('route', ''), $this->staff($request));

        return redirect()->route($article ? 'help.article' : 'help.index', $article ? $article->slug : []);
    }

    public function systemMap(Request $request): View
    {
        return $this->view($request, 'help.system-map', ['map' => app(SystemMaps::class)->map($this->staff($request))]);
    }

    public function systemBreakdown(Request $request): View
    {
        return $this->view($request, 'help.system-breakdown', ['tree' => app(SystemMaps::class)->breakdown($this->staff($request))]);
    }

    public function links(Request $request, string $slug): View
    {
        $article = $this->library->find($slug, true) ?? abort(404);

        return view('help.links', ['article' => $article, 'related' => $this->library->related($article->id, true),
            'others' => DB::table('help_articles')->where('id', '<>', $article->id)->orderBy('category')->orderBy('title')->get(['id', 'title', 'category'])]);
    }

    public function addLink(Request $request, string $slug): RedirectResponse
    {
        $article = $this->library->find($slug, true) ?? abort(404);
        $target = DB::table('help_articles')->where('id', $request->validate(['target' => ['required', 'uuid']])['target'])->first() ?? abort(404);
        $this->library->link($article->id, $target->id);

        return back()->with('status', __('Linked.'));
    }

    public function removeLink(Request $request, string $slug, string $target): RedirectResponse
    {
        $article = $this->library->find($slug, true) ?? abort(404);
        $this->library->unlink($article->id, $target);

        return back()->with('status', __('Link removed.'));
    }

    private function staff(Request $request): bool
    {
        return $request->user() !== null;
    }

    /** In the staff layout for who is signed in, else in the public catalogue's. */
    private function view(Request $request, string $name, array $data): View
    {
        return view($name, $data + ['layout' => $request->user() ? 'layouts.app' : 'layouts.public', 'categories' => $data['categories'] ?? $this->library->categories($this->staff($request))]);
    }
}
