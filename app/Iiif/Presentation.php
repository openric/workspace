<?php

// OpenRiC Workspace - IIIF Presentation 3: a manifest for each published record, a collection for each record with
// records inside it, the institution's top collection and its curated collections (roadmap phase 14a, ADR 0014).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's IiifManifestV3Service (the manifest: label and summary in each language, metadata, rights,
// requiredStatement, provider, homepage, seeAlso, partOf, navDate, viewingDirection, start, behavior, rendering,
// thumbnail) and Heratio's IiifCollectionService (canvases, audio and video canvases, collections). The record is
// the RiC rico:RecordResource; each of its instantiations a visitor may see is one canvas, painting the file a visitor
// may receive: the reference copy, as the Workspace keeps no IIIF Image API server (no image service, so no deep-zoom
// tiles), or the file itself for sound and video. A visitor's view decides everything here, as it does in the API.

namespace App\Iiif;

use App\Api\PublicCatalogue;
use App\Description\InstantiationService;
use App\Files\FileStore;
use App\Models\DigitalFile;
use App\Models\IiifCollection;
use App\Models\Instantiation;
use App\Models\RecordResource;
use App\Rights\PublicRights;
use Illuminate\Support\Collection;

class Presentation
{
    public const CONTEXT = 'http://iiif.io/api/presentation/3/context.json';

    /** Scripts read right to left (ISO 15924), for viewingDirection. */
    private const RIGHT_TO_LEFT = ['Arab', 'Hebr', 'Syrc', 'Thaa', 'Nkoo', 'Adlm', 'Mand', 'Samr'];

