<?php

// OpenRiC Workspace - built reports scheduled, archived, shared and versioned (roadmap phase 8).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Mail\ScheduledReportMail;
use App\Models\CustomReport;
use App\Models\Institution;
use App\Models\ReportArchive;
use App\Models\ReportSchedule;
use App\Models\ReportShare;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Reports\ReportDelivery;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $editor;

    private CustomReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['workspace.timezone' => 'Africa/Johannesburg']);
        $this->loadBundledVocabularies();
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        app(RecordService::class)->create($this->institution, Vocab::BASE.'level-of-description/fonds', 'Council records', 'en', null, $holder, 'C1');
        $this->actingAs($this->editor)->post('/reports/builder', ['template' => ReportTemplate::where('name', 'Holdings summary')->value('id'), 'name' => 'Holdings']);
        $this->report = CustomReport::where('name', 'Holdings')->firstOrFail();
    }

    public function test_the_next_run_follows_the_frequency_in_the_workspace_time_zone(): void
    {
        $delivery = app(ReportDelivery::class);
        // Tuesday 28 October 2026, 10:00 in Johannesburg (08:00 UTC).
        $now = CarbonImmutable::parse('2026-10-28 08:00:00', 'UTC');
        $this->assertSame('2026-10-29 06:00', $delivery->nextRun('daily', null, null, '08:00', $now)->format('Y-m-d H:i'), 'today 08:00 local has passed');
        $this->assertSame('2026-10-28 10:30', $delivery->nextRun('daily', null, null, '12:30', $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-02 06:00', $delivery->nextRun('weekly', 1, null, '08:00', $now)->format('Y-m-d H:i'), 'the next Monday');
        $this->assertSame('2026-10-31 06:00', $delivery->nextRun('monthly', null, 31, '08:00', $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-30 06:00', $delivery->nextRun('monthly', null, 31, '08:00', CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'))->format('Y-m-d H:i'), 'November has 30 days');
        $this->assertSame('2027-01-15 06:00', $delivery->nextRun('quarterly', null, 15, '08:00', $now)->format('Y-m-d H:i'));
    }

    public function test_a_schedule_makes_keeps_and_sends_the_report_to_accounts_of_the_institution_only(): void
    {
        Mail::fake();
        $reader = app(AccountService::class)->create($this->institution, 'Reader', 'reader@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($reader, 'editor');
        $elsewhere = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        $stranger = User::create(['institution_id' => $elsewhere->id, 'name' => 'Stranger', 'email' => 'stranger@example.org', 'password' => 'correct horse battery staple']);

        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/schedules", ['frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'pdf',
            'recipients' => [$stranger->id]])->assertSessionHasErrors('schedule');
        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/schedules", ['frequency' => 'weekly', 'day_of_week' => 5, 'time_of_day' => '08:00', 'format' => 'xlsx',
            'recipients' => [$this->editor->id, $reader->id, $stranger->id]])->assertRedirect();
        $schedule = ReportSchedule::firstOrFail();
        $this->assertSame([$this->editor->id, $reader->id], $schedule->recipients);
        $this->assertTrue($schedule->next_run_at->isFuture());

        // Not yet due, nothing is made; once due, it is made, kept and sent, and the next run moves on a week.
        $this->artisan('workspace:reports:run-scheduled')->expectsOutputToContain('0 scheduled reports made')->assertSuccessful();
        $schedule->update(['next_run_at' => now()->subMinute()]);
        $this->artisan('workspace:reports:run-scheduled')->expectsOutputToContain('1 scheduled reports made, 0 failed')->assertSuccessful();
        $archive = ReportArchive::firstOrFail();
        $this->assertSame(['xlsx', $schedule->id, 1], [$archive->format, $archive->schedule_id, $archive->entities]);
        Storage::disk('local')->assertExists($archive->path);
        Mail::assertQueued(ScheduledReportMail::class, 2);
        Mail::assertQueued(ScheduledReportMail::class, fn ($mail) => $mail->hasTo('reader@example.org') && $mail->archive->is($archive));
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
        $this->assertSame(5, $schedule->fresh()->next_run_at->setTimezone('Africa/Johannesburg')->isoWeekday());

        // The kept file downloads from the report; a file is also made on demand.
        $this->actingAs($this->editor)->get("/reports/builder/{$this->report->id}")->assertOk()->assertSee('XLSX')->assertSee('scheduled');
        $this->actingAs($this->editor)->get("/reports/builder/{$this->report->id}/archive/{$archive->id}")->assertOk()->assertDownload();
        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/archive", ['format' => 'pdf'])->assertRedirect();
        $this->assertSame(2, ReportArchive::count());

        // Removing the report removes its files.
        $paths = ReportArchive::pluck('path')->all();
        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/remove")->assertRedirect();
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertSame(0, ReportSchedule::count());
    }

    public function test_a_share_link_opens_the_report_without_signing_in_until_it_expires_or_is_revoked(): void
    {
        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/shares", ['days' => 400])->assertSessionHasErrors('days');
        $link = $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/shares", ['days' => 7])->assertRedirect()->getSession()->get('share_link');
        $token = substr($link, strrpos($link, '/') + 1);
        $share = ReportShare::firstOrFail();
        $this->assertNotSame($token, $share->token, 'only the hash is kept');

        auth()->logout();
        $this->get("/shared/reports/$token")->assertOk()->assertSee('Holdings')->assertSee('By repository')->assertSee('Riverside Archives')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame(1, $share->fresh()->opened);
        $this->get('/shared/reports/'.str_repeat('a', 64))->assertNotFound();

        $share->update(['expires_at' => now()->subMinute()]);
        $this->get("/shared/reports/$token")->assertNotFound();
        $share->update(['expires_at' => now()->addDay()]);
        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/shares/{$share->id}/revoke")->assertRedirect();
        auth()->logout();
        $this->get("/shared/reports/$token")->assertNotFound();
    }

    public function test_a_version_is_kept_and_restored(): void
    {
        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/versions", ['summary' => 'As agreed'])->assertRedirect();
        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}", ['name' => 'Changed', 'columns' => ['name']]);
        $this->report->sections()->first()->delete();
        $this->assertSame(['Changed', 2], [$this->report->fresh()->name, $this->report->sections()->count()]);

        $version = $this->report->versions()->where('number', 1)->firstOrFail();
        $this->actingAs($this->editor)->post("/reports/builder/{$this->report->id}/versions/{$version->id}/restore")->assertRedirect();
        $report = $this->report->fresh();
        $this->assertSame(['Holdings', 3, ['name', 'reference-code', 'level', 'repository', 'published']], [$report->name, $report->sections()->count(), $report->columns]);
        // What it was before is kept as version 2.
        $this->assertSame('Changed', $report->versions()->where('number', 2)->firstOrFail()->snapshot['name']);

        // Only the owner keeps or restores versions.
        $other = app(AccountService::class)->create($this->institution, 'Other', 'other@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($other, 'editor');
        $this->actingAs($other)->post("/reports/builder/{$this->report->id}/versions/{$version->id}/restore")->assertForbidden();
    }
}
