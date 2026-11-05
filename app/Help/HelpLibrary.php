<?php

// OpenRiC Workspace - the Help Center's library (roadmap phase 20b), after Heratio's ahg-help HelpArticleService and its
// ingest commands: every guide in docs/help, written in Markdown under a short header (its title, category, order,
// whether it is for staff, the screens it explains and the Heratio guide it was adapted from), read into an article with
// its headings as sections, both searched by meaning of their words (PostgreSQL full-text search, where Heratio uses
// MySQL's), and linked to the guides its text links to as well as to any linked by hand; the categories in their order;
// the guide that explains the screen in hand; and the guides before and after one in its category.
// Changed from Heratio: the library is read in again whenever its files change, rather than by a command run after a
// release; guides for staff are hidden from the public as Heratio's admin categories are, but by each guide's header.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Help;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use stdClass;

class HelpLibrary
{
    /** The categories in the order the Help Center shows them; a guide of another category comes after. */
    public const CATEGORIES = ['Getting started', 'Describing', 'Digital objects and viewers', 'Accessions and storage', 'Search and discovery', 'Import and export',
        'Rights and access', 'Preservation', 'Records management and workflow', 'Research and the reading room', 'Federation and open data', 'Public site',
        'AI assistance', 'Administration', 'Technical reference'];

    public function directory(): string
    {
        return rtrim((string) config('workspace.help_directory') ?: base_path('docs/help'), '/');
    }

    /** What the files are now: their names, sizes and times, as one hash. */
    public function fingerprint(): string
    {
        $files = glob($this->directory().'/*.md') ?: [];
        sort($files);

        return hash('sha256', implode("\n", array_map(fn ($f) => basename($f).' '.filesize($f).' '.filemtime($f), $files)));
    }

    /** Read the library in again if its files have changed since it was last read. */
    public function ensureCurrent(): void
    {
        $fingerprint = $this->fingerprint();
        if (DB::table('help_library_state')->where('id', 1)->value('fingerprint') !== $fingerprint) {
            $this->ingest();
        }
    }

    /**
     * Read every guide in, by its slug (its file name): new ones added, changed ones replaced, those whose file is gone
     * removed; then each guide's links from its text rebuilt, keeping those added by hand.
     *
     * @return array{articles: int, links: int}
     */
    public function ingest(): array
    {
        $files = glob($this->directory().'/*.md') ?: [];
        sort($files);

        return DB::transaction(function () use ($files) {
            $seen = [];
            foreach ($files as $path) {
                $slug = basename($path, '.md');
                $seen[] = $slug;
                $this->ingestFile($slug, $path);
            }
            DB::table('help_articles')->whereNotIn('slug', $seen ?: [''])->delete();
            $ids = DB::table('help_articles')->pluck('id', 'slug');
            DB::table('help_article_links')->where('manual', false)->delete();
            $links = 0;
            foreach (DB::table('help_articles')->get(['id', 'body_html']) as $article) {
                preg_match_all('~/help/article/([a-z0-9][a-z0-9-]*)~', $article->body_html, $m);
                foreach (array_unique($m[1]) as $target) {
                    if (isset($ids[$target]) && $ids[$target] !== $article->id) {
                        $links += DB::table('help_article_links')->insertOrIgnore(['id' => (string) Str::uuid7(), 'help_article_id' => $article->id,
                            'related_article_id' => $ids[$target], 'manual' => false, 'created_at' => now()]);
                    }
                }
            }
            DB::table('help_library_state')->upsert([['id' => 1, 'fingerprint' => $this->fingerprint(), 'articles' => count($seen), 'read_at' => now()]], ['id'], ['fingerprint', 'articles', 'read_at']);

            return ['articles' => count($seen), 'links' => $links];
        });
    }

    /** One guide's header and text: `key: value` lines between two lines of three hyphens, then the Markdown. */
    public static function parse(string $source): array
    {
        $meta = [];
        if (preg_match('/\A---\R(.*?)\R---\R/s', $source, $m)) {
            foreach (preg_split('/\R/', $m[1]) as $line) {
                if (preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $kv)) {
                    $meta[$kv[1]] = trim($kv[2], " \"'");
                }
            }
            $source = substr($source, strlen($m[0]));
        }

