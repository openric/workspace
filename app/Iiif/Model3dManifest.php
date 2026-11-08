<?php

// OpenRiC Workspace - a IIIF manifest for a 3D model (roadmap phase 21), after Heratio's ahg-3d-model
// Iiif3dManifestBuilder (#1180), aligned to the IIIF 3D Technical Specification Group's model (release candidate of
// January 2026, folding into Presentation 4): a canvas with a depth, the model, a camera and a light painted on it, and
// the model's published hotspots as annotations at a point (PointSelector x, y, z). Provisional, as the release
// candidate is: the property names may change when it is final.
// Changed from Heratio: labels and hotspot texts in every language they are written in; only published hotspots; the
// file the public may receive; the record's page as its homepage; the viewer hints under openricx:viewer.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Iiif;

use App\Files\ModelViews;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Instantiation;
use App\Models\ModelHotspot;
use App\Support\Vocab;

class Model3dManifest
{
    public function __construct(private ModelViews $views) {}

    public function build(Instantiation $instantiation, DigitalFile $model): array
    {
        $id = route('iiif.model3d', $instantiation);
        $canvas = $id.'/canvas/1';
        $settings = $this->views->settings($instantiation);
        $properties = $instantiation->file('master')?->model_properties ?? [];
        [$w, $h, $d] = $this->extent((string) ($properties['bounding_box'] ?? ''));
        $label = $this->languages($instantiation, 'instantiation-title') ?: ['none' => [(string) $model->original_name]];
        $painting = fn (string $what, array $body) => ['id' => $id.'/annotation/'.$what, 'type' => 'Annotation', 'motivation' => 'painting', 'body' => $body, 'target' => $canvas];

        $manifest = [
            '@context' => ['http://iiif.io/api/presentation/3/context.json', 'http://iiif.io/api/extension/3d/context.json'],
            'id' => $id, 'type' => 'Manifest', 'label' => $label,
            'metadata' => $this->metadata($properties),
            'homepage' => [['id' => route('discover.record', $instantiation->record_resource_id), 'type' => 'Text', 'format' => 'text/html', 'label' => $label]],
            'items' => [['id' => $canvas, 'type' => 'Canvas', 'height' => $h, 'width' => $w, 'depth' => $d, 'items' => [['id' => $canvas.'/page', 'type' => 'AnnotationPage', 'items' => [
                $painting('model', ['id' => route('discover.file', $model), 'type' => 'Model', 'format' => $model->mime_type, 'label' => $label]),
                $painting('camera', ['id' => $id.'/camera/1', 'type' => 'PerspectiveCamera', 'fieldOfView' => (float) preg_replace('/[^0-9.]/', '', (string) $settings['field_of_view']) ?: 30.0]),
                $painting('light', ['id' => $id.'/light/1', 'type' => 'AmbientLight', 'color' => '#ffffff', 'intensity' => (float) $settings['exposure']]),
            ]]]]],
        ];
        $published = Concept::where('iri', Vocab::valueState('published'))->value('id');
        $annotations = ModelHotspot::where('instantiation_id', $instantiation->id)->with('texts')->orderBy('position')->get()
            ->map(function (ModelHotspot $hotspot) use ($id, $canvas, $published) {
                $texts = $hotspot->texts->where('state_id', $published);
                if ($texts->isEmpty()) {
                    return null;
                }
                $body = $texts->map(fn ($t) => ['type' => 'TextualBody', 'value' => trim($t->title.($t->description ? ': '.$t->description : '')), 'format' => 'text/plain', 'language' => $t->language])->values()->all();
                if ($hotspot->link_url) {
                    $body[] = ['id' => $hotspot->link_url, 'type' => 'Text'];
                }

                return ['id' => $id.'/hotspot/'.$hotspot->id, 'type' => 'Annotation', 'motivation' => $hotspot->link_url ? 'linking' : 'commenting', 'body' => $body,
                    'target' => ['type' => 'SpecificResource', 'source' => $canvas, 'selector' => ['type' => 'PointSelector', 'x' => $hotspot->position_x, 'y' => $hotspot->position_y, 'z' => $hotspot->position_z]]];
            })->filter()->values()->all();
        if ($annotations !== []) {
            $manifest['annotations'] = [['id' => $id.'/annotations/1', 'type' => 'AnnotationPage', 'items' => $annotations]];
        }
        // How the Workspace shows it, for a viewer that wants it (not part of IIIF).
        $manifest['openricx:viewer'] = ['autoRotate' => (bool) $settings['auto_rotate'], 'rotationSpeed' => (int) $settings['rotation_speed'], 'cameraOrbit' => $settings['camera_orbit'],
            'backgroundColor' => $settings['background_color'], 'arEnabled' => (bool) $settings['ar_enabled']];

        return $manifest;
    }

    /** The canvas's extent from the model's bounding box (min x,y,z max x,y,z), rounded up and at least 1. */
    private function extent(string $box): array
    {
        if (preg_match('/^\s*(-?[\d.eE+]+),(-?[\d.eE+]+),(-?[\d.eE+]+)\s+(-?[\d.eE+]+),(-?[\d.eE+]+),(-?[\d.eE+]+)\s*$/', $box, $m)) {
            return [max(1, (int) ceil(abs((float) $m[4] - (float) $m[1]))), max(1, (int) ceil(abs((float) $m[5] - (float) $m[2]))), max(1, (int) ceil(abs((float) $m[6] - (float) $m[3])))];
        }

        return [1000, 1000, 1000];
    }

    private function metadata(array $p): array
    {
        $pairs = [[__('Format'), $p['format_version'] ?? strtoupper((string) ($p['format'] ?? ''))], [__('Vertices'), isset($p['vertex_count']) ? number_format((int) $p['vertex_count']) : null],
            [__('Faces'), isset($p['face_count']) ? number_format((int) $p['face_count']) : null], [__('Compression'), $p['compression'] ?? null]];

        return collect($pairs)->filter(fn ($pair) => $pair[1] !== null && $pair[1] !== '')
            ->map(fn ($pair) => ['label' => ['en' => [$pair[0]]], 'value' => ['none' => [(string) $pair[1]]]])->values()->all();
    }

    /** An element's published values, as a IIIF language map. */
    private function languages(Instantiation $instantiation, string $notation): array
    {
        $published = Concept::where('iri', Vocab::valueState('published'))->value('id');

        return $instantiation->names->merge($instantiation->descriptionValues)
            ->filter(fn ($v) => $v->state_id === $published && (($v->element?->notation ?? null) === $notation || ($v->nameType?->notation ?? null) === 'authorised'))
            ->groupBy('language')->map(fn ($values) => $values->pluck('value')->unique()->values()->all())->all();
    }
}
