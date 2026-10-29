<?php

/**
 * WatermarkService - Service for Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-media-processing/src/Services/WatermarkService.php
 * at commit 011766951 (governance/CODE_PROVENANCE.md): apply() (ImageMagick composite, dissolved
 * by the opacity, at a gravity or tiled), POSITION_MAP, the minimum size, and the default branch of
 * getWatermarkConfig(). Changed: composite runs as a Process held to a time limit instead of
 * through exec and a shell; the watermark is the institution's (its columns, with type and
 * position as concepts) rather than the watermark_setting, watermark_type and custom_watermark
 * tables; Heratio's system types are images in public/images/watermarks, here they are text
 * written by ImageMagick (the copyright sign and the institution's name, Confidential, Draft), and
 * a custom image is the institution's upload. The watermark for one object (object_watermark_setting)
 * is a description's RecordWatermark, read before the institution's as Heratio reads it. Not copied
 * yet: the one a security classification requires, which comes with
 * security clearance in phase 18; the Cantaloupe cache, since the Workspace has no image server.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Files;

use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\RecordWatermark;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class Watermark
{
    /** watermark-position notation => ImageMagick gravity, as Heratio's POSITION_MAP. */
    public const POSITION_MAP = [
        'top-left' => 'NorthWest', 'top-center' => 'North', 'top-right' => 'NorthEast',
        'center-left' => 'West', 'center' => 'Center', 'center-right' => 'East',
        'bottom-left' => 'SouthWest', 'bottom-center' => 'South', 'bottom-right' => 'SouthEast',
        'repeat' => 'Repeat',
    ];

    /**
     * The institution's watermark, or null when it has none: what to write or the image to lay
     * on, where, how strongly, and the smallest image that takes it.
     *
     * @return array{text: ?string, image: ?string, gravity: string, opacity: int, min_size: int}|null
     */
    public function configFor(Institution $institution, ?RecordResource $record = null): ?array
    {
        // A description's own setting first, as Heratio's getWatermarkConfig: off is none; on, its own parts over the institution's.
        $own = $record ? RecordWatermark::where('record_resource_id', $record->id)->with('watermarkType', 'watermarkPosition')->first() : null;
        if ($own && ! $own->enabled) {
            return null;
        }
        $type = $own?->watermarkType?->notation ?? $institution->watermarkType?->notation;
        if ($type === null) {
            return null;
        }
        $image = null;
        if ($type === 'custom') {
            $path = $institution->watermark_image_path;
            if (! $path || ! Storage::disk('local')->exists($path)) {
                return null;
            }
            $image = Storage::disk('local')->path($path);
        }

        return [
            'text' => match ($type) {
                'copyright' => '© '.$institution->name, 'confidential' => 'Confidential', 'draft' => 'Draft', default => null,
            },
            'image' => $image,
            'gravity' => self::POSITION_MAP[$own?->watermarkPosition?->notation ?? $institution->watermarkPosition?->notation ?? 'center'] ?? 'Center',
            'opacity' => (int) ($own?->opacity ?? $institution->watermark_opacity),
            'min_size' => (int) $institution->watermark_min_size,
        ];
    }

    /**
     * Mark the image in place. False, and the image unchanged, when it is smaller than the
     * minimum or ImageMagick fails: a copy without its watermark is still a copy.
     *
     * @param  array{text: ?string, image: ?string, gravity: string, opacity: int, min_size: int}  $config
     */
    public function apply(string $imagePath, array $config): bool
    {
        $size = @getimagesize($imagePath);
        if (! $size || $size[0] < $config['min_size'] || $size[1] < $config['min_size']) {
            return false;
        }
        $mark = $config['image'];
        $temp = null;
        if ($mark === null) {
            // The text, about a quarter of the image wide, white with a dark edge so it reads on light and dark alike.
            $temp = tempnam(sys_get_temp_dir(), 'wsw').'.png';
            $text = new Process(['convert', '-background', 'none', '-fill', 'white', '-stroke', '#333333', '-strokewidth', '1',
                '-font', 'DejaVu-Sans-Bold', '-size', max(80, intdiv($size[0], 4)).'x', 'label:'.$config['text'], $temp]);
            $text->setTimeout(60)->run();
            if (! $text->isSuccessful()) {
                Log::warning('the watermark text could not be drawn', ['error' => mb_substr($text->getErrorOutput(), 0, 300)]);
                @unlink($temp);

                return false;
            }
            $mark = $temp;
        }
        try {
            $place = $config['gravity'] === 'Repeat' ? ['-tile'] : ['-gravity', $config['gravity']];
            // Written back in its own format: a copy's file has no extension to tell ImageMagick.
            $process = new Process(['composite', '-dissolve', (string) $config['opacity'], ...$place, $mark, $imagePath, image_type_to_extension($size[2], false).':'.$imagePath]);
            $process->setTimeout(120)->run();
            if (! $process->isSuccessful()) {
                Log::warning('the watermark could not be applied', ['error' => mb_substr($process->getErrorOutput(), 0, 300)]);

                return false;
            }

            return true;
        } finally {
            if ($temp !== null) {
                @unlink($temp);
                @unlink(substr($temp, 0, -4));
            }
        }
    }
}
