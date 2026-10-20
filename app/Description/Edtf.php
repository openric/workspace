<?php

// OpenRiC Workspace - check an EDTF date and find its earliest and latest day.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The normalised value of a rico:Date (rico:normalizedDateValue) is written in
// the Extended Date/Time Format (Library of Congress EDTF, incorporated in
// ISO 8601-2:2019). Supported: level 0 (1902, 1902-03, 1902-03-15, and
// intervals a/b), and from level 1 the qualifiers ? ~ % after a date,
// unspecified digits (19XX, 1902-XX), and unknown or open interval ends
// (/1950, 1902/, ../1950, 1902/..).
// ponytail: no seasons (21-24), no extended years (Y...), no level 2; add them
// when a description needs one.

namespace App\Description;

use DateTimeImmutable;

final class Edtf
{
    private const DATE = '/^(?<y>-?[0-9X]{4})(?:-(?<m>[0-9X]{2})(?:-(?<d>[0-9X]{2}))?)?(?<q>[?~%])?$/';

    public static function valid(string $value): bool
    {
        return self::bounds($value) !== null;
    }

    /**
     * The earliest and latest day the value can mean, as Y-m-d; null where an
     * interval end is unknown or open. Returns null if the value is not valid EDTF.
     *
     * @return array{0: ?string, 1: ?string}|null
     */
    public static function bounds(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }
        if (str_contains($value, '/')) {
            [$start, $end] = explode('/', $value, 2);
            if (str_contains($end, '/') || ($start === '' && $end === '') || (in_array($start, ['', '..'], true) && in_array($end, ['', '..'], true))) {
                return null;
            }
            $from = in_array($start, ['', '..'], true) ? [null, null] : self::single($start);
            $to = in_array($end, ['', '..'], true) ? [null, null] : self::single($end);
            if ($from === null || $to === null) {
                return null;
            }
            if ($from[0] !== null && $to[1] !== null && $from[0] > $to[1]) {
                return null;
            }

            return [$from[0], $to[1]];
        }

        return self::single($value);
    }

    /** @return array{0: string, 1: string}|null */
    private static function single(string $value): ?array
    {
        if (! preg_match(self::DATE, $value, $m)) {
            return null;
        }
        $year = $m['y'];
        $month = $m['m'] ?? '';
        $day = $m['d'] ?? '';
        // Unspecified digits may only run to the right: 19XX, 1902-XX, never 1X02.
        if (preg_match('/X[0-9]/', $year.$month.$day) || (str_contains($year, 'X') && ($month !== '' && ! str_contains($month, 'X')))) {
            return null;
        }
        $negative = str_starts_with($year, '-');
        if ($negative && str_contains($year, 'X')) {
            return null;
        }
        $lowYear = (int) str_replace('X', '0', $year);
        $highYear = (int) str_replace('X', '9', $year);
        [$lowMonth, $highMonth] = $month === '' || $month === 'XX' ? [1, 12] : [(int) $month, (int) $month];
        if ($lowMonth < 1 || $highMonth > 12) {
            return null;
        }
        if ($day === '' || $day === 'XX') {
            $lowDay = 1;
            $highDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', max($highYear, 1), $highMonth)))->format('t');
        } else {
            $lowDay = $highDay = (int) $day;
            if (! checkdate($lowMonth, $lowDay, max($lowYear, 1))) {
                return null;
            }
        }

        return [self::ymd($lowYear, $lowMonth, $lowDay), self::ymd($highYear, $highMonth, $highDay)];
    }

    private static function ymd(int $year, int $month, int $day): string
    {
        return ($year < 0 ? '-' : '').sprintf('%04d-%02d-%02d', abs($year), $month, $day);
    }
}
