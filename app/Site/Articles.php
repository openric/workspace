<?php

// OpenRiC Workspace - the institution's articles on its public site (roadmap phase 20a), after Heratio's ahg-articles
// BlogService and BlogLinkService: written in Markdown, in groups, a draft until published and stamped the first time it
// is; its attachments (guides, templates, reports and the rest, in sections and in the order chosen, a video played in
// the page); the articles it links to, each link read from both ends; and visitors' comments, shown at once as Heratio
// shows them, with a pause between comments from one address, and moderated as approved, pending or spam.
// Changed from Heratio: each institution has its own articles, its slugs unique within it; its files are kept in OCFL
// and served only while the article is published (SiteFiles); and who may edit them is who may publish.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Site;

use App\Audit\AuditLog;
use App\Models\Article;
use App\Models\ArticleAttachment;
use App\Models\ArticleComment;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\SiteFile;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Articles
{
    public const SORTS = ['latest', 'oldest', 'title', 'popular'];

    /** Heratio's flood guard: seconds between two comments from one address. */
    public const COMMENT_PAUSE_SECONDS = 20;

    public function __construct(private SiteFiles $files) {}

    /** The institution's articles the public may read: published, their publication date come. */
    public function published(Institution $institution): Builder
    {
        return Article::where('institution_id', $institution->id)->where('status_id', $this->concept('site-article-status', 'published'))
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /** Heratio's paginatePublished: nine to a page, in a group or all, newest, oldest, by title or most read. */
    public function paginate(Institution $institution, ?string $group, string $sort, int $perPage = 9): LengthAwarePaginator
    {
        $query = $this->published($institution)->withCount('attachments')->with('cover')->when($group, fn ($q) => $q->where('article_group', $group));
        match ($sort) {
            'oldest' => $query->orderByRaw('COALESCE(published_at, created_at) ASC'),
            'title' => $query->orderBy('title'),
            'popular' => $query->orderByDesc('view_count')->orderByRaw('COALESCE(published_at, created_at) DESC'),
            default => $query->orderByRaw('COALESCE(published_at, created_at) DESC'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /** @return list<string> the groups of the articles the public may read */
    public function publishedGroups(Institution $institution): array
    {
        return $this->published($institution)->whereNotNull('article_group')->where('article_group', '<>', '')->distinct()->orderBy('article_group')->pluck('article_group')->all();
    }

    /** @return list<string> every group in use, offered when an article is written */
    public function groups(Institution $institution): array
    {
        return Article::where('institution_id', $institution->id)->whereNotNull('article_group')->where('article_group', '<>', '')->distinct()->orderBy('article_group')->pluck('article_group')->all();
    }

    public function findPublished(Institution $institution, string $slug): ?Article
    {
        return $this->published($institution)->where('slug', $slug)->with('status')->first();
    }

    public function save(Institution $institution, array $data, User $actor, ?Article $article = null, ?UploadedFile $cover = null): Article
    {
        $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $fields = ['title' => trim((string) $data['title']), 'status_id' => $this->concept('site-article-status', $status)];
        foreach (['excerpt', 'body', 'attachments_label', 'author', 'article_group'] as $field) {
            $fields[$field] = trim((string) ($data[$field] ?? '')) ?: null;
        }
        $fields['title'] !== '' || throw new InvalidArgumentException(__('An article has a title.'));
        // The publication date is stamped the first time it goes public, unless one is given.
        $fields['published_at'] = ($data['published_at'] ?? null) ?: ($status === 'published' ? ($article?->published_at ?? now()) : $article?->published_at);
        $wanted = trim((string) ($data['slug'] ?? ''));
        if (! $article || $wanted !== '') {
            $fields['slug'] = $this->uniqueSlug($institution, $wanted !== '' ? $wanted : $fields['title'], $article?->id);
        }

        return AuditLog::actingAs($actor, function () use ($institution, $fields, $actor, $article, $cover) {
            return DB::transaction(function () use ($institution, $fields, $actor, $article, $cover) {
                $article ??= new Article(['institution_id' => $institution->id, 'created_by_id' => $actor->id]);
                $article->fill($fields)->save();
                if ($cover) {
                    $old = $article->cover;
                    $article->update(['cover_file_id' => $this->files->store($institution, $cover, SiteFiles::IMAGES, $article, $actor)->id]);
                    $old && $this->files->delete($old, $actor);
                }

                return $article->fresh(['status', 'cover']);
            });
        });
    }

    /** Remove an article with its files, comments and links. */
    public function delete(Article $article, User $actor): void
    {
        AuditLog::actingAs($actor, function () use ($article, $actor) {
            SiteFile::where('article_id', $article->id)->get()->each(fn ($file) => $this->files->delete($file, $actor));
            $article->delete();
        });
    }

    public function incrementViews(Article $article): void
    {
        // Counting a read is not an edit of the article, so it is not audited.
        DB::table('articles')->where('id', $article->id)->increment('view_count');
    }

    /**
     * Heratio's listAttachments order: files with no section first, then each section by name; within one, the order
     * chosen, then by kind and when it came.
     *
     * @return Collection<int, ArticleAttachment>
     */
    public function attachments(Article $article): Collection
    {
        return $article->attachments()->with('file', 'kind.labels')->get()
            ->sortBy(fn (ArticleAttachment $a) => [($a->group_label ?? '') !== '' ? 1 : 0, mb_strtolower((string) $a->group_label), $a->sort_order, $a->created_at?->getTimestamp()])->values();
    }

    public function addAttachment(Article $article, UploadedFile $upload, array $meta, User $actor): ArticleAttachment
    {
        $institution = Institution::findOrFail($article->institution_id);

        return AuditLog::actingAs($actor, function () use ($article, $upload, $meta, $actor, $institution) {
            $file = $this->files->store($institution, $upload, SiteFiles::ATTACHMENTS, $article, $actor);

            return ArticleAttachment::create(['article_id' => $article->id, 'site_file_id' => $file->id, 'kind_id' => $this->concept('article-attachment-kind', $meta['kind'] ?? 'guide'),
                'group_label' => trim((string) ($meta['group_label'] ?? '')) ?: null, 'title' => trim((string) ($meta['title'] ?? '')) ?: $file->file_name,
                'description' => trim((string) ($meta['description'] ?? '')) ?: null,
                'sort_order' => array_key_exists('sort_order', $meta) && $meta['sort_order'] !== null && $meta['sort_order'] !== '' ? (int) $meta['sort_order'] : 1 + (int) $article->attachments()->max('sort_order'),
                'created_by_id' => $actor->id]);
        });
    }

    /** Change an attachment's kind, section, title, description or place, and replace its file if one is given. */
    public function updateAttachment(ArticleAttachment $attachment, array $meta, ?UploadedFile $upload, User $actor): ArticleAttachment
    {
        return AuditLog::actingAs($actor, function () use ($attachment, $meta, $upload, $actor) {
            $fields = ['kind_id' => $this->concept('article-attachment-kind', $meta['kind'] ?? $attachment->kind->notation), 'group_label' => trim((string) ($meta['group_label'] ?? '')) ?: null,
                'description' => trim((string) ($meta['description'] ?? '')) ?: null, 'sort_order' => (int) ($meta['sort_order'] ?? $attachment->sort_order)];
            $old = null;
            if ($upload) {
                $article = Article::findOrFail($attachment->article_id);
                $old = $attachment->file;
                $fields['site_file_id'] = $this->files->store(Institution::findOrFail($article->institution_id), $upload, SiteFiles::ATTACHMENTS, $article, $actor)->id;
            }
            $attachment->update($fields);
            $old && $this->files->delete($old, $actor);
            $title = trim((string) ($meta['title'] ?? ''));
            $attachment->update(['title' => $title !== '' ? $title : $attachment->fresh('file')->file->file_name]);

            return $attachment->fresh(['file', 'kind']);
        });
    }

    /** Heratio's reorderAttachments: each in the order given; ids not the article's are passed over. */
    public function reorderAttachments(Article $article, array $ids, User $actor): void
    {
        $owned = $article->attachments()->pluck('id')->all();
        AuditLog::actingAs($actor, function () use ($ids, $owned) {
            $i = 0;
            foreach ($ids as $id) {
                if (in_array($id, $owned, true)) {
                    ArticleAttachment::whereKey($id)->first()->update(['sort_order' => $i++]);
                }
            }
        });
    }

    public function deleteAttachment(ArticleAttachment $attachment, User $actor): void
    {
        AuditLog::actingAs($actor, function () use ($attachment, $actor) {
            $file = $attachment->file;
            $attachment->delete();
            $file && $this->files->delete($file, $actor);
        });
    }

    /**
     * The articles linked to it, from either end, in the order chosen then by title (Heratio's BlogLinkService::related).
     *
     * @return Collection<int, array{article: Article, description: ?string, sort_order: int}>
     */
    public function related(Article $article, bool $publicOnly = false): Collection
    {
        $links = DB::table('article_links')->where('article_id', $article->id)->orWhere('related_article_id', $article->id)->get()
            ->mapWithKeys(fn ($l) => [($l->article_id === $article->id ? $l->related_article_id : $l->article_id) => $l]);
        if ($links->isEmpty()) {
            return collect();
        }
        $others = ($publicOnly ? $this->published(Institution::findOrFail($article->institution_id)) : Article::query())->whereIn('id', $links->keys())->get();

        return $others->map(fn (Article $other) => ['article' => $other, 'description' => $links[$other->id]->description, 'sort_order' => (int) $links[$other->id]->sort_order])
            ->sortBy(fn ($r) => [$r['sort_order'], mb_strtolower($r['article']->title)])->values();
    }

    public function link(Article $article, Article $target, ?string $description, User $actor): void
    {
        ($article->id !== $target->id && $article->institution_id === $target->institution_id) || throw new InvalidArgumentException(__('An article links to another of the institution\'s articles.'));
        $existing = DB::table('article_links')->where(fn ($q) => $q->where(['article_id' => $article->id, 'related_article_id' => $target->id]))
            ->orWhere(fn ($q) => $q->where(['article_id' => $target->id, 'related_article_id' => $article->id]))->first();
        $description = trim((string) $description) ?: null;
        AuditLog::actingAs($actor, function () use ($existing, $article, $target, $description) {
            if ($existing) {
                DB::table('article_links')->where('id', $existing->id)->update(['description' => $description]);

                return;
            }
            $max = (int) DB::table('article_links')->where('article_id', $article->id)->orWhere('related_article_id', $article->id)->max('sort_order');
            DB::table('article_links')->insert(['id' => (string) Str::uuid7(), 'article_id' => $article->id, 'related_article_id' => $target->id, 'description' => $description,
                'sort_order' => $max + 1, 'created_at' => now()]);
        });
    }

    public function reorderLinks(Article $article, array $targetIds, User $actor): void
    {
        AuditLog::actingAs($actor, function () use ($article, $targetIds) {
            foreach (array_values($targetIds) as $i => $target) {
                $this->linkBetween($article->id, (string) $target)->update(['sort_order' => $i]);
            }
        });
    }

    public function unlink(Article $article, string $targetId, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $this->linkBetween($article->id, $targetId)->delete());
    }

    /** A visitor's comment, shown at once as Heratio shows it; the name may be left out. */
    public function comment(Article $article, array $data, ?string $ip, ?string $userAgent): ArticleComment
    {
        $since = $ip ? ArticleComment::where('ip', $ip)->max('created_at') : null;
        if ($since && now()->diffInSeconds($since, true) < self::COMMENT_PAUSE_SECONDS) {
            throw new InvalidArgumentException(__('Please wait a few seconds before commenting again.'));
        }
        $name = trim((string) ($data['author_name'] ?? ''));

        return ArticleComment::create(['article_id' => $article->id, 'author_name' => $name !== '' ? Str::limit($name, 150, '') : null, 'body' => trim((string) $data['body']),
            'status_id' => $this->concept('article-comment-status', 'approved'), 'ip' => $ip, 'user_agent' => $userAgent ? Str::limit($userAgent, 255, '') : null]);
    }

    /** @return Collection<int, ArticleComment> */
    public function approvedComments(Article $article): Collection
    {
        return $article->comments()->where('status_id', $this->concept('article-comment-status', 'approved'))->orderBy('created_at')->get();
    }

    public function setCommentStatus(ArticleComment $comment, string $status, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $comment->update(['status_id' => $this->concept('article-comment-status', $status)]));
    }

    public function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }

    private function linkBetween(string $a, string $b)
    {
        return DB::table('article_links')->where(fn ($q) => $q->where(['article_id' => $a, 'related_article_id' => $b]))->orWhere(fn ($q) => $q->where(['article_id' => $b, 'related_article_id' => $a]));
    }

    /** Heratio's uniqueSlug, within the institution. */
    private function uniqueSlug(Institution $institution, string $source, ?string $ignore): string
    {
        $base = Str::slug($source) ?: 'article';
        $slug = $base;
        for ($i = 2; Article::where(['institution_id' => $institution->id, 'slug' => $slug])->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
