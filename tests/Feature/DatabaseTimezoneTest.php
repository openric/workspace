<?php

// OpenRiC Workspace - the database session is in UTC, as the application is, so a time the application writes is the
// time stored, whatever the server's own zone; and a weekly report falls due in the future at any hour of its day.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Models\Institution;
use App\Reports\ReportDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_session_is_utc_and_a_time_written_is_the_time_stored(): void
    {
        $this->assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
        $written = CarbonImmutable::parse('2026-10-30 05:07:40', 'UTC');
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        $institution->forceFill(['created_at' => $written])->save();
        $this->assertSame('2026-10-30T05:07:40+00:00', $institution->fresh()->created_at->toIso8601String());
        $this->assertSame('2026-10-30 05:07:40+00', DB::table('institutions')->where('id', $institution->id)->value('created_at'));
    }

    public function test_a_weekly_schedule_on_its_own_day_before_its_hour_falls_due_later_that_day(): void
    {
        // Friday 30 October 2026, 05:07 UTC (07:07 in Johannesburg): Friday 08:00 local is 06:00 UTC, still to come.
        CarbonImmutable::setTestNow('2026-10-30 05:07:40');
        $next = app(ReportDelivery::class)->nextRun('weekly', 5, null, '08:00', CarbonImmutable::now());
        $this->assertSame('2026-10-30 06:00', $next->format('Y-m-d H:i'));
        $this->assertTrue($next->isFuture());
        CarbonImmutable::setTestNow();
    }
}
