<?php

// OpenRiC Workspace - POPIA governance (roadmap phase 18a, ADR 0016): the Information Officer, the processing register
// with its submission and decision by another, an impact assessment signed off, consent withdrawn, data subject and
// PAIA requests numbered and due by their law, a security compromise reported to the Regulator, the POPIA report that
// says what holds, the jurisdictions and controls, and who may do this work.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Models\ConsentRecord;
use App\Models\ImpactAssessment;
use App\Models\Institution;
use App\Models\PaiaRequest;
use App\Models\PrivacyRequest;
use App\Models\ProcessingActivity;
use App\Models\SecurityCompromise;
use App\Models\User;
use App\Privacy\PrivacyService;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $officer;

    private User $deputy;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['officer' => 'administrator', 'deputy' => 'administrator', 'editor' => 'editor'] as $who => $template) {
            $this->{$who} = app(AccountService::class)->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            app(AccountService::class)->grantTemplateRole($this->{$who}, $template);
        }
    }

    private function add(string $register, array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->officer)->post(route('privacy.store', $register), $data);
    }

    private function step(string $register, $entry, string $step, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->officer)->post(route('privacy.step', [$register, $entry->id, $step]), $data);
    }

    public function test_the_processing_register_is_drafted_submitted_and_decided_by_another(): void
    {
        $this->add('officers', ['name' => 'Dr Johan Pieterse', 'title' => 'Information Officer', 'jurisdiction_id' => 'popia', 'registration_number' => 'IR-2026-123', 'is_active' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->add('processing', ['name' => 'Reading room registration', 'jurisdiction_id' => 'popia', 'purpose' => 'Admit researchers and keep the reading room secure.',
            'lawful_basis_id' => 'public-duty', 'data_subjects' => 'Researchers', 'special_information' => '0'])->assertSessionHasNoErrors();
        $activity = ProcessingActivity::sole();
        $this->assertSame(['draft', 'public-duty'], [$activity->status->notation, $activity->lawfulBasis->notation]);
        $this->add('processing', ['name' => 'Incomplete', 'jurisdiction_id' => 'popia'])->assertSessionHasErrors('privacy');

        $this->step('processing', $activity, 'submit')->assertSessionHasNoErrors();
        $this->step('processing', $activity, 'approve')->assertSessionHasErrors('privacy');
        $this->step('processing', $activity, 'reject', [], $this->deputy)->assertSessionHasErrors('privacy');
        $this->step('processing', $activity, 'approve', [], $this->deputy)->assertSessionHasNoErrors();
        $this->assertSame(['approved', $this->deputy->id], [$activity->fresh()->status->notation, $activity->fresh()->decided_by_id]);

        $report = collect(app(PrivacyService::class)->report($this->institution))->pluck('ok', 'check');
        $this->assertTrue($report['An Information Officer is appointed and registered with the Regulator (POPIA s.55, PAIA s.17)']);
        $this->assertTrue($report['The processing of personal information is documented (POPIA s.17)']);
        $this->actingAs($this->officer)->get(route('privacy.index'))->assertOk()->assertSee('IR-2026-123');
    }

    public function test_an_impact_assessment_is_signed_off_once_it_has_its_measures_and_consent_is_withdrawn(): void
    {
        $this->add('processing', ['name' => 'Oral history interviews', 'jurisdiction_id' => 'popia', 'purpose' => 'Record and keep interviews.', 'lawful_basis_id' => 'consent',
            'special_information' => '1'])->assertSessionHasNoErrors();
        $activity = ProcessingActivity::sole();
        $this->assertFalse(collect(app(PrivacyService::class)->report($this->institution))->firstWhere('check', 'Processing with special or children\'s information, or marked for it, has a signed-off impact assessment')['ok']);
        $this->add('assessments', ['name' => 'Oral history PIA', 'processing_activity_id' => $activity->id, 'risks' => 'Health and beliefs of interviewees.'])->assertSessionHasNoErrors();
        $assessment = ImpactAssessment::sole();
        $this->step('assessments', $assessment, 'sign-off')->assertSessionHasErrors('privacy');
        $this->actingAs($this->officer)->post(route('privacy.update', ['assessments', $assessment->id]), ['name' => 'Oral history PIA', 'processing_activity_id' => $activity->id,
            'measures' => 'Closed for 25 years unless the interviewee agrees.'])->assertSessionHasNoErrors();
        $this->step('assessments', $assessment, 'sign-off')->assertSessionHasNoErrors();
        $this->assertSame('signed-off', $assessment->fresh()->status->notation);
        $this->assertTrue(collect(app(PrivacyService::class)->report($this->institution))->firstWhere('check', 'Processing with special or children\'s information, or marked for it, has a signed-off impact assessment')['ok']);

        $this->add('consent', ['data_subject' => 'Mrs A. Botha', 'purpose' => 'Interview recorded and kept', 'processing_activity_id' => $activity->id, 'given_at' => '2026-10-01T10:00'])->assertSessionHasNoErrors();
        $consent = ConsentRecord::sole();
        $this->step('consent', $consent, 'withdraw')->assertSessionHasNoErrors();
        $this->assertNotNull($consent->fresh()->withdrawn_at);
        $this->step('consent', $consent, 'withdraw')->assertSessionHasErrors('privacy');
    }

    public function test_requests_are_numbered_and_due_by_their_law(): void
    {
        $this->add('requests', ['jurisdiction_id' => 'popia', 'type_id' => 'access', 'requester_name' => 'Mr P. de Wet', 'received_on' => '2026-10-01', 'priority_id' => 'high',
            'notes' => 'Asked by telephone first.'])->assertSessionHasNoErrors();
        $this->add('requests', ['jurisdiction_id' => 'ccpa', 'type_id' => 'deletion', 'requester_name' => 'Ms Lee', 'received_on' => '2026-10-01'])->assertSessionHasNoErrors();
        [$first, $second] = PrivacyRequest::orderBy('reference')->get()->all();
        $this->assertMatchesRegularExpression('/^DSR-\d{4}-0001$/', $first->reference);
        $this->assertSame(['high', 'Asked by telephone first.'], [$first->priority->notation, $first->notes], 'the fields of Heratio\'s privacy_dsar the form audit found missing');
        $this->assertSame(['received', '2026-10-31', '2026-11-15'], [$first->status->notation, $first->due_on->format('Y-m-d'), $second->due_on->format('Y-m-d')], 'POPIA 30 days, CCPA 45');
        $this->step('requests', $first, 'verify')->assertSessionHasNoErrors();
        $this->step('requests', $first, 'status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertNotNull($first->fresh()->completed_on);
        $this->travelTo(now()->addMonths(2));
        $this->assertSame('1 overdue', collect(app(PrivacyService::class)->report($this->institution))->firstWhere('check', 'Data subject requests are answered in time (POPIA s.23, s.24)')['detail'],
            'the open one is late, the completed one is not');

        $this->add('paia', ['kind_id' => 'public-body', 'requester_name' => 'A journalist', 'record_description' => 'Council minutes of 1990', 'received_on' => '2026-10-01',
            'fee_exemption_requested' => '1'])->assertSessionHasNoErrors();
        $paia = PaiaRequest::sole();
        $this->assertTrue($paia->fee_exemption_requested);
        $this->assertSame('2026-10-31', $paia->due_on->format('Y-m-d'));
        $this->step('paia', $paia, 'status', ['status' => 'extended'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-30', $paia->fresh()->due_on->format('Y-m-d'), 'extended once by 30 days (PAIA s.26)');
    }

    public function test_a_security_compromise_is_reported_to_the_regulator(): void
    {
        $this->add('compromises', ['jurisdiction_id' => 'gdpr', 'type_id' => 'confidentiality', 'severity_id' => 'high', 'description' => 'A box of registration forms was lost in transit.',
            'detected_at' => '2026-10-20T09:00', 'notification_required' => '1', 'cause' => 'Sent by an ordinary courier.'])->assertSessionHasNoErrors();
        $compromise = SecurityCompromise::sole();
        $this->assertSame('Sent by an ordinary courier.', $compromise->cause);
        $this->assertSame(['detected', '2026-10-23 09:00'], [$compromise->status->notation, app(PrivacyService::class)->regulatorNotice($compromise)['deadline']->format('Y-m-d H:i')]);
        $this->assertFalse(collect(app(PrivacyService::class)->report($this->institution))->firstWhere('check', 'Every security compromise that must be reported has been reported to the Regulator (POPIA s.22)')['ok']);
        $this->step('compromises', $compromise, 'notify', ['recipient' => 'regulator', 'method' => 'portal', 'content' => 'Reported on the portal.'])->assertSessionHasNoErrors();
        $this->step('compromises', $compromise, 'status', ['status' => 'contained'])->assertSessionHasNoErrors();
        $this->assertNotNull($compromise->fresh()->contained_at);
        $this->assertTrue(app(PrivacyService::class)->regulatorNotice($compromise->fresh())['told']);
        $this->actingAs($this->officer)->get(route('privacy.show', ['compromises', $compromise->id]))->assertOk()->assertSee('The Regulator has been told.');
    }

    public function test_jurisdictions_controls_and_who_may_do_this_work(): void
    {
        $this->actingAs($this->officer)->get(route('privacy.controls'))->assertOk()->assertSee('Information Regulator')->assertSee('C-PRV-01')->assertSee('as soon as reasonably possible');
        $this->actingAs($this->officer)->get(route('privacy.register', 'requests'))->assertOk();
        $this->actingAs($this->editor)->get(route('privacy.index'))->assertForbidden();
        $this->add('requests', ['jurisdiction_id' => 'popia', 'type_id' => 'access', 'requester_name' => 'X', 'received_on' => '2026-10-01'], $this->editor)->assertForbidden();
    }
}
