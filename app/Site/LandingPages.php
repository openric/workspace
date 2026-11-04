<?php

// OpenRiC Workspace - pages built of blocks (roadmap phase 20a), after Heratio's ahg-landing-page LandingPageService:
// the institution's public landing pages, its default one the catalogue's home, and each user's own dashboards; each
// page's blocks in order, a row block holding others in its columns, each of its type with its settings, shown or
// hidden, copied and moved; and the data each block shows, from what the public may read (BlockData).
// Changed from Heratio: each institution has its own pages; the block types are a vocabulary, their default settings
// here; a page's history is the audit log rather than a version table Heratio never writes; a page keeps no JavaScript
// of its own, so nothing typed into it runs in a visitor's browser; and a dashboard belongs to its owner, not to a
// layout value.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Site;

use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\LandingPageBlock;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LandingPages
{
    /** Row blocks and how many columns each holds (Heratio's is_container). */
    public const ROWS = ['row-1-col' => 1, 'row-2-col' => 2, 'row-3-col' => 3];

    /** Each block type's settings when it is added (Heratio's default_config, in the Workspace's kinds). */
    public const DEFAULTS = [
        'row-1-col' => [],
        'row-2-col' => ['left_width' => 6, 'right_width' => 6],
        'row-3-col' => ['col_widths' => [4, 4, 4]],
        'hero-banner' => ['title' => 'Welcome', 'subtitle' => '', 'background_image' => '', 'overlay_opacity' => 0.5, 'height' => 400, 'text_align' => 'center', 'button_text' => '', 'button_url' => ''],
        'text-content' => ['content' => ''],
        'image-carousel' => ['source' => 'collections', 'images' => [], 'limit' => 12, 'autoplay' => true, 'interval' => 5000],
        'search-box' => ['placeholder' => 'Search the archive...', 'show_advanced' => false],
        'statistics' => ['title' => '', 'stats' => [['kind' => 'records', 'label' => 'Archival descriptions'], ['kind' => 'repositories', 'label' => 'Repositories'],
            ['kind' => 'agents', 'label' => 'People, families and organisations'], ['kind' => 'places', 'label' => 'Places']]],
        'browse-panels' => ['columns' => 3, 'show_counts' => true, 'panels' => [['title' => 'Archival descriptions', 'kind' => 'records'], ['title' => 'Authority records', 'kind' => 'agents'],
            ['title' => 'Repositories', 'kind' => 'repositories']]],
        'recent-items' => ['title' => 'Recently published', 'limit' => 6, 'columns' => 3, 'show_date' => true, 'show_thumbnail' => true],
        'holdings-list' => ['title' => 'Holdings', 'limit' => 10, 'repository_id' => null, 'sort' => 'title', 'show_level' => true],
        'featured-items' => ['title' => 'Featured', 'items' => [], 'columns' => 3, 'show_description' => true, 'show_thumbnail' => true],
        'repository-spotlight' => ['repository_id' => null, 'max_holdings' => 5, 'show_description' => true, 'show_contact' => true],
        'map' => ['title' => 'Places', 'height' => 400, 'zoom' => 6],
        'header-section' => ['title' => '', 'subtitle' => '', 'show_breadcrumb' => false],
        'footer-section' => ['columns' => []],
        'quick-links' => ['links' => []],
        'copyright-bar' => ['text' => 'All rights reserved.', 'year' => 'auto', 'organisation' => ''],
        'spacer' => ['height' => 2],
        'divider' => ['style' => 'solid', 'color' => '#dee2e6', 'width' => 100],
    ];

    /** The homepage Heratio seeds: a hero banner, a search box, statistics, browse panels and recent items. */
    public const STARTER = ['hero-banner' => ['title' => 'Welcome to the archive', 'subtitle' => 'Explore our collections', 'height' => 350], 'search-box' => ['placeholder' => 'Search our collections...'],
        'statistics' => [], 'browse-panels' => [], 'recent-items' => []];

    public function __construct(private BlockData $data) {}

    /** The page shown at /landing/{slug}, or with none the default: active, and the institution's own, not a dashboard. */
    public function public(Institution $institution, ?string $slug): ?LandingPage
    {
        return LandingPage::where(['institution_id' => $institution->id, 'is_active' => true])->whereNull('owner_id')
            ->when($slug, fn ($q) => $q->where('slug', $slug), fn ($q) => $q->where('is_default', true))->first();
    }

    public function save(Institution $institution, array $data, User $actor, ?LandingPage $page = null, ?User $owner = null): LandingPage
    {
        $name = trim((string) ($data['name'] ?? $page?->name ?? ''));
        $name !== '' || throw new InvalidArgumentException(__('A page has a name.'));
        $slug = Str::slug(trim((string) ($data['slug'] ?? '')) ?: ($page?->slug ?? $name)) ?: 'page';
        LandingPage::where(['institution_id' => $institution->id, 'slug' => $slug])->when($page, fn ($q) => $q->whereKeyNot($page->id))->exists()
            && throw new InvalidArgumentException(__('Another page has that address.'));
        $owner ??= $page && $page->owner_id ? User::find($page->owner_id) : null;
        $fields = ['name' => $name, 'slug' => $slug, 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'is_default' => ! $owner && ! empty($data['is_default']), 'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($page?->is_active ?? true),
            'meta_title' => trim((string) ($data['meta_title'] ?? '')) ?: null, 'meta_description' => trim((string) ($data['meta_description'] ?? '')) ?: null,
            'css_classes' => self::classes($data['css_classes'] ?? null), 'custom_css' => self::css($data['custom_css'] ?? null)];

        return AuditLog::actingAs($actor, fn () => DB::transaction(function () use ($institution, $fields, $actor, $page, $owner) {
            if ($fields['is_default']) {
                LandingPage::where(['institution_id' => $institution->id, 'is_default' => true])->whereNull('owner_id')->when($page, fn ($q) => $q->whereKeyNot($page->id))
                    ->get()->each->update(['is_default' => false]);
            }
            $page ??= new LandingPage(['institution_id' => $institution->id, 'owner_id' => $owner?->id, 'created_by_id' => $actor->id]);
            $page->fill($fields + ['published_at' => $page->published_at ?? ($fields['is_active'] ? now() : null)])->save();

            return $page->fresh();
        }));
    }

    /** Heratio's seeded homepage, for a new page started from it. */
    public function seedStarter(LandingPage $page, User $actor): void
    {
        foreach (self::STARTER as $type => $config) {
            $this->addBlock($page, $type, $config, $actor);
        }
    }

    public function delete(LandingPage $page, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $page->delete());
    }

    /**
     * The page's top-level blocks in order, each with its children and the data it shows.
     *
     * @return Collection<int, LandingPageBlock>
     */
    public function blocks(LandingPage $page, bool $visibleOnly, ?PublicCatalogue $catalogue = null): Collection
    {
        $all = LandingPageBlock::where('landing_page_id', $page->id)->when($visibleOnly, fn ($q) => $q->where('is_visible', true))->with('type.labels')
            ->orderBy('column_slot')->orderBy('position')->get();
        $children = $all->whereNotNull('parent_block_id')->groupBy('parent_block_id');
        $catalogue ??= new PublicCatalogue(Institution::findOrFail($page->institution_id));
        $top = $all->whereNull('parent_block_id')->sortBy('position')->values();
        foreach ($all as $block) {
            $block->setRelation('children', $children->get($block->id, collect())->values());
            $block->data = $this->data->resolve($block->type->notation, $this->config($block), $catalogue);
        }

        return $top;
    }

    /** The block's settings over its type's defaults. */
    public function config(LandingPageBlock $block): array
    {
        return array_replace(self::DEFAULTS[$block->type->notation] ?? [], (array) $block->config);
    }

    /** Add a block of a type at the end of the page, or of a column of a row block. */
    public function addBlock(LandingPage $page, string $type, array $config, User $actor, ?LandingPageBlock $parent = null, ?int $column = null): LandingPageBlock
    {
        array_key_exists($type, self::DEFAULTS) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $type]));
        if ($parent) {
            ($parent->landing_page_id === $page->id && isset(self::ROWS[$parent->type->notation])) || throw new InvalidArgumentException(__('A block goes inside a row block of the same page.'));
            isset(self::ROWS[$type]) && throw new InvalidArgumentException(__('A row block does not go inside another.'));
            $column = max(1, min((int) ($column ?? 1), self::ROWS[$parent->type->notation]));
        }

        return AuditLog::actingAs($actor, fn () => LandingPageBlock::create(['landing_page_id' => $page->id, 'block_type_id' => $this->type($type), 'parent_block_id' => $parent?->id,
            'column_slot' => $parent ? $column : null, 'config' => array_replace(self::DEFAULTS[$type], $config),
            'position' => 1 + (int) LandingPageBlock::where(['landing_page_id' => $page->id, 'parent_block_id' => $parent?->id])->max('position')]));
    }

    /** Change a block's title, settings and how it is shown. */
    public function updateBlock(LandingPageBlock $block, array $data, User $actor): LandingPageBlock
    {
        $fields = [];
        foreach (['title' => fn ($v) => trim((string) $v) ?: null, 'css_classes' => fn ($v) => self::classes($v), 'full_width' => fn ($v) => (bool) $v, 'is_visible' => fn ($v) => (bool) $v,
            'background_color' => fn ($v) => self::colour($v), 'text_color' => fn ($v) => self::colour($v),
            'padding_top' => fn ($v) => max(0, min(6, round((float) $v, 1))), 'padding_bottom' => fn ($v) => max(0, min(6, round((float) $v, 1))),
            'col_span' => fn ($v) => max(1, min(12, (int) $v))] as $field => $clean) {
            if (array_key_exists($field, $data)) {
                $fields[$field] = $clean($data[$field]);
            }
        }
        if (array_key_exists('config', $data)) {
            $fields['config'] = self::cleanConfig($block->type->notation, (array) $data['config']);
        }

        return AuditLog::actingAs($actor, function () use ($block, $fields) {
            $block->update($fields);

            return $block->fresh('type');
        });
    }

    public function deleteBlock(LandingPageBlock $block, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $block->delete());
    }

    /** Heratio's reorderBlocks: the blocks of the page in the order given, within the row they sit in. */
    public function reorder(LandingPage $page, array $ids, User $actor): void
    {
        AuditLog::actingAs($actor, function () use ($page, $ids) {
            $blocks = LandingPageBlock::where('landing_page_id', $page->id)->whereIn('id', $ids)->get()->keyBy('id');
            foreach (array_values($ids) as $i => $id) {
                $blocks->get($id)?->update(['position' => $i + 1]);
            }
        });
    }

    /** Heratio's duplicateBlock: a copy just after it, and of a row block its blocks too. */
    public function duplicate(LandingPageBlock $block, User $actor): LandingPageBlock
    {
        return AuditLog::actingAs($actor, fn () => DB::transaction(function () use ($block) {
            LandingPageBlock::where(['landing_page_id' => $block->landing_page_id, 'parent_block_id' => $block->parent_block_id])->where('position', '>', $block->position)
                ->get()->each(fn ($b) => $b->update(['position' => $b->position + 1]));
            $copy = $block->replicate();
            $copy->fill(['title' => $block->title ? $block->title.' ('.__('copy').')' : null, 'position' => $block->position + 1])->save();
            foreach ($block->children()->get() as $child) {
                $child->replicate()->fill(['parent_block_id' => $copy->id])->save();
            }

            return $copy;
        }));
    }

    public function toggle(LandingPageBlock $block, User $actor): bool
    {
        AuditLog::actingAs($actor, fn () => $block->update(['is_visible' => ! $block->is_visible]));

        return $block->is_visible;
    }

    /** The block types, in the vocabulary's order. */
    public function types(): Collection
    {
        return Concept::choices(Vocab::BASE.'landing-block-type/')->get();
    }

    private function type(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'landing-block-type/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }

    /** A setting's name on screen: left_width as "Left width". */
    public static function settingLabel(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /** Only the settings a block type has, each of the kind its default is; links only to the web or within the site. */
    public static function cleanConfig(string $type, array $config): array
    {
        $out = [];
        foreach (self::DEFAULTS[$type] ?? [] as $key => $default) {
            if (! array_key_exists($key, $config)) {
                continue;
            }
            $value = $config[$key];
            $out[$key] = match (true) {
                is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                is_int($default) => (int) $value,
                is_float($default) => (float) $value,
                is_array($default) => self::cleanList(is_array($value) ? $value : (json_decode((string) $value, true) ?: [])),
                str_ends_with($key, '_url') || $key === 'background_image' => self::url($value),
                $default === null => ($value === '' || $value === null) ? null : (string) $value,
                default => Str::limit(trim((string) $value), 20000, ''),
            };
        }

        return $out;
    }

    /** A list of settings: each value text, a number, or a list of the same; any link made safe. */
    private static function cleanList(array $items): array
    {
        $clean = [];
        foreach (array_slice($items, 0, 100, true) as $key => $value) {
            $clean[$key] = match (true) {
                is_array($value) => self::cleanList($value),
                is_int($value), is_float($value), is_bool($value) => $value,
                in_array($key, ['url', 'link', 'href'], true) => self::url($value),
                default => Str::limit(trim((string) $value), 2000, ''),
            };
        }

        return $clean;
    }

    /** An address a visitor may follow: on the web, or a path on this site; anything else, such as javascript:, is dropped. */
    public static function url(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('~^(https?://[^\s"<>]+|/[^\s"<>]*)$~i', $value) && ! str_starts_with($value, '//') ? $value : '';
    }

    private static function colour(mixed $value): ?string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $value) ? (string) $value : null;
    }

    private static function classes(mixed $value): ?string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_ -]/', '', (string) $value)) ?: null;
    }

    /** A page's own style sheet, which cannot close its style element. */
    private static function css(mixed $value): ?string
    {
        return trim(str_ireplace(['</style', '<!--', '-->'], '', (string) $value)) ?: null;
    }
}
