<?php

// OpenRiC Workspace - the institution's own pages on its public site (roadmap phase 20a), after Heratio's
// StaticPageController, BlogController and LandingPageController: its static pages, its articles with their attachments,
// related articles and comments, the files they show, and its landing pages, the default one the catalogue's home.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Models\SiteFile;
use App\Site\Articles;
use App\Site\LandingPages;
use App\Site\SiteFiles;
use App\Site\StaticPages;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class SiteController extends Controller
{
    public function page(Request $request, string $slug): View
    {
        $page = app(StaticPages::class)->find($request->attributes->get('institution'), $slug) ?? abort(404);
        $text = $page->translation(Vocab::languages()) ?? abort(404);

        return view('site.page', ['page' => $page, 'text' => $text, 'html' => self::markdown($text->content)]);
    }

    /** Heratio's articles index: nine to a page, filtered by group, sorted, as tiles or a list. */
    public function articles(Request $request): View
    {
        $institution = $request->attributes->get('institution');
        $articles = app(Articles::class);
        $groups = $articles->publishedGroups($institution);
        $group = in_array($request->query('group'), $groups, true) ? $request->query('group') : null;
        $sort = in_array($request->query('sort'), Articles::SORTS, true) ? $request->query('sort') : 'latest';

        return view('site.articles', ['articles' => $articles->paginate($institution, $group, $sort), 'groups' => $groups, 'group' => $group, 'sort' => $sort,
            'display' => $request->query('view') === 'list' ? 'list' : 'tile']);
    }

    public function article(Request $request, string $slug): View
    {
        $articles = app(Articles::class);
        $article = $articles->findPublished($request->attributes->get('institution'), $slug) ?? abort(404);

        return view('site.article', ['article' => $article, 'html' => self::markdown($article->body), 'attachments' => $articles->attachments($article),
            'related' => $articles->related($article, true), 'comments' => $articles->approvedComments($article)]);
    }

    /**
     * Heratio's read count: called by the article page once it has been seen for a few seconds, with its session, so a
     * crawler that runs no script is never counted; one read a session, and none by who edits the site.
     */
    public function read(Request $request, string $slug): Response
    {
        $article = app(Articles::class)->findPublished($request->attributes->get('institution'), $slug);
        if (! $article || ! $request->ajax()) {
            return response()->noContent(404);
        }
        $key = 'article_viewed_'.$article->id;
        if (! $request->user()?->mayAnywhere('publish', 'description') && ! $request->session()->has($key)) {
            app(Articles::class)->incrementViews($article);
            $request->session()->put($key, true);
        }

        return response()->noContent();
    }

    /** A visitor's comment, with Heratio's guards: a field only robots fill, a pause between comments, and a length. */
    public function comment(Request $request, string $slug): RedirectResponse
    {
        $articles = app(Articles::class);
        $article = $articles->findPublished($request->attributes->get('institution'), $slug) ?? abort(404);
        if (trim((string) $request->input('website', '')) !== '') {
            return redirect()->route('site.article', $slug);
        }
        $data = $request->validate(['author_name' => ['nullable', 'string', 'max:150'], 'body' => ['required', 'string', 'min:2', 'max:4000']]);
        try {
            $articles->comment($article, $data, $request->ip(), (string) $request->userAgent());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('comment_error', $e->getMessage());
        }

        return redirect()->to(route('site.article', $slug).'#comments')->with('comment_success', __('Thanks - your comment has been posted.'));
    }

    public function file(Request $request, SiteFile $file): BinaryFileResponse
    {
        $files = app(SiteFiles::class);
        abort_unless($files->servable($file, $request->attributes->get('institution'), $request->user()), 404);
        $inline = str_starts_with($file->mime_type, 'image/') || str_starts_with($file->mime_type, 'video/') || $file->mime_type === 'application/pdf';

        return response()->file($files->path($file), ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; media-src 'self'",
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes(Str::ascii($file->file_name), '"\\').'"', 'Cache-Control' => $file->article_id ? 'private, max-age=300' : 'public, max-age=86400']);
    }

    public function landing(Request $request, ?string $slug = null): View
    {
        $institution = $request->attributes->get('institution');
        $page = app(LandingPages::class)->public($institution, $slug) ?? abort(404);

        return $this->showLanding($page, $request->attributes->get('catalogue') ?? new PublicCatalogue($institution));
    }

    public function showLanding($page, PublicCatalogue $catalogue): View
    {
        $pages = app(LandingPages::class);

        return view('site.landing', ['page' => $page, 'blocks' => $pages->blocks($page, true, $catalogue), 'pages' => $pages, 'institution' => $catalogue->institution]);
    }

    /** Markdown with any HTML in it stripped and no unsafe links, as Heratio shows an article. */
    public static function markdown(?string $text): string
    {
        return $text ? Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]) : '';
    }
}
