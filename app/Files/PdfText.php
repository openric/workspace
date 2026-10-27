<?php

/**
 * PdfTextExtractService - Service for Heratio
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
 * OpenRiC Workspace - ported from Heratio, packages/ahg-pdf-tools/src/Services/PdfTextExtractService.php
 * at commit 011766951 (governance/CODE_PROVENANCE.md): extractText, isPdftotextAvailable and
 * getPdftotextVersion. Changed: pdftotext runs as a Process held to a time limit instead of
 * through exec and a shell; its page breaks are kept as a blank line; the text is not stored in
 * AtoM's property table but proposed as the instantiation's transcript (roadmap phase 5), so
 * storeExtractedText, getExtractedText, batchExtract and getStatistics are not copied
 * (workspace:files:extract-text does the batch).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Files;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PdfText
{
    /**
     * The text of a PDF's own text layer, or null when it has none (a scan without OCR) or
     * pdftotext cannot read it.
     */
    public function extractText(string $pdfPath): ?string
    {
        if (! is_file($pdfPath)) {
            throw new RuntimeException("PDF file not found: {$pdfPath}");
        }
        if (! $this->isPdftotextAvailable()) {
            throw new RuntimeException('pdftotext is not installed. Install with: sudo apt install poppler-utils');
        }
        $process = new Process(['pdftotext', '-enc', 'UTF-8', $pdfPath, '-']);
        $process->setTimeout(300)->run();
        if (! $process->isSuccessful()) {
            Log::warning('pdftotext failed', ['path' => $pdfPath, 'code' => $process->getExitCode(), 'error' => mb_substr($process->getErrorOutput(), 0, 300)]);

            return null;
        }
        // A form feed ends each page; a page of the scanned kind gives only whitespace.
        $pages = array_map(fn ($page) => trim(preg_replace("/[ \t]+\n/", "\n", $page)), explode("\f", $process->getOutput()));
        $text = trim(implode("\n\n", array_filter($pages, fn ($page) => $page !== '')));

        return $text !== '' && mb_check_encoding($text, 'UTF-8') ? $text : null;
    }

    public function isPdftotextAvailable(): bool
    {
        return (new ExecutableFinder)->find('pdftotext') !== null;
    }

    public function getPdftotextVersion(): ?string
    {
        if (! $this->isPdftotextAvailable()) {
            return null;
        }
        $process = new Process(['pdftotext', '-v']);
        $process->run();

        return preg_match('/(\d+\.\d+\.\d+)/', $process->getErrorOutput().$process->getOutput(), $m) ? $m[1] : null;
    }
}
