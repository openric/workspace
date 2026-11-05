<?php

/**
 * OcrService - production Tesseract wrapper with multi-language support.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - parseTsv() and iso639ToTesseract() copied from Heratio,
 * packages/ahg-ai-services/src/Services/OcrService.php at commit 9c5d49d25f738dc1dad63f1d78101c70bcbe08e8
 * (governance/CODE_PROVENANCE.md, roadmap phase 17a). Changed: only Tesseract's words and their boxes are kept, as the
 * vision reader's cross-check, never as the text; run through Laravel's Process rather than a shell string; the
 * languages come from the description's language; no PREMIS event or persistence here.
 */

declare(strict_types=1);

namespace App\Ai\Ocr;

use Illuminate\Support\Facades\Process;

class Tesseract
{
    public function available(): bool
    {
        return Process::run(['tesseract', '--version'])->successful();
    }

    public function version(): string
    {
        $run = Process::run(['tesseract', '--version']);

        return $run->successful() ? trim(strtok($run->output()."\n", "\n")) : '';
    }

    /**
     * Tesseract's words on the page, with their boxes in pixels, read in the given languages (BCP 47 or ISO 639);
     * none where Tesseract is missing or fails, so the vision model's reading then goes unchecked.
     *
     * @return array{text: string, words: list<array{text: string, x: int, y: int, width: int, height: int, confidence: float}>, mean_confidence: ?float}
     */
    public function words(string $imagePath, array $languages = ['en']): array
    {
        $installed = $this->installed();
        $tags = array_values(array_unique(array_filter(array_map(fn ($l) => $this->iso639ToTesseract(strtolower(explode('-', (string) $l)[0])), $languages),
            fn ($t) => in_array($t, $installed, true)))) ?: ['eng'];
        $run = Process::timeout(300)->run(['tesseract', $imagePath, 'stdout', '-l', implode('+', $tags), '--psm', '3', 'tsv']);

        return $run->successful() ? $this->parseTsv($run->output()) : ['text' => '', 'words' => [], 'mean_confidence' => null];
    }

    /** @return list<string> */
    private function installed(): array
    {
        $run = Process::run(['tesseract', '--list-langs']);

        return $run->successful() ? array_slice(array_map('trim', explode("\n", trim($run->output()))), 1) : [];
    }

    /**
     * Parse Tesseract TSV output into word boxes + recombined text.
     *
     * @return array{text:string,words:array,mean_confidence:?float}
     */
    public function parseTsv(string $tsv): array
    {
        $lines = preg_split('/\R+/', trim($tsv));
        $words = [];
        $confSum = 0.0;
        $confN = 0;
        $textParts = [];
        $currentLine = '';
        $lastLineKey = null;
        if ($lines === false || count($lines) < 2) {
            return ['text' => '', 'words' => [], 'mean_confidence' => null];
        }
        // Header line tells us column ordering; pin to default Tesseract TSV.
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) < 12) {
                continue;
            }
            // level=cols[0]; 5 == WORD per Tesseract docs.
            if ((int) $cols[0] !== 5) {
                continue;
            }
            $word = trim($cols[11]);
            if ($word === '') {
                continue;
            }
            $conf = (float) $cols[10];
            if ($conf < 0) {
                $conf = 0.0;
            }
            $words[] = [
                'text' => $word,
                'x' => (int) $cols[6],
                'y' => (int) $cols[7],
                'width' => (int) $cols[8],
                'height' => (int) $cols[9],
                'confidence' => $conf,
            ];
            $confSum += $conf;
            $confN++;
            $lineKey = $cols[1].'/'.$cols[2].'/'.$cols[3].'/'.$cols[4];
            if ($lastLineKey !== null && $lineKey !== $lastLineKey) {
                $textParts[] = $currentLine;
                $currentLine = '';
            }
            $currentLine .= ($currentLine === '' ? '' : ' ').$word;
            $lastLineKey = $lineKey;
        }
        if ($currentLine !== '') {
            $textParts[] = $currentLine;
        }

        return [
            'text' => implode("\n", $textParts),
            'words' => $words,
            'mean_confidence' => $confN ? round($confSum / $confN, 2) : null,
        ];
    }

    /**
     * Map an ISO 639-1 / -2 code (from information_object_i18n.language)
     * to a Tesseract trained-data tag. Only the SADC + EU core set is
     * tabulated; unknown codes fall through unchanged so operators can
     * install custom traineddata and have it accepted by Tesseract.
     */
    public function iso639ToTesseract(string $iso): string
    {
        static $map = [
            'en' => 'eng', 'eng' => 'eng',
            'af' => 'afr', 'afr' => 'afr',
            'nl' => 'nld', 'nld' => 'nld', 'dut' => 'nld',
            'zu' => 'zul', 'zul' => 'zul',
            'xh' => 'xho', 'xho' => 'xho',
            'st' => 'sot', 'sot' => 'sot',
            'tn' => 'tsn', 'tsn' => 'tsn',
            'ts' => 'tso', 'tso' => 'tso',
            've' => 'ven', 'ven' => 'ven',
            'nr' => 'nbl', 'nbl' => 'nbl',
            'ss' => 'ssw', 'ssw' => 'ssw',
            'nso' => 'nso',
            'sn' => 'sna', 'sna' => 'sna',
            'pt' => 'por', 'por' => 'por',
            'fr' => 'fra', 'fra' => 'fra', 'fre' => 'fra',
            'de' => 'deu', 'deu' => 'deu', 'ger' => 'deu',
            'es' => 'spa', 'spa' => 'spa',
            'it' => 'ita', 'ita' => 'ita',
            'la' => 'lat', 'lat' => 'lat',
        ];
        $code = strtolower($iso);

        return $map[$code] ?? $iso;
    }
}
