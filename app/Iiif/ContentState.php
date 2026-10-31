<?php

// OpenRiC Workspace - IIIF Content State 1.0 (roadmap phase 14a, ADR 0014): a link to a place in a manifest (the
// manifest, a canvas, a region of it) as an annotation, in URL-safe base64 without padding, so a reader can send
// someone to the same page and the same spot.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Copied from Heratio's IiifContentStateService: the annotation and the encoding are the specification's. Changed:
// the annotation has no id of its own, which the specification allows, instead of an example.com IRI, and decoding
// accepts only an annotation whose target is in this Workspace's manifests, so the viewer opens nothing else.

namespace App\Iiif;

class ContentState
{
    /** The token for a manifest, a canvas of it, and a region of the canvas (xywh=x,y,w,h). */
    public function encode(string $manifest, ?string $canvas = null, ?string $region = null): string
    {
        $source = $canvas ? ['id' => $canvas, 'type' => 'Canvas', 'partOf' => [['id' => $manifest, 'type' => 'Manifest']]] : ['id' => $manifest, 'type' => 'Manifest'];
        $target = $region ? ['type' => 'SpecificResource', 'source' => $source, 'selector' => ['type' => 'FragmentSelector', 'conformsTo' => 'http://www.w3.org/TR/media-frags/', 'value' => $region]] : $source;
        $annotation = ['@context' => Presentation::CONTEXT, 'type' => 'Annotation', 'motivation' => ['contentState'], 'target' => $target];

        return rtrim(strtr(base64_encode(json_encode($annotation, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    /**
     * What a token points at: its manifest, canvas and region, when the manifest is one of this Workspace's;
     * else null.
     *
     * @return array{manifest: string, canvas: ?string, region: ?string}|null
     */
    public function decode(string $token): ?array
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $annotation = $json === false ? null : json_decode($json, true);
        $target = is_array($annotation) ? ($annotation['target'] ?? null) : null;
        if (! is_array($target)) {
            return null;
        }
        $source = ($target['type'] ?? null) === 'SpecificResource' ? ($target['source'] ?? []) : $target;
        $type = $source['type'] ?? null;
        $manifest = $type === 'Canvas' ? ($source['partOf'][0]['id'] ?? null) : ($type === 'Manifest' ? ($source['id'] ?? null) : null);
        if (! is_string($manifest) || ! str_starts_with($manifest, url('/iiif/records/'))) {
            return null;
        }
        $region = $target['selector']['value'] ?? null;

        return ['manifest' => $manifest, 'canvas' => $type === 'Canvas' ? (string) $source['id'] : null,
            'region' => is_string($region) && preg_match('/^xywh=\d+,\d+,\d+,\d+$/', $region) ? $region : null];
    }
}
