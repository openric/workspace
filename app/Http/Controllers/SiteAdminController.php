<?php

// OpenRiC Workspace - editing the institution's public site (roadmap phase 20a), after Heratio's StaticPageController,
// BlogAdminController and LandingPageController: static pages in each language, articles with their attachments, links
// and comments, pictures for them, and landing pages built of blocks; and each user's own dashboards, built the same way.
// Who may publish edits the site; a dashboard only its owner.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Models\Article;
use App\Models\ArticleAttachment;
use App\Models\ArticleComment;
use App\Models\Concept;
use App\Models\LandingPage;
use App\Models\LandingPageBlock;
use App\Models\StaticPage;
use App\Site\Articles;
use App\Site\LandingPages;
use App\Site\SiteFiles;
use App\Site\StaticPages;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class SiteAdminController extends Controller
{
    // Static pages.

    public function pages(Request $request): View
    {
        return view('site.admin.pages', ['pages' => StaticPage::where('institution_id', $request->user()->institution_id)->with('translations')->orderBy('slug')->get(),
            'named' => StaticPages::NAMED]);
    }

    public function pageForm(Request $request, ?StaticPage $page = null): View
    {
        $page && $this->own($request, $page->institution_id);
        $language = $this->language($request);

        return view('site.admin.page-form', ['page' => $page, 'language' => $language, 'text' => $page?->translations->firstWhere('language', $language),
            'slug' => $page?->slug ?? (in_array($request->query('slug'), StaticPages::NAMED, true) ? $request->query('slug') : '')]);
    }

    public function savePage(Request $request, ?StaticPage $page = null): RedirectResponse
    {
        $page && $this->own($request, $page->institution_id);
        $data = $request->validate(['slug' => ['required', 'string', 'max:255'], 'title' => ['required', 'string', 'max:1024'], 'content' => ['nullable', 'string', 'max:200000']]);
        try {
            $page = app(StaticPages::class)->save($request->user()->institution, $data, $this->language($request), $request->user(), $page);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['slug' => $e->getMessage()]);
        }

        return redirect()->route('site.pages.edit', [$page, 'language' => $this->language($request)])->with('status', __('Saved.'));
    }

    public function deletePage(Request $request, StaticPage $page): RedirectResponse
    {
        $this->own($request, $page->institution_id);
        app(StaticPages::class)->delete($page, $request->user());

        return redirect()->route('site.pages')->with('status', __('Deleted.'));
    }

    // Articles.

    public function articles(Request $request): View
    {
        $institution = $request->user()->institution;

        return view('site.admin.articles', ['articles' => Article::where('institution_id', $institution->id)->with('status.labels')->withCount('attachments', 'comments')->orderByDesc('created_at')->get(),
            'pending' => ArticleComment::whereHas('article', fn ($q) => $q->where('institution_id', $institution->id))->where('status_id', app(Articles::class)->concept('article-comment-status', 'pending'))->count()]);
    }

    public function articleForm(Request $request, ?Article $article = null): View
    {
        $article && $this->own($request, $article->institution_id);
        $articles = app(Articles::class);

        return view('site.admin.article-form', ['article' => $article?->load('status', 'cover'), 'groups' => $articles->groups($request->user()->institution),
            'kinds' => Concept::choices(Vocab::BASE.'article-attachment-kind/')->get(), 'attachments' => $article ? $articles->attachments($article) : collect(),
            'related' => $article ? $articles->related($article) : collect(),
            'others' => Article::where('institution_id', $request->user()->institution_id)->when($article, fn ($q) => $q->whereKeyNot($article->id))->orderBy('title')->limit(1000)->get(['id', 'title', 'slug'])]);
    }

    public function saveArticle(Request $request, ?Article $article = null): RedirectResponse
    {
        $article && $this->own($request, $article->institution_id);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'slug' => ['nullable', 'string', 'max:200'], 'article_group' => ['nullable', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:150'], 'excerpt' => ['nullable', 'string', 'max:500'], 'body' => ['nullable', 'string', 'max:500000'],
            'attachments_label' => ['nullable', 'string', 'max:255'], 'status' => ['required', Rule::in(['draft', 'published'])], 'published_at' => ['nullable', 'date'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120']]);
        try {
            $article = app(Articles::class)->save($request->user()->institution, $data, $request->user(), $article, $request->file('cover'));
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route('site.articles.edit', $article)->with('status', __('Saved.'));
    }

    public function deleteArticle(Request $request, Article $article): RedirectResponse
    {
        $this->own($request, $article->institution_id);
        app(Articles::class)->delete($article, $request->user());

        return redirect()->route('site.articles')->with('status', __('Deleted.'));
    }

    public function addAttachment(Request $request, Article $article): RedirectResponse
    {
        $this->own($request, $article->institution_id);
        $data = $request->validate(['file' => ['required', 'file'], 'kind' => ['required', 'string'], 'title' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:500'],
            'group_label' => ['nullable', 'string', 'max:150'], 'sort_order' => ['nullable', 'integer']]);
        try {
            app(Articles::class)->addAttachment($article, $request->file('file'), $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('site.articles.edit', $article)->with('status', __('Attachment added.'));
    }

    public function updateAttachment(Request $request, Article $article, ArticleAttachment $attachment): RedirectResponse
    {
        $this->own($request, $article->institution_id);
        abort_unless($attachment->article_id === $article->id, 404);
        $data = $request->validate(['file' => ['nullable', 'file'], 'kind' => ['required', 'string'], 'title' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:500'],
            'group_label' => ['nullable', 'string', 'max:150'], 'sort_order' => ['nullable', 'integer']]);
        try {
            app(Articles::class)->updateAttachment($attachment, $data, $request->file('file'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('site.articles.edit', $article)->with('status', __('Saved.'));
    }

    public function reorderAttachments(Request $request, Article $article): RedirectResponse
    {
        $this->own($request, $article->institution_id);
        app(Articles::class)->reorderAttachments($article, $this->ids($request), $request->user());

        return redirect()->route('site.articles.edit', $article)->with('status', __('File order saved.'));
    }

    public function deleteAttachment(Request $request, Article $article, ArticleAttachment $attachment): RedirectResponse
    {
        $this->own($request, $article->institution_id);
        abort_unless($attachment->article_id === $article->id, 404);
        app(Articles::class)->deleteAttachment($attachment, $request->user());

        return redirect()->route('site.articles.edit', $article)->with('status', __('Attachment removed.'));
    }

    public function link(Request $request, Article $article): RedirectResponse
    {
        $this->own($request, $article->institution_id);
        $data = $request->validate(['target' => ['required', 'uuid'], 'description' => ['nullable', 'string', 'max:500']]);
        $target = Article::where(['id' => $data['target'], 'institution_id' => $article->institution_id])->firstOrFail();
        try {
            app(Articles::class)->link($article, $target, $data['description'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['target' => $e->getMessage()]);
        }

        return redirect()->route('site.articles.edit', $article)->with('status', __('Linked.'));
    }

    public function reorderLinks(Request $request, Article $article): RedirectResponse
    {
        $this->own($request, $article->institution_id);
        app(Articles::class)->reorderLinks($article, $this->ids($request), $request->user());

        return redirect()->route('site.articles.edit', $article)->with('status', __('Order saved.'));
    }

    public function unlink(Request $request, Article $article, Article $target): RedirectResponse
    {
        $this->own($request, $article->institution_id);
        app(Articles::class)->unlink($article, $target->id, $request->user());

        return redirect()->route('site.articles.edit', $article)->with('status', __('Link removed.'));
    }

    /** Heratio's uploadImage: a picture for an article's text or a landing page, its address returned to put in. */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'], 'article' => ['nullable', 'uuid']]);
        $article = $request->input('article') ? Article::where(['id' => $request->input('article'), 'institution_id' => $request->user()->institution_id])->firstOrFail() : null;
        try {
            $file = app(SiteFiles::class)->store($request->user()->institution, $request->file('image'), SiteFiles::IMAGES, $article, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['url' => route('site.file', $file)]);
    }

    public function comments(Request $request): View
    {
        return view('site.admin.comments', ['comments' => ArticleComment::whereHas('article', fn ($q) => $q->where('institution_id', $request->user()->institution_id))
            ->with('article', 'status.labels')->orderByDesc('created_at')->limit(200)->get(), 'statuses' => Concept::choices(Vocab::BASE.'article-comment-status/')->get()]);
    }

    public function commentStatus(Request $request, ArticleComment $comment): RedirectResponse
    {
        $this->own($request, $comment->article->institution_id);
        $request->validate(['status' => ['required', Rule::in(['approved', 'pending', 'spam'])]]);
        app(Articles::class)->setCommentStatus($comment, $request->input('status'), $request->user());

        return back()->with('status', __('Comment updated.'));
    }

    public function deleteComment(Request $request, ArticleComment $comment): RedirectResponse
    {
        $this->own($request, $comment->article->institution_id);
        AuditLog::actingAs($request->user(), fn () => $comment->delete());

        return back()->with('status', __('Comment deleted.'));
    }

    // Landing pages and dashboards.

    public function landingPages(Request $request): View
    {
        return view('site.admin.landing-pages', ['pages' => LandingPage::where('institution_id', $request->user()->institution_id)->whereNull('owner_id')->withCount('blocks')->orderBy('name')->get(),
            'dashboard' => false]);
    }

    public function dashboards(Request $request): View
    {
        return view('site.admin.landing-pages', ['pages' => LandingPage::where('owner_id', $request->user()->id)->withCount('blocks')->orderBy('name')->get(), 'dashboard' => true]);
    }

    public function createLanding(Request $request): RedirectResponse
    {
        $dashboard = $request->routeIs('dashboards.store');
        $dashboard || abort_unless($this->publishes($request), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'slug' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'], 'starter' => ['nullable', 'boolean']]);
        $pages = app(LandingPages::class);
        try {
            $page = $pages->save($request->user()->institution, $data, $request->user(), null, $dashboard ? $request->user() : null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }
        ! empty($data['starter']) && $pages->seedStarter($page, $request->user());

        return redirect()->route('landing.edit', $page)->with('status', __('Created.'));
    }

    public function editLanding(Request $request, LandingPage $page): View
    {
        $this->mayBuild($request, $page);
        $pages = app(LandingPages::class);

        return view('site.admin.landing-edit', ['page' => $page, 'blocks' => $pages->blocks($page, false, new PublicCatalogue($request->user()->institution)), 'pages' => $pages,
            'types' => $pages->types(), 'repositories' => (new PublicCatalogue($request->user()->institution))->visible('repositories')]);
    }

    public function updateLanding(Request $request, LandingPage $page): RedirectResponse
    {
        $this->mayBuild($request, $page);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'slug' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean'], 'meta_title' => ['nullable', 'string', 'max:255'], 'meta_description' => ['nullable', 'string', 'max:2000'],
            'css_classes' => ['nullable', 'string', 'max:255'], 'custom_css' => ['nullable', 'string', 'max:50000']]);
        $data['is_active'] = $request->boolean('is_active');
        try {
            app(LandingPages::class)->save($request->user()->institution, $data, $request->user(), $page);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('landing.edit', $page)->with('status', __('Saved.'));
    }

    public function deleteLanding(Request $request, LandingPage $page): RedirectResponse
    {
        $this->mayBuild($request, $page);
        app(LandingPages::class)->delete($page, $request->user());

        return redirect()->route($page->owner_id ? 'dashboards' : 'site.landing')->with('status', __('Deleted.'));
    }

    public function showDashboard(Request $request, LandingPage $page): View
    {
        abort_unless($page->owner_id === $request->user()->id, 404);

        return app(SiteController::class)->showLanding($page, new PublicCatalogue($request->user()->institution));
    }

    public function addBlock(Request $request, LandingPage $page): RedirectResponse
    {
        $this->mayBuild($request, $page);
        $data = $request->validate(['type' => ['required', 'string'], 'parent' => ['nullable', 'uuid'], 'column' => ['nullable', 'integer', 'between:1,3']]);
        $parent = ($data['parent'] ?? null) ? LandingPageBlock::where(['id' => $data['parent'], 'landing_page_id' => $page->id])->firstOrFail() : null;
        try {
            $block = app(LandingPages::class)->addBlock($page, $data['type'], [], $request->user(), $parent, $data['column'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()->to(route('landing.edit', $page).'#block-'.$block->id)->with('status', __('Block added.'));
    }

    public function updateBlock(Request $request, LandingPageBlock $block): RedirectResponse
    {
        $page = $block->page;
        $this->mayBuild($request, $page);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:255'], 'config' => ['nullable', 'array'], 'css_classes' => ['nullable', 'string', 'max:255'],
            'background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'padding_top' => ['nullable', 'numeric', 'between:0,6'],
            'padding_bottom' => ['nullable', 'numeric', 'between:0,6'], 'col_span' => ['nullable', 'integer', 'between:1,12']]);
        $data['full_width'] = $request->boolean('full_width');
        $data['is_visible'] = $request->boolean('is_visible');
        app(LandingPages::class)->updateBlock($block, $data, $request->user());

        return redirect()->to(route('landing.edit', $page).'#block-'.$block->id)->with('status', __('Saved.'));
    }

    public function deleteBlock(Request $request, LandingPageBlock $block): RedirectResponse
    {
        $page = $block->page;
        $this->mayBuild($request, $page);
        app(LandingPages::class)->deleteBlock($block, $request->user());

        return redirect()->route('landing.edit', $page)->with('status', __('Block removed.'));
    }

    public function reorderBlocks(Request $request, LandingPage $page): RedirectResponse
    {
        $this->mayBuild($request, $page);
        app(LandingPages::class)->reorder($page, $this->ids($request), $request->user());

        return redirect()->route('landing.edit', $page)->with('status', __('Order saved.'));
    }

    public function duplicateBlock(Request $request, LandingPageBlock $block): RedirectResponse
    {
        $page = $block->page;
        $this->mayBuild($request, $page);
        $copy = app(LandingPages::class)->duplicate($block, $request->user());

        return redirect()->to(route('landing.edit', $page).'#block-'.$copy->id)->with('status', __('Block copied.'));
    }

    public function toggleBlock(Request $request, LandingPageBlock $block): RedirectResponse
    {
        $page = $block->page;
        $this->mayBuild($request, $page);
        app(LandingPages::class)->toggle($block, $request->user());

        return redirect()->to(route('landing.edit', $page).'#block-'.$block->id);
    }

    /** A dashboard is built by its owner; a public page by who may publish for its institution. */
    private function mayBuild(Request $request, LandingPage $page): void
    {
        $user = $request->user();
        $page->owner_id ? abort_unless($page->owner_id === $user->id, 404)
            : abort_unless($page->institution_id === $user->institution_id && $this->publishes($request), $page->institution_id === $user->institution_id ? 403 : 404);
    }

    private function publishes(Request $request): bool
    {
        return $request->user()->mayAnywhere('publish', 'description');
    }

    private function own(Request $request, string $institutionId): void
    {
        abort_unless($institutionId === $request->user()->institution_id, 404);
    }

    private function language(Request $request): string
    {
        $language = (string) $request->query('language', $request->input('language', app()->getLocale()));

        return preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language) ? $language : 'en';
    }

    /** The ids in the order given, as a list or comma-separated. */
    private function ids(Request $request): array
    {
        $order = $request->input('order', []);

        return array_values(array_filter(array_map('trim', is_string($order) ? explode(',', $order) : (array) $order), fn ($v) => preg_match('/^[0-9a-f-]{36}$/', $v)));
    }
}