    /** Image formats a browser paints. */
    private const IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private PublicCatalogue $catalogue) {}

    /** The record's manifest, or null when a visitor may see no image, sound or video of it. */
    public function manifest(RecordResource $unit): ?array
    {
        $view = $this->catalogue->view;
        $id = route('iiif.manifest', $unit->id);
        $canvases = [];
        $rendering = [];
        $thumbnail = null;
        foreach ($this->instantiations($unit) as [$instantiation, $files]) {
            if ($canvas = $this->canvas($id, $instantiation, $files)) {
                $canvases[] = $canvas;
                $thumbnail ??= $canvas['thumbnail'][0] ?? null;
            }
            // The file itself, to take away rather than look at, where a visitor may have it.
            if (($master = $files->get('master')) && ! $this->painted($canvas, $master)) {
                $rendering[] = ['id' => route('discover.file', $master), 'type' => $this->type($master->mime_type), 'label' => $this->strings([[$master->original_name, 'none']]),
                    'format' => $master->mime_type];
            }
        }
        if ($canvases === []) {
            return null;
        }

        $manifest = ['@context' => self::CONTEXT, 'id' => $id, 'type' => 'Manifest', 'label' => $this->label($unit)];
        if ($summary = $this->text($unit, 'scope-and-content')) {
            $manifest['summary'] = $summary;
        }
        $manifest['metadata'] = $this->metadata($unit);
        if ($rights = $this->rights($unit)) {
            $manifest['rights'] = $rights;
        }
        $holder = $unit->repository ?? collect($unit->ancestors())->first()?->repository;
        $holderName = $holder && $view->readable($holder) ? $view->label($holder) : null;
        $manifest['requiredStatement'] = ['label' => ['en' => ['Held by']], 'value' => ['none' => [$holderName ?? $this->catalogue->institution->name]]];
        $manifest['provider'] = [$this->provider()];
        $manifest['homepage'] = [['id' => route('discover.record', $unit->id), 'type' => 'Text', 'label' => $manifest['label'], 'format' => 'text/html']];
        // The RiC-O description the manifest presents (ADR 0014).
        $manifest['seeAlso'] = [['id' => route('api.record', $unit->id), 'type' => 'Dataset', 'label' => ['en' => ['RiC-O description']], 'format' => 'application/ld+json',
            'profile' => 'https://www.ica.org/standards/RiC/ontology']];
        if ($unit->parent && $view->readable($unit->parent)) {
            $manifest['partOf'] = [['id' => route('iiif.collection', $unit->parent_id), 'type' => 'Collection', 'label' => $this->label($unit->parent)]];
        }
        if ($navDate = $this->navDate($unit)) {
            $manifest['navDate'] = $navDate;
        }
        if ($this->rightToLeft($unit)) {
            $manifest['viewingDirection'] = 'right-to-left';
        }
        if (count($canvases) > 1) {
            $manifest['start'] = ['id' => $canvases[0]['id'], 'type' => 'Canvas'];
            $manifest['behavior'] = ['individuals'];
        }
        if ($thumbnail) {
            $manifest['thumbnail'] = [$thumbnail];
        }
        if ($rendering !== []) {
            $manifest['rendering'] = $rendering;
        }
        $manifest['items'] = $canvases;

        return $manifest;
    }

    /** Whether a visitor may see an image, sound or video of the record: whether it has a manifest. */
    public function hasManifest(RecordResource $unit): bool
    {
        foreach ($this->instantiations($unit) as [, $files]) {
            if ($this->painting($files) !== null) {
                return true;
            }
        }

        return false;
    }

    /** A record with records inside it, as a collection of its parts: those with parts of their own as collections, the rest as manifests. */
    public function collection(RecordResource $unit): array
    {
        $collection = ['@context' => self::CONTEXT, 'id' => route('iiif.collection', $unit->id), 'type' => 'Collection', 'label' => $this->label($unit)];
        if ($summary = $this->text($unit, 'scope-and-content')) {
            $collection['summary'] = $summary;
        }
        $collection['provider'] = [$this->provider()];
        $collection['homepage'] = [['id' => route('discover.record', $unit->id), 'type' => 'Text', 'label' => $collection['label'], 'format' => 'text/html']];
        if ($unit->parent && $this->catalogue->view->readable($unit->parent)) {
            $collection['partOf'] = [['id' => route('iiif.collection', $unit->parent_id), 'type' => 'Collection']];
        }
        $collection['items'] = $this->references($unit->children()->whereNull('deleted_at')->orderBy('position')->orderBy('created_at')->get());

        return $collection;
    }

    /** The institution's collection: its published records at the top, then its public curated collections. */
    public function top(): array
    {
        $institution = $this->catalogue->institution;
        $records = RecordResource::where('institution_id', $institution->id)->whereNull('parent_id')->whereNull('deleted_at')->orderBy('created_at')->get();
        $curated = IiifCollection::where(['institution_id' => $institution->id, 'is_public' => true])->whereNull('parent_id')->orderBy('position')->orderBy('label')->get();

        return ['@context' => self::CONTEXT, 'id' => route('iiif.top'), 'type' => 'Collection', 'label' => ['none' => [$institution->name]],
            'provider' => [$this->provider()],
            'items' => [...$this->references($records), ...$curated->map(fn (IiifCollection $c) => ['id' => route('iiif.curated', $c), 'type' => 'Collection', 'label' => [$c->language => [$c->label]]])]];
    }

    /** A curated collection, with the items a visitor may have: published records with a manifest, public collections, outside resources. */
    public function curated(IiifCollection $curated): array
    {
        $json = ['@context' => self::CONTEXT, 'id' => route('iiif.curated', $curated), 'type' => 'Collection', 'label' => [$curated->language => [$curated->label]]];
        if ($curated->summary) {
            $json['summary'] = [$curated->language => [$curated->summary]];
        }
        if ($curated->attribution) {
            $json['requiredStatement'] = ['label' => ['en' => ['Attribution']], 'value' => [$curated->language => [$curated->attribution]]];
        }
        $json['provider'] = [$this->provider()];
        if ($curated->behavior) {
            $json['behavior'] = [$curated->behavior->notation];
        }
        if ($curated->nav_date) {
            $json['navDate'] = $curated->nav_date->format('Y-m-d').'T00:00:00Z';
        }
        $items = [];
        foreach ($curated->items()->with('record', 'memberCollection', 'resourceType')->get() as $item) {
            if ($item->record) {
                $unit = $this->catalogue->find('records', $item->record_resource_id);
                if ($unit && $this->hasManifest($unit)) {
                    $items[] = ['id' => route('iiif.manifest', $unit->id), 'type' => 'Manifest', 'label' => $item->label ? ['none' => [$item->label]] : $this->label($unit)];
                }
            } elseif ($item->memberCollection) {
                if ($item->memberCollection->is_public) {
                    $items[] = ['id' => route('iiif.curated', $item->memberCollection), 'type' => 'Collection',
                        'label' => ['none' => [$item->label ?? $item->memberCollection->label]]];
                }
            } else {
                $items[] = ['id' => $item->uri, 'type' => ucfirst($item->resourceType->notation), 'label' => ['none' => [$item->label ?? $item->uri]]];
            }
        }
        $json['items'] = $items;

        return $json;
    }

    /**
     * Records as collection items: a record with published records inside it is a collection, one without is a
     * manifest when a visitor may see an image, sound or video of it. A record that is neither is left out.
     * ponytail: each record's files are read to know whether it has a manifest; a fonds of thousands of parts wants that
     * kept with the record when its files change.
     */
    private function references(Collection $units): array
    {
        $items = [];
        foreach ($units as $unit) {
            if (! $this->catalogue->view->readable($unit)) {
                continue;
            }
            $hasParts = $unit->children()->whereNull('deleted_at')->get()->contains(fn ($c) => $this->catalogue->view->readable($c));
            if ($hasParts) {
                $items[] = ['id' => route('iiif.collection', $unit->id), 'type' => 'Collection', 'label' => $this->label($unit)];
            } elseif ($this->hasManifest($unit)) {
                $items[] = ['id' => route('iiif.manifest', $unit->id), 'type' => 'Manifest', 'label' => $this->label($unit)];
            }
        }

        return $items;
    }

    /**
     * The record's instantiations a visitor may see, in order, each with the files a visitor may receive, by use.
     *
     * @return list<array{0: Instantiation, 1: Collection<string, DigitalFile>}>
     */
    private function instantiations(RecordResource $unit): array
    {
        $service = app(InstantiationService::class);
        $out = [];
        $instantiations = Instantiation::where('record_resource_id', $unit->id)->whereNull('deleted_at')
            ->with([...PublicCatalogue::WITH, 'availability', 'files.use', 'files.instantiation.record', 'files.instantiation.availability'])->orderBy('position')->orderBy('id')->get();
        foreach ($instantiations as $instantiation) {
            if ($this->catalogue->view->label($instantiation) === null) {
                continue;
            }
            $files = $instantiation->files->filter(fn (DigitalFile $file) => $service->deliverable(null, $file))->keyBy(fn ($file) => $file->use->notation);
            if ($files->isNotEmpty()) {
                $out[] = [$instantiation, $files];
            }
        }

        return $out;
    }

    /** The file a canvas paints: the reference copy if it is an image, else the file itself if it is an image, sound or video. */
    private function painting(Collection $files): ?DigitalFile
    {
        foreach (['reference', 'master'] as $use) {
            $file = $files->get($use);
            if ($file && (in_array($file->mime_type, self::IMAGES, true) || $this->timed($file))) {
                return $file;
            }
        }

        return null;
    }

    private function canvas(string $manifestId, Instantiation $instantiation, Collection $files): ?array
    {
        $file = $this->painting($files);
        if ($file === null) {
            return null;
        }
        $id = $manifestId.'/canvas/'.$instantiation->id;
        $label = $this->label($instantiation);
        $body = ['id' => route('discover.file', $file), 'type' => $this->type($file->mime_type), 'format' => $file->mime_type];
        $canvas = ['id' => $id, 'type' => 'Canvas', 'label' => $label];
        if ($this->timed($file)) {
            $duration = $this->duration($files);
            if ($duration === null) {
                return null; // a timed canvas needs its duration; the file is still offered to take away
            }
            $canvas['duration'] = $body['duration'] = $duration;
            if ($body['type'] === 'Video' && $file->width && $file->height) {
                $canvas['width'] = $body['width'] = $file->width;
                $canvas['height'] = $body['height'] = $file->height;
            }
        } else {
            [$width, $height] = $this->size($file);
            if ($width === null) {
                return null;
            }
            $canvas['width'] = $body['width'] = $width;
            $canvas['height'] = $body['height'] = $height;
        }
        if ($thumbnail = $files->get('thumbnail')) {
            [$w, $h] = $this->size($thumbnail);
            $canvas['thumbnail'] = [array_filter(['id' => route('discover.file', $thumbnail), 'type' => 'Image', 'format' => $thumbnail->mime_type, 'width' => $w, 'height' => $h])];
        }
        $canvas['items'] = [['id' => $id.'/page', 'type' => 'AnnotationPage', 'items' => [
            ['id' => $id.'/page/painting', 'type' => 'Annotation', 'motivation' => 'painting', 'body' => $body, 'target' => $id],
        ]]];

        return $canvas;
    }

    /** Width and height: as kept, else read from the stored file. */
    private function size(DigitalFile $file): array
    {
        if ($file->width && $file->height) {
            return [$file->width, $file->height];
        }
        $size = @getimagesize(app(FileStore::class)->path($file->storage_path));

        return $size ? [$size[0], $size[1]] : [null, null];
    }

    /** Seconds, from what exiftool read from the file itself. */
    private function duration(Collection $files): ?float
    {
        foreach (['master', 'reference'] as $use) {
            foreach ((array) ($files->get($use)?->embedded_metadata ?? []) as $tag => $value) {
                if (! str_ends_with((string) $tag, 'Duration')) {
                    continue;
                }
                $value = trim((string) $value);
                if (preg_match('/^(\d+):(\d{2}):(\d{2}(?:\.\d+)?)$/', $value, $m)) {
                    return $m[1] * 3600 + $m[2] * 60 + (float) $m[3];
                }
                if (preg_match('/^(\d+(?:\.\d+)?)\s*s?$/', $value, $m) && (float) $m[1] > 0) {
                    return (float) $m[1];
                }
            }
        }

        return null;
    }

    private function painted(?array $canvas, DigitalFile $file): bool
    {
        return $canvas !== null && ($canvas['items'][0]['items'][0]['body']['id'] ?? null) === route('discover.file', $file);
    }

    private function timed(DigitalFile $file): bool
    {
        return str_starts_with($file->mime_type, 'audio/') || str_starts_with($file->mime_type, 'video/');
    }

    private function type(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'Image',
            str_starts_with($mime, 'audio/') => 'Sound',
            str_starts_with($mime, 'video/') => 'Video',
            str_starts_with($mime, 'model/') => 'Model',
            default => 'Text',
        };
    }

    /** The entity's published name or title in each language (a IIIF language map). */
    private function label($entity): array
    {
        $names = $entity->namesOfType('authorised')->filter(fn ($n) => $this->catalogue->view->visible($n))->sortBy(fn ($n) => [! $n->is_original, $n->language]);

        return $names->isEmpty() ? ['none' => [(string) $this->catalogue->view->label($entity)]] : $this->strings($names->map(fn ($n) => [$n->value, $n->language])->all());
    }

    /** Every published value of an element, as a language map. */
    private function text($entity, string $notation): ?array
    {
        $values = $this->catalogue->view->values($entity, $notation);

        return $values->isEmpty() ? null : $this->strings($values->map(fn ($v) => [$v->value, $v->language])->all());
    }

    /** @param list<array{0: string, 1: ?string}> $pairs text and language */
    private function strings(array $pairs): array
    {
        $map = [];
        foreach ($pairs as [$text, $language]) {
            $map[$language && $language !== 'und' ? $language : 'none'][] = $text;
        }

        return $map;
    }

    /** What a reader sees first: reference code, level, dates, extent, creators and holder. */
    private function metadata(RecordResource $unit): array
    {
        $view = $this->catalogue->view;
        $pair = fn (string $label, array $value) => ['label' => ['en' => [$label]], 'value' => $value];
        $metadata = [];
        if ($code = $unit->referenceCode()) {
            $metadata[] = $pair('Reference code', ['none' => [$code]]);
        }
        if ($unit->level) {
            $metadata[] = $pair('Level of description', ['en' => [(string) $unit->level->label(['en', 'und'])]]);
        }
        $dates = $unit->dates->map(fn ($d) => $d->expressed ?? $d->normalized_value)->filter()->values()->all();
        if ($written = $view->value($unit, 'dates-expressed')) {
            $dates = [$written];
        }
        if ($dates !== []) {
            $metadata[] = $pair('Dates', ['none' => $dates]);
        }
        if ($extent = $this->text($unit, 'extent')) {
            $metadata[] = $pair('Extent', $extent);
        }
        $creators = $view->creators($unit)->map(fn ($r) => $view->label($r->other))->filter()->values()->all();
        if ($creators !== []) {
            $metadata[] = $pair('Creator', ['none' => $creators]);
        }

        return $metadata;
    }

    /** The first licence or rights statement in force: a Creative Commons or RightsStatements.org URI, as IIIF requires. */
    private function rights(RecordResource $unit): ?string
    {
        foreach ((new PublicRights($this->catalogue->view))->rights($unit) as $right) {
            foreach ([$right->licence?->iri, $right->statement?->iri] as $iri) {
                if ($iri && preg_match('~^https?://(creativecommons\.org/(licenses|publicdomain)/|rightsstatements\.org/vocab/)~', $iri)) {
                    return $iri;
                }
            }
        }

        return null;
    }

    private function provider(): array
    {
        $institution = $this->catalogue->institution;

        return ['id' => route('discover.home'), 'type' => 'Agent', 'label' => ['none' => [$institution->name]],
            'homepage' => [['id' => route('discover.home'), 'type' => 'Text', 'label' => ['none' => [$institution->name]], 'format' => 'text/html']]];
    }

    /** The day the record's creation began, for a viewer's timeline. */
    private function navDate(RecordResource $unit): ?string
    {
        $date = $unit->dates->first(fn ($d) => $d->property?->notation === 'creation' && $d->earliest) ?? $unit->dates->first(fn ($d) => $d->earliest);

        return $date ? $date->earliest->format('Y-m-d').'T00:00:00Z' : null;
    }

    private function rightToLeft(RecordResource $unit): bool
    {
        $scripts = preg_split('/\s+/', (string) $this->catalogue->view->value($unit, 'scripts-of-material-codes'), -1, PREG_SPLIT_NO_EMPTY);

        return $scripts !== [] && array_intersect($scripts, self::RIGHT_TO_LEFT) === $scripts;
    }
}
