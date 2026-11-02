<?php

// OpenRiC Workspace - a research project's data management plans and ethics approvals, and what the project still
// owes them (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's DmpService, ResearchEthicsService and its project compliance dashboard. A plan starts from a
// template's sections (the RDA and Science Europe common sections) and is answered section by section; it is exported
// as an RDA DMP Common Standard document (maDMP), with each section's answer kept in an extension so the export loses
// nothing. An ethics approval records the committee, reference, decision and expiry, the consent basis and how
// sensitive the data is; it expires soon within 60 days of its expiry date, unless it was rejected or not required.
// The compliance view gathers what Heratio's dashboard showed, read from the project's own records rather than from
// milestones named for ethics: approvals and their expiry, the plans and how complete they are, funding that names
// no plan, outputs without a persistent identifier, team members without an ORCID iD, and whether the latest
// replication pack is older than the project's last change.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\ResearchDmp;
use App\Models\ResearchEthicsApproval;
use App\Models\ResearchProject;
use App\Models\ResearchTemplateSection;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DataPlans
{
    public const EXPIRING_SOON_DAYS = 60;

    /** BCP 47 to ISO 639-3, for the maDMP's language (Heratio's iso639). */
    private const ISO639 = ['en' => 'eng', 'af' => 'afr', 'fr' => 'fra', 'de' => 'deu', 'es' => 'spa', 'pt' => 'por', 'nl' => 'nld', 'it' => 'ita', 'zh' => 'zho', 'ar' => 'ara',
        'zu' => 'zul', 'xh' => 'xho', 'st' => 'sot', 'tn' => 'tsn', 'sn' => 'sna', 'nd' => 'nde', 'sw' => 'swa'];

    public function __construct(private Projects $projects) {}

    // Data management plans (DmpService).

    public function createPlan(User $actor, ResearchProject $project, array $data): ResearchDmp
    {
        $this->projects->must($actor, $project, 'contributor');
        $templateId = $this->concept('dmp-template', (string) ($data['template'] ?? 'generic'));
        $fields = $this->planFields($project, $data) + ['template_id' => $templateId];
        $sections = ResearchTemplateSection::where('template_id', $templateId)->orderBy('sort_order')->get();

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $fields, $sections) {
            $plan = $project->dmps()->create($fields + ['created_by_id' => $actor->id]);
            foreach ($sections as $s) {
                $plan->sections()->create(['section_key' => $s->section_key, 'label' => $s->label, 'hint' => $s->hint, 'sort_order' => $s->sort_order]);
            }
            $this->projects->log($project, $actor, 'dmp', $plan);

            return $plan;
        }));
    }

    /** The plan's details and, as given, its answers; answers not given are left as they are. */
    public function savePlan(User $actor, ResearchDmp $plan, array $data): void
    {
        $this->projects->must($actor, $plan->project, 'contributor');
        // What is not given stays as it is.
        $fields = $this->planFields($plan->project, $data + ['title' => $plan->title, 'status' => $plan->status->notation, 'funder' => $plan->funder, 'language' => $plan->language,
            'contact_name' => $plan->contact_name, 'contact_email' => $plan->contact_email]);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $plan, $data, $fields) {
            $plan->update($fields);
            foreach ($plan->sections as $section) {
                if (array_key_exists($section->section_key, (array) ($data['sections'] ?? []))) {
                    $section->update(['body' => trim((string) $data['sections'][$section->section_key]) ?: null]);
                }
            }
            $this->projects->log($plan->project, $actor, 'dmp', $plan, ['status' => $plan->status->notation]);
        }));
    }

    public function deletePlan(User $actor, ResearchDmp $plan): void
    {
        $this->projects->must($actor, $plan->project, $plan->created_by_id === $actor->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $plan->delete()));
    }

    /** The plan as an RDA DMP Common Standard (maDMP) document (Heratio's buildMadmp). */
    public function madmp(ResearchDmp $plan): array
    {
        $plan->loadMissing('project.funding.status', 'sections', 'status', 'template');
        $project = $plan->project;
        $answered = $plan->sections->filter(fn ($s) => trim((string) $s->body) !== '');
        $ethics = $project->ethicsApprovals()->with('dataSensitivity')->get();
        $sensitive = $ethics->contains(fn ($e) => in_array($e->dataSensitivity->notation, ['special-category', 'restricted'], true));
        $personal = $sensitive || $ethics->contains(fn ($e) => $e->dataSensitivity->notation === 'personal');
        $known = $ethics->isNotEmpty();
        $funding = $project->funding->map(fn ($f) => array_filter(['name' => $f->funder_name, 'funding_status' => match ($f->status->notation) {
            'applied' => 'applied', 'declined' => 'rejected', default => 'granted'
        }, 'grant_id' => $f->award_reference ? ['identifier' => $f->award_reference, 'type' => 'other'] : null]))->values()->all();
        if (! $funding && $plan->funder) {
            $funding = [['name' => $plan->funder, 'funding_status' => 'planned']];
        }

        $dmp = array_filter([
            'title' => $plan->title,
            'language' => self::ISO639[strtolower(substr($plan->language, 0, 2))] ?? 'eng',
            'created' => $plan->created_at->toIso8601String(),
            'modified' => $plan->updated_at->toIso8601String(),
            'ethical_issues_exist' => $known ? ($personal ? 'yes' : 'no') : 'unknown',
            'dmp_id' => ['identifier' => route('research.projects.plans.show', [$project, $plan]), 'type' => 'url'],
            'contact' => $plan->contact_name || $plan->contact_email ? ['name' => (string) $plan->contact_name, 'mbox' => (string) $plan->contact_email,
                'contact_id' => ['identifier' => (string) ($plan->contact_email ?: $plan->contact_name), 'type' => 'other']] : null,
            'project' => [array_filter(['title' => $project->title, 'description' => (string) $project->description, 'start' => $project->start_date?->toDateString(),
                'end' => ($project->actual_end_date ?? $project->expected_end_date)?->toDateString(), 'funding' => $funding ?: null])],
            'dataset' => [[
                'title' => $plan->title,
                'description' => $answered->map(fn ($s) => $s->label.': '.$s->body)->implode("\n\n"),
                'personal_data' => $known ? ($personal ? 'yes' : 'no') : 'unknown',
                'sensitive_data' => $known ? ($sensitive ? 'yes' : 'no') : 'unknown',
                'dataset_id' => ['identifier' => route('research.projects.plans.show', [$project, $plan]).'#dataset', 'type' => 'url'],
            ]],
            // Not of the standard: every section's answer, so the export loses nothing.
            'extension' => [['openric' => ['status' => $plan->status->notation, 'template' => $plan->template->notation, 'funder' => (string) $plan->funder,
                'sections' => $plan->sections->map(fn ($s) => ['key' => $s->section_key, 'label' => $s->label, 'answer' => (string) $s->body])->all()]]],
        ], fn ($v) => $v !== null);

        return ['dmp' => $dmp];
    }

    // Ethics approvals (ResearchEthicsService).

    public function saveApproval(User $actor, ResearchProject $project, array $data, ?ResearchEthicsApproval $approval = null): ResearchEthicsApproval
    {
        $this->projects->must($actor, $project, 'contributor');
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the approval a title.'));
        }
        $decision = ($data['decision_date'] ?? '') ?: null;
        $expiry = ($data['expiry_date'] ?? '') ?: null;
        if ($decision && $expiry && $expiry < $decision) {
            throw new InvalidArgumentException(__('It cannot expire before it was decided.'));
        }
        $dmp = ($data['dmp_id'] ?? '') ? $project->dmps()->whereKey($data['dmp_id'])->value('id') ?? throw new InvalidArgumentException(__('That plan is not of this project.')) : null;
        $fields = ['title' => mb_substr(trim($data['title']), 0, 512), 'approval_type_id' => $this->concept('ethics-approval-type', (string) ($data['approval_type'] ?? 'human-subjects')),
            'reference_number' => trim((string) ($data['reference_number'] ?? '')) ?: null, 'committee_name' => trim((string) ($data['committee_name'] ?? '')) ?: null,
            'status_id' => $this->concept('ethics-status', (string) ($data['status'] ?? 'pending')), 'decision_date' => $decision, 'expiry_date' => $expiry,
            'consent_basis_id' => $this->concept('consent-basis', (string) ($data['consent_basis'] ?? 'informed-consent')),
            'data_sensitivity_id' => $this->concept('data-sensitivity', (string) ($data['data_sensitivity'] ?? 'none')), 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'dmp_id' => $dmp];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $approval, $fields) {
            $approval = $approval ? tap($approval)->update($fields) : $project->ethicsApprovals()->create($fields + ['created_by_id' => $actor->id]);
            $this->projects->log($project, $actor, 'ethics', $approval, ['status' => $approval->status->notation]);

            return $approval;
        }));
    }

    public function deleteApproval(User $actor, ResearchEthicsApproval $approval): void
    {
        $this->projects->must($actor, $approval->project, $approval->created_by_id === $actor->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $approval->delete()));
    }

    /** expired, soon (within 60 days), or null; a rejected approval, or one not required, never expires. */
    public static function expiry(ResearchEthicsApproval $a): ?string
    {
        if (! $a->expiry_date || in_array($a->status->notation, ['rejected', 'not-required'], true)) {
            return null;
        }
        $today = now(config('workspace.timezone'))->startOfDay();

        return $a->expiry_date->lt($today) ? 'expired' : ($a->expiry_date->lte($today->copy()->addDays(self::EXPIRING_SOON_DAYS)) ? 'soon' : null);
    }

    /**
     * What the project still owes its funders, committees and readers: each check is ok, attention or missing, with
     * what to do (Heratio's compliance dashboard).
     */
    public function compliance(ResearchProject $project): array
    {
        $project->loadMissing('ethicsApprovals.status', 'dmps.sections', 'dmps.status', 'funding.status', 'outputs.status', 'teamMembers', 'replicationBuilds');
        $approvals = $project->ethicsApprovals;
        $live = $approvals->filter(fn ($a) => in_array($a->status->notation, ['approved', 'conditions'], true) && self::expiry($a) !== 'expired');
        $checks = [];

        $checks['ethics'] = match (true) {
            $approvals->isEmpty() => ['missing', __('No ethics approval is recorded. Record one, or the committee\'s finding that none is needed.')],
            $approvals->contains(fn ($a) => $a->status->notation === 'rejected') => ['attention', __('An ethics application was rejected.')],
            $approvals->contains(fn ($a) => self::expiry($a) === 'expired' || $a->status->notation === 'expired') => ['attention', __('An ethics approval has expired. Renew it before working on.')],
            $approvals->contains(fn ($a) => self::expiry($a) === 'soon') => ['attention', __('An ethics approval expires within :days days.', ['days' => self::EXPIRING_SOON_DAYS])],
            $approvals->contains(fn ($a) => $a->status->notation === 'pending') => ['attention', __('An ethics application awaits the committee.')],
            $approvals->contains(fn ($a) => $a->status->notation === 'conditions') => ['attention', __('An approval has conditions to meet.')],
            default => ['ok', trans_choice(':count approval is in force or none is needed.|:count approvals are in force or none is needed.', $approvals->count())],
        };
        $plans = $project->dmps->reject(fn ($p) => $p->status->notation === 'superseded');
        $incomplete = $plans->filter(fn ($p) => Funding::completeness($p->sections)['pct'] < 100);
        $checks['dmp'] = match (true) {
            $plans->isEmpty() => ['missing', __('No data management plan. Start one from a template.')],
            $incomplete->isNotEmpty() => ['attention', __(':title leaves sections unanswered.', ['title' => $incomplete->first()->title])],
            default => ['ok', __('Every section of the plan is answered.')],
        };
        $unplanned = $project->funding->filter(fn ($f) => in_array($f->status->notation, Funding::AWARDED, true) && $f->status->notation !== 'completed' && ! $f->dmp_id);
        $checks['funding'] = $unplanned->isNotEmpty() ? ['attention', __(':funder names no data management plan.', ['funder' => $unplanned->first()->funder_name])]
            : ['ok', trans_choice(':count funding record.|:count funding records.', $project->funding->count())];
        $published = $project->outputs->filter(fn ($o) => $o->status->notation === 'published');
        $bare = $published->filter(fn ($o) => ! $o->identifier && ! $o->identifier_url);
        $checks['identifiers'] = $bare->isNotEmpty() ? ['attention', __(':title is published without a DOI or other identifier.', ['title' => $bare->first()->title])]
            : ['ok', trans_choice(':count published output, each with an identifier.|:count published outputs, each with an identifier.', $published->count())];
        $noOrcid = $project->teamMembers->filter(fn ($t) => ! $t->orcid);
        $checks['orcid'] = $noOrcid->isNotEmpty() ? ['attention', __(':name has no ORCID iD on the team.', ['name' => $noOrcid->first()->person_name])]
            : ['ok', __('Every team member has an ORCID iD.')];
        $pack = $project->replicationBuilds->first();
        $checks['replication'] = match (true) {
            ! $pack => ['missing', __('No replication pack has been built.')],
            $pack->built_at->lt($project->updated_at) => ['attention', __('The project changed after the last replication pack was built.')],
            default => ['ok', __('The replication pack is current.')],
        };

        return ['checks' => $checks, 'in_force' => $live->count(), 'sensitivity' => $approvals->load('dataSensitivity')->max(fn ($a) => array_search($a->dataSensitivity->notation, ['none', 'personal', 'special-category', 'restricted'], true))];
    }

    private function planFields(ResearchProject $project, array $data): array
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the plan a title.'));
        }
        $email = trim((string) ($data['contact_email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(__('That is not an e-mail address.'));
        }

        return ['title' => mb_substr(trim($data['title']), 0, 255), 'status_id' => $this->concept('dmp-status', (string) ($data['status'] ?? 'draft')),
            'funder' => trim((string) ($data['funder'] ?? '')) ?: null, 'language' => mb_substr(trim((string) ($data['language'] ?? '')) ?: 'en', 0, 12),
            'contact_name' => trim((string) ($data['contact_name'] ?? '')) ?: null, 'contact_email' => $email ?: null];
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
