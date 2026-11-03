<?php

// OpenRiC Workspace - Code 128 barcodes drawn as SVG on the server (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio's ahg-label fetched each barcode from an outside service (barcodeapi.org), sending it every identifier it
// printed. The Workspace draws them itself: ISO/IEC 15417 code set B for printable ASCII, and code set C for a run of
// an even number of digits (four or more), which halves its width, as box numbers often are. Each symbol is the bar and
// space widths of the standard's table; the check symbol is the weighted sum modulo 103.

namespace App\Labels;

use InvalidArgumentException;

class Code128
{
    /** Bar, space, bar, space, bar, space widths of symbols 0 to 106 (106 is the stop, with its final bar). */
    public const PATTERNS = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232',
        '122132', '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212',
        '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133',
        '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113',
        '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411',
        '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131',
        '211412', '211214', '211232', '2331112'];

    private const START_B = 104;

    private const START_C = 105;

    private const CODE_B = 100;

    private const CODE_C = 99;

    private const STOP = 106;

    /** @return list<int> the symbol values, start to check, without the stop */
    public static function symbols(string $text): array
    {
        ($text !== '' && preg_match('/^[\x20-\x7E]+$/', $text)) || throw new InvalidArgumentException(__('A Code 128 barcode holds printable ASCII only.'));
        $digitsAt = fn (int $i) => strspn($text, '0123456789', $i);
        $set = $digitsAt(0) >= 4 && $digitsAt(0) % 2 === 0 ? 'C' : 'B';
        $symbols = [$set === 'C' ? self::START_C : self::START_B];
        for ($i = 0, $n = strlen($text); $i < $n;) {
            $run = $digitsAt($i);
            if ($set === 'B' && $run >= 4) {
                // A run of four or more digits goes in set C, an odd one leaving its first digit in B.
                if ($run % 2 === 1) {
                    $symbols[] = ord($text[$i++]) - 32;
                    $run--;
                }
                $symbols[] = self::CODE_C;
                $set = 'C';
            }
            if ($set === 'C') {
                if ($run >= 2) {
                    $symbols[] = (int) substr($text, $i, 2);
                    $i += 2;

                    continue;
                }
                $symbols[] = self::CODE_B;
                $set = 'B';
            }
            $symbols[] = ord($text[$i++]) - 32;
        }
        $check = $symbols[0];
        foreach (array_slice($symbols, 1) as $position => $value) {
            $check += ($position + 1) * $value;
        }
        $symbols[] = $check % 103;

        return $symbols;
    }

    /** The barcode as SVG, with the quiet zones the standard asks for (ten modules each side) and the text under it. */
    public static function svg(string $text, float $height = 40, float $module = 1, bool $caption = true): string
    {
        $widths = implode('', array_map(fn ($s) => self::PATTERNS[$s], [...self::symbols($text), self::STOP]));
        [$x, $rects] = [10 * $module, ''];
        foreach (str_split($widths) as $n => $width) {
            $w = (int) $width * $module;
            if ($n % 2 === 0) {
                $rects .= sprintf('<rect x="%s" y="0" width="%s" height="%s"/>', $x, $w, $height);
            }
            $x += $w;
        }
        $total = $x + 10 * $module;
        $text = htmlspecialchars($text, ENT_XML1);
        $label = $caption ? sprintf('<text x="%s" y="%s" font-family="DejaVu Sans Mono, monospace" font-size="%s" text-anchor="middle">%s</text>', $total / 2, $height + 11 * $module, 10 * $module, $text) : '';

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s" role="img" aria-label="%s"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g>%s</svg>',
            $total, $height + ($caption ? 14 * $module : 0), $text, $rects, $label);
    }
}
