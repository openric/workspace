<?php

/**
 * MetadataExtractionService - Service for Heratio
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
 * OpenRiC Workspace - ported from Heratio, packages/ahg-metadata-extraction/src/Services/MetadataExtractionService.php
 * at commit 011766951 (governance/CODE_PROVENANCE.md): runExifTool, extractNormalised (its source
 * priority, XMP before IPTC before EXIF before PDF), normaliseIsoDate and cleanString. Changed:
 * exiftool alone reads every kind of file, so PHP's exif_read_data, iptcparse and the hand-made
 * XMP and PDF readers, and ffprobe, are not copied; exiftool's own and the file system's groups
 * (paths, permissions) are dropped, and so are binary values. Nothing is written to the
 * description: the normalised values become proposals (ADR 0007), so applyToSector and its
 * per-sector mappings are not copied, and the IPTC, XMP and GPS switches are not needed, since
 * nothing reaches the record unreviewed and the raw values are shown only to those who may
 * receive the master. normaliseIsoDate does not guess at other wordings with strtotime: a wrong
 * date proposed is worse than none.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Files;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class EmbeddedMetadata
{
    /** exiftool groups that describe the copy on this server or exiftool itself, not the file as made. */
    private const DROPPED_GROUPS = ['System', 'ExifTool', 'SourceFile'];

    /**
     * Where each normalised value is read from, first found first (exiftool -G1 tag names).
     *
     * @var array<string, list<string>>
     */
    private const SOURCES = [
        'title' => ['XMP-dc:Title', 'IPTC:ObjectName', 'IPTC:Headline', 'PDF:Title', 'IFD0:ImageDescription'],
        'creator' => ['XMP-dc:Creator', 'IPTC:By-line', 'IFD0:Artist', 'PDF:Author'],
        'date' => ['ExifIFD:DateTimeOriginal', 'XMP-exif:DateTimeOriginal', 'XMP-xmp:CreateDate', 'IPTC:DateCreated', 'PDF:CreateDate', 'IFD0:ModifyDate'],
        'description' => ['XMP-dc:Description', 'IPTC:Caption-Abstract', 'PDF:Subject', 'ExifIFD:UserComment'],
        'copyright' => ['XMP-dc:Rights', 'IPTC:CopyrightNotice', 'IFD0:Copyright'],
        'keywords' => ['XMP-dc:Subject', 'IPTC:Keywords', 'PDF:Keywords'],
        'technical' => ['IFD0:Make', 'IFD0:Model', 'PDF:Producer', 'XMP-xmp:CreatorTool'],
    ];

    /**
     * Everything exiftool reads from the file, tag => value, without the server's own facts.
     *
     * @return array<string, string>
     */
    public function extract(string $filePath): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }
        if (! $this->isExifToolAvailable()) {
            throw new RuntimeException('exiftool is not installed. Install with: sudo apt install libimage-exiftool-perl');
        }
        $process = new Process(['exiftool', '-json', '-a', '-G1', '-charset', 'filename=utf8', $filePath]);
        $process->setTimeout(120)->run();
        $data = json_decode($process->getOutput(), true);
        if (! is_array($data[0] ?? null)) {
            Log::warning('exiftool gave nothing to read', ['code' => $process->getExitCode(), 'error' => mb_substr($process->getErrorOutput(), 0, 300)]);

            return [];
        }
        $out = [];
        foreach ($data[0] as $tag => $value) {
            if (in_array(strtok((string) $tag, ':'), self::DROPPED_GROUPS, true)) {
                continue;
            }
            $value = is_array($value) ? implode('; ', array_map(fn ($v) => $this->cleanString($v), $value)) : $this->cleanString($value);
            if ($value === '' || str_starts_with($value, '(Binary data') || str_contains($value, 'use -b option to extract')) {
                continue;
            }
            $out[(string) $tag] = mb_substr($value, 0, 4000);
        }

        return $out;
    }

    /**
     * The values of the file that a description can use, each with the tag it came from.
     *
     * @param  array<string, string>  $raw  what extract() gives
     * @return array<string, array{value: string, tag: string}>
     */
    public function normalise(array $raw): array
    {
        $out = [];
        foreach (self::SOURCES as $field => $tags) {
            foreach ($tags as $tag) {
                if (($raw[$tag] ?? '') !== '') {
                    $value = $field === 'date' ? $this->normaliseIsoDate($raw[$tag]) : $raw[$tag];
                    if ($value !== null) {
                        $out[$field] = ['value' => $value, 'tag' => $tag];

                        break;
                    }
                }
            }
        }

        return $out;
    }

    public function isExifToolAvailable(): bool
    {
        return (new ExecutableFinder)->find('exiftool') !== null;
    }

    /** A date as YYYY-MM-DD, or YYYY for a year alone; null if it cannot be read. */
    public function normaliseIsoDate(string $value): ?string
    {
        $value = trim($value);
        // EXIF: 2021:02:05 15:04:54; ISO: 2021-02-05 or 2021-02-05T15:04:54. A camera with no clock set gives 0000:00:00.
        if (preg_match('/^(\d{4})[:-](\d{2})[:-](\d{2})/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && $m[1] !== '0000' ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        // Heratio makes a year alone the first of January; here it stays a year.
        if (preg_match('/^(\d{4})$/', $value, $m)) {
            return $m[1];
        }

        return null;
    }

    private function cleanString(mixed $value): string
    {
        $value = (string) $value;
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return trim($value);
    }
}
