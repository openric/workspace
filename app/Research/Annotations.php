<?php

// OpenRiC Workspace - a researcher's annotations (roadmap phase 19d, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchAnnotationsController (annotations, annotationStudio, store, update, destroy), its evidence
// viewer's notes and tags, and research_annotation with research_annotation_target: notes, highlights, bookmarks, tags
// and transcriptions on an entity, or on a part of it - a region of an image, a point, a quote, a range of text, a
// stretch of a recording - as the W3C Web Annotation model's selectors have them, given out as a W3C AnnotationPage.
// Who reads one: its author; when shared, the members of the research workspaces its author belongs to; when public,
// every signed-in account of the institution; and always only while they may read the entity. Changed: Heratio keeps
// HTML content, cleaned; here content is plain text, shown as text. Heratio's iiif_annotation_id is the annotation's
// own address in the AnnotationPage.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\ResearchAnnotation;
use App\Models\ResearchCollection;
use App\Models\ResearchWorkspaceMember;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Annotations
{
    public const TYPES = Vocab::BASE.'annotation-type/';

    public const VISIBILITY = Vocab::BASE.'annotation-visibility/';

    public const SELECTORS = Vocab::BASE.'selector-type/';

    /** The W3C motivation of each kind. */
    public const MOTIVATIONS = ['note' => 'commenting', 'highlight' => 'highlighting', 'bookmark' => 'bookmarking', 'tag' => 'tagging', 'transcription' => 'supplementing'];

    public function __construct(private Diary $diary) {}

    public function save(User $user, Model $subject, array $data, ?ResearchAnnotation $annotation = null): ResearchAnnotation
    {
        if ($annotation && $annotation->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if ($subject->getAttribute('institution_id') !== $user->institution_id || ! EntityKinds::readable($user, $subject)) {
            throw new InvalidArgumentException(__('You may not annotate that.'));
        }
        $type = $data['annotation_type'] ?? 'note';
        if (trim((string) ($data['content'] ?? '')) === '' && trim((string) ($data['title'] ?? '')) === '' && ! in_array($type, ['highlight', 'bookmark'], true)) {
            throw new InvalidArgumentException(__('Write what the annotation says.'));
        }
        $collection = ($data['collection_id'] ?? null) ? ResearchCollection::where(['id' => $data['collection_id'], 'user_id' => $user->id])->first()
            ?? throw new InvalidArgumentException(__('That is not yours.')) : null;
        if (($data['instantiation_id'] ?? null) && ! Instantiation::where(['id' => $data['instantiation_id'], 'record_resource_id' => $subject->getKey()])->exists()) {
            throw new InvalidArgumentException(__('That copy is not of this record.'));
        }
        $selector = $this->selector((string) ($data['selector_type'] ?? ''), $data);
        $fields = ['annotation_type_id' => $this->concept(self::TYPES, $type), 'visibility_id' => $this->concept(self::VISIBILITY, $data['visibility'] ?? 'private'),
            'title' => trim((string) ($data['title'] ?? '')) ?: null, 'content' => trim((string) ($data['content'] ?? '')) ?: null, 'tags' => Diary::tags($data['tags'] ?? null),
            'collection_id' => $collection?->id, 'canvas_id' => trim((string) ($data['canvas_id'] ?? '')) ?: null, 'instantiation_id' => $data['instantiation_id'] ?? null];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $subject, $annotation, $fields, $selector, $data) {
            if ($annotation) {
                $annotation->update($fields);
            } else {
                $annotation = ResearchAnnotation::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id,
                    'subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()]);
                $this->diary->auto($user, 'auto-annotation', __('Annotated ":title"', ['title' => EntityKinds::label($subject)]), $annotation);
            }
            if ($selector !== null) {
                $annotation->targets()->delete();
                $annotation->targets()->create(['selector_type_id' => $this->concept(self::SELECTORS, $data['selector_type']), 'selector' => $selector,
                    'source_url' => $fields['canvas_id'], 'created_at' => now()]);
            } elseif (! empty($data['clear_selector'])) {
                $annotation->targets()->delete();
            }

            return $annotation;
        }));
    }

    public function delete(User $user, ResearchAnnotation $annotation): void
    {
        if ($annotation->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $annotation->delete()));
    }

    /** The entity an annotation is about. */
    public function subject(ResearchAnnotation $annotation): ?Model
    {
        $kind = EntityKinds::byTable($annotation->subject_table);
        $class = $kind ? EntityKinds::get($kind)['class'] : null;

        return $class ? $class::find($annotation->subject_id) : null;
    }

    /** The annotations an account may read: its own, the public ones, and the shared ones of who shares a research workspace with it. */
    public function readable(User $user): Builder
    {
        $public = $this->concept(self::VISIBILITY, 'public');
        $shared = $this->concept(self::VISIBILITY, 'shared');

        return ResearchAnnotation::where('institution_id', $user->institution_id)->where(fn ($w) => $w->where('user_id', $user->id)->orWhere('visibility_id', $public)
            ->orWhere(fn ($s) => $s->where('visibility_id', $shared)->whereIn('user_id', $this->colleagues($user))));
    }

    /** The annotations of an entity an account may read, newest first. */
    public function of(User $user, Model $subject): Collection
    {
        if (! EntityKinds::readable($user, $subject)) {
            return collect();
        }

        return $this->readable($user)->where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()])
            ->with('annotationType', 'visibility', 'targets.selectorType', 'user')->orderByDesc('created_at')->get();
    }

    /** One's own annotations, by words and kind (Heratio's annotations list and searchAnnotations). */
    public function mine(User $user, ?string $words = null, ?string $type = null): Collection
    {
        $q = trim((string) $words);

        return ResearchAnnotation::where('user_id', $user->id)->with('annotationType', 'visibility', 'targets.selectorType')
            ->when($type, fn ($w) => $w->whereHas('annotationType', fn ($c) => $c->where('iri', self::TYPES.$type)))
            ->when($q !== '', fn ($w) => $w->where(fn ($o) => $o->where('title', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('content', 'ilike', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('tags', 'ilike', '%'.addcslashes($q, '%_\\').'%')))
            ->orderByDesc('created_at')->get()->filter(fn ($a) => ($s = $this->subject($a)) && EntityKinds::readable($user, $s))->values();
    }

    /** The tags of an entity's annotations one may read (Heratio's evidence viewer). @return list<string> */
    public function tags(User $user, Model $subject): array
    {
        return $this->of($user, $subject)->pluck('tags')->filter()->flatMap(fn ($t) => explode(',', $t))->map(fn ($t) => trim($t))->filter()->unique()->sort()->values()->all();
    }

    /** The annotations of an entity one may read, as a W3C AnnotationPage (JSON-LD). */
    public function page(User $user, Model $subject, string $id): array
    {
        $source = (string) ($subject->getAttribute('iri') ?? EntityKinds::route($subject));

        return ['@context' => 'http://www.w3.org/ns/anno.jsonld', 'id' => $id, 'type' => 'AnnotationPage',
            'items' => $this->of($user, $subject)->map(fn ($a) => array_filter([
                'id' => $id.'#'.$a->id, 'type' => 'Annotation', 'motivation' => self::MOTIVATIONS[$a->annotationType->notation] ?? 'commenting',
                'created' => $a->created_at?->toIso8601String(), 'modified' => $a->updated_at?->toIso8601String(), 'creator' => ['type' => 'Person', 'name' => $a->user?->name],
                'body' => $a->content !== null || $a->title !== null ? array_values(array_filter([
                    $a->content !== null ? ['type' => 'TextualBody', 'value' => $a->content, 'format' => 'text/plain', 'purpose' => self::MOTIVATIONS[$a->annotationType->notation] ?? 'commenting'] : null,
                    ...collect(explode(',', (string) $a->tags))->map(fn ($t) => trim($t))->filter()->map(fn ($t) => ['type' => 'TextualBody', 'value' => $t, 'purpose' => 'tagging'])->all(),
                ])) : null,
                'target' => ($t = $a->targets->first()) ? ['source' => $a->canvas_id ?? $source, 'selector' => $t->selector] : ($a->canvas_id ?? $source),
            ]))->values()->all()];
    }

    /** A W3C selector from the form: null when none was given. */
    public function selector(string $type, array $data): ?array
    {
        $value = trim((string) ($data['selector_value'] ?? ''));
        if ($type === '' || ($value === '' && $type !== 'text-quote')) {
            return null;
        }
        $number = '\d+(?:\.\d+)?';
        $selector = match ($type) {
            'fragment' => preg_match("/^xywh=(?:percent:|pixel:)?$number,$number,$number,$number$/", $value) ? ['type' => 'FragmentSelector', 'conformsTo' => 'http://www.w3.org/TR/media-frags/', 'value' => $value] : null,
            'time' => preg_match("/^t=$number(?:,$number)?$/", $value) ? ['type' => 'FragmentSelector', 'conformsTo' => 'http://www.w3.org/TR/media-frags/', 'value' => $value] : null,
            'point' => preg_match("/^($number),($number)$/", $value, $m) ? ['type' => 'PointSelector', 'x' => (float) $m[1], 'y' => (float) $m[2]] : null,
            'range' => preg_match('/^(\d+),(\d+)$/', $value, $m) && (int) $m[1] <= (int) $m[2] ? ['type' => 'RangeSelector',
                'startSelector' => ['type' => 'TextPositionSelector', 'start' => (int) $m[1], 'end' => (int) $m[1]], 'endSelector' => ['type' => 'TextPositionSelector', 'start' => (int) $m[2], 'end' => (int) $m[2]]] : null,
            'svg' => preg_match('/^<svg[\s>].*<\/svg>$/is', $value) && mb_strlen($value) <= 100000 ? ['type' => 'SvgSelector', 'value' => $value] : null,
            'text-quote' => trim((string) ($data['exact'] ?? '')) !== '' ? array_filter(['type' => 'TextQuoteSelector', 'exact' => trim($data['exact']),
                'prefix' => ($data['prefix'] ?? '') !== '' ? $data['prefix'] : null, 'suffix' => ($data['suffix'] ?? '') !== '' ? $data['suffix'] : null]) : null,
            default => throw new InvalidArgumentException(__(':value is not in the list', ['value' => $type])),
        };

        return $selector ?? throw new InvalidArgumentException(__('Where in the record the annotation points is not written as its kind expects.'));
    }

    /** The accounts that share an accepted membership of a research workspace with this one. */
    private function colleagues(User $user)
    {
        $accepted = $this->concept(Vocab::BASE.'membership-status/', 'accepted');

        return ResearchWorkspaceMember::where('status_id', $accepted)->whereIn('workspace_id',
            ResearchWorkspaceMember::where(['user_id' => $user->id, 'status_id' => $accepted])->select('workspace_id'))->select('user_id');
    }

    private function concept(string $scheme, string $notation): string
    {
        return Concept::where('iri', $scheme.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