        return [$meta, ltrim($source)];
    }

    private function ingestFile(string $slug, string $path): void
    {
        [$meta, $markdown] = self::parse((string) file_get_contents($path));
        $title = $meta['title'] ?? (preg_match('/^#\s+(.+)$/m', $markdown, $h) ? trim($h[1]) : $slug);
        // The guides are the Workspace's own, but any HTML in them is still escaped, and no unsafe link is made.
        $html = $this->withAnchors((string) (new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown));
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
        $sections = self::sections($markdown);
        $fields = ['title' => $title, 'category' => $meta['category'] ?? 'Getting started', 'subcategory' => ($meta['subcategory'] ?? '') ?: null,
            'source_file' => 'docs/help/'.basename($path), 'heratio_source' => ($meta['heratio'] ?? '') ?: null, 'body_markdown' => $markdown, 'body_html' => $html, 'body_text' => $text,
            'toc' => json_encode(array_map(fn ($s) => ['level' => $s['level'], 'text' => $s['heading'], 'anchor' => $s['anchor']], $sections)),
            'word_count' => str_word_count($text), 'sort_order' => (int) ($meta['order'] ?? 100), 'staff_only' => filter_var($meta['staff'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'routes' => json_encode(array_values(array_filter(array_map('trim', explode(',', $meta['routes'] ?? ''))))), 'tags' => ($meta['tags'] ?? '') ?: null, 'updated_at' => now()];
        $id = DB::table('help_articles')->where('slug', $slug)->value('id');
        if ($id) {
            DB::table('help_articles')->where('id', $id)->update($fields);
        } else {
            DB::table('help_articles')->insert($fields + ['id' => $id = (string) Str::uuid7(), 'slug' => $slug, 'created_at' => now()]);
        }
        DB::table('help_sections')->where('help_article_id', $id)->delete();
        foreach ($sections as $i => $s) {
            DB::table('help_sections')->insert(['id' => (string) Str::uuid7(), 'help_article_id' => $id, 'heading' => $s['heading'], 'anchor' => $s['anchor'], 'level' => $s['level'],
                'body_text' => $s['text'] ?: null, 'sort_order' => $i]);
        }
    }

    /** Heratio's extractSections: each heading of level 2 to 4, with the text under it. */
    public static function sections(string $markdown): array
    {
        $out = [];
        $current = null;
        $fenced = false;
        foreach (preg_split('/\R/', $markdown) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $fenced = ! $fenced;
            }
            if (! $fenced && preg_match('/^(#{2,4})\s+(.+?)\s*#*$/', $line, $m)) {
                $current !== null && $out[] = $current;
                $current = ['level' => strlen($m[1]), 'heading' => trim($m[2]), 'anchor' => self::anchor($m[2]), 'text' => ''];

                continue;
            }
            if ($current !== null) {
                $current['text'] .= ' '.trim(strip_tags($line));
            }
        }
        $current !== null && $out[] = $current;

        return array_map(fn ($s) => [...$s, 'text' => Str::limit(trim((string) preg_replace('/\s+/', ' ', $s['text'])), 4000, '')], $out);
    }

    public static function anchor(string $heading): string
    {
        return Str::slug(strip_tags($heading)) ?: 'section';
    }

    /** Heratio's addHeadingIds: each heading of level 2 to 4 given the anchor its section has. */
    private function withAnchors(string $html): string
    {
        return (string) preg_replace_callback('~<h([2-4])>(.*?)</h\1>~s', fn ($m) => '<h'.$m[1].' id="'.self::anchor(html_entity_decode($m[2])).'">'.$m[2].'</h'.$m[1].'>', $html);
    }

    /** @return Collection<int, stdClass> */
    public function categories(bool $staff): Collection
    {
        $rows = DB::table('help_articles')->when(! $staff, fn ($q) => $q->where('staff_only', false))
            ->selectRaw('category, count(*) as articles')->groupBy('category')->get();

        return $rows->sortBy(fn ($r) => [array_search($r->category, self::CATEGORIES, true) === false ? 99 : array_search($r->category, self::CATEGORIES, true), $r->category])->values();
    }

    /** @return Collection<int, stdClass> */
    public function inCategory(string $category, bool $staff): Collection
    {
        return DB::table('help_articles')->where('category', $category)->when(! $staff, fn ($q) => $q->where('staff_only', false))
            ->orderBy('subcategory')->orderBy('sort_order')->orderBy('title')->get(['slug', 'title', 'subcategory', 'word_count', 'updated_at']);
    }

    public function find(string $slug, bool $staff): ?stdClass
    {
        return DB::table('help_articles')->where('slug', $slug)->when(! $staff, fn ($q) => $q->where('staff_only', false))->first();
    }

    /** Guides whose title or text match, best first, and the sections of any guide that match. */
    public function search(string $query, bool $staff, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['articles' => collect(), 'sections' => collect()];
        }
        $ts = "websearch_to_tsquery('english', ?)";
        $articles = DB::table('help_articles')->whereRaw("search @@ $ts", [$query])->when(! $staff, fn ($q) => $q->where('staff_only', false))
            ->selectRaw("slug, title, category, ts_rank(search, $ts) as rank, ts_headline('english', body_text, $ts, 'MaxWords=35, MinWords=15') as snippet", [$query, $query])
            ->orderByDesc('rank')->limit($limit)->get();
        $sections = DB::table('help_sections as s')->join('help_articles as a', 'a.id', '=', 's.help_article_id')->whereRaw("s.search @@ $ts", [$query])
            ->when(! $staff, fn ($q) => $q->where('a.staff_only', false))
            ->selectRaw("a.slug, a.title as article_title, s.heading, s.anchor, ts_rank(s.search, $ts) as rank", [$query])->orderByDesc('rank')->limit(30)->get();

        return ['articles' => $articles, 'sections' => $sections];
    }

    /** The guides linked to it from either end, by its text or by hand. */
    public function related(string $id, bool $staff): Collection
    {
        $ids = DB::table('help_article_links')->where('help_article_id', $id)->pluck('related_article_id')
            ->merge(DB::table('help_article_links')->where('related_article_id', $id)->pluck('help_article_id'))->unique();

        return DB::table('help_articles')->whereIn('id', $ids->all())->when(! $staff, fn ($q) => $q->where('staff_only', false))->orderBy('title')->get(['id', 'slug', 'title', 'category']);
    }

    /** Heratio's getAdjacentArticles: the guide before and after it in its category. */
    public function adjacent(stdClass $article, bool $staff): array
    {
        $list = $this->inCategory($article->category, $staff)->values();
        $i = $list->search(fn ($a) => $a->slug === $article->slug);

        return ['prev' => $i > 0 ? $list[$i - 1] : null, 'next' => $i !== false && $i < $list->count() - 1 ? $list[$i + 1] : null];
    }

    public function recent(bool $staff, int $limit = 5): Collection
    {
        return DB::table('help_articles')->when(! $staff, fn ($q) => $q->where('staff_only', false))->orderByDesc('updated_at')->orderBy('title')->limit($limit)->get(['slug', 'title', 'category']);
    }

    /**
     * The guide that explains a screen (Heratio's contextualFor): the guide that names its route exactly, else the one
     * with the longest pattern that matches it, such as records.* for records.show.
     */
    public function forRoute(?string $route, bool $staff): ?stdClass
    {
        if (! $route) {
            return null;
        }
        $best = null;
        $length = -1;
        foreach (DB::table('help_articles')->when(! $staff, fn ($q) => $q->where('staff_only', false))->where('routes', '<>', '[]')->get(['slug', 'title', 'routes']) as $article) {
            foreach ((array) json_decode($article->routes, true) as $pattern) {
                $score = $pattern === $route ? 1000 : (Str::is($pattern, $route) ? strlen($pattern) : -1);
                if ($score > $length) {
                    [$best, $length] = [$article, $score];
                }
            }
        }

        return $best;
    }

    /** Link two guides by hand; it is kept when the library is read in again. */
    public function link(string $from, string $to): void
    {
        $from === $to || DB::table('help_article_links')->upsert([['id' => (string) Str::uuid7(), 'help_article_id' => $from, 'related_article_id' => $to, 'manual' => true, 'created_at' => now()]],
            ['help_article_id', 'related_article_id'], ['manual']);
    }

    public function unlink(string $from, string $to): void
    {
        DB::table('help_article_links')->where(fn ($q) => $q->where(['help_article_id' => $from, 'related_article_id' => $to]))
            ->orWhere(fn ($q) => $q->where(['help_article_id' => $to, 'related_article_id' => $from]))->delete();
    }
}
