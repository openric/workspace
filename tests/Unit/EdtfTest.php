<?php

// OpenRiC Workspace - EDTF dates (ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit;

use App\Description\Edtf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EdtfTest extends TestCase
{
    public static function validDates(): array
    {
        return [
            'year' => ['1902', ['1902-01-01', '1902-12-31']],
            'month' => ['1902-02', ['1902-02-01', '1902-02-28']],
            'leap month' => ['1904-02', ['1904-02-01', '1904-02-29']],
            'day' => ['1902-03-15', ['1902-03-15', '1902-03-15']],
            'approximate' => ['1902~', ['1902-01-01', '1902-12-31']],
            'uncertain day' => ['1902-03-15?', ['1902-03-15', '1902-03-15']],
            'decade' => ['190X', ['1900-01-01', '1909-12-31']],
            'century' => ['19XX', ['1900-01-01', '1999-12-31']],
            'unknown month' => ['1902-XX', ['1902-01-01', '1902-12-31']],
            'interval' => ['1902/1950', ['1902-01-01', '1950-12-31']],
            'interval of days' => ['1902-03-15/1902-04-01', ['1902-03-15', '1902-04-01']],
            'open end' => ['1902/..', ['1902-01-01', null]],
            'unknown start' => ['/1950', [null, '1950-12-31']],
            'open start' => ['../1950-06', [null, '1950-06-30']],
            'approximate interval' => ['1902~/1950?', ['1902-01-01', '1950-12-31']],
        ];
    }

    #[DataProvider('validDates')]
    public function test_valid_dates_and_their_bounds(string $value, array $bounds): void
    {
        $this->assertTrue(Edtf::valid($value));
        $this->assertSame($bounds, Edtf::bounds($value));
    }

    public static function invalidDates(): array
    {
        return [[''], ['1902-13'], ['1902-02-30'], ['1903-02-29'], ['02'], ['1902-3'], ['1X02'], ['19XX-03'], ['1950/1902'], ['/'], ['../..'],
            ['1902/1950/1960'], ['circa 1902'], ['1902-03-15T10:00'], ['21st century']];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_are_refused(string $value): void
    {
        $this->assertFalse(Edtf::valid($value), "$value should be refused");
    }
}
