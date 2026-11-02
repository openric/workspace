<?php

// OpenRiC Workspace - a research project's funding, its grant applications, and the funding calls a researcher
// watches (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchFundingService and GrantEngineService. A funding record is a grant or award: funder and
// its type, award reference, amount and currency, status, period, the data management plan it requires. Totals are
// kept per currency, never added across currencies, and count only what is awarded, active or completed; a record is
// active now when it says so, or is awarded and today falls in its period. A grant application starts from a
// template's sections (rows of research_template_sections) and is written section by section; what the project
// already holds (its description, questions, milestones, team, outputs, ethics and data plan) is gathered beside it
// to write from. Heratio's drafting of a section by a language model is phase 17's. A funding call belongs to the
// researcher who watches it.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\ResearchFunding;
use App\Models\ResearchGrantCall;
use App\Models\ResearchGrantDraft;
use App\Models\ResearchProject;
use App\Models\ResearchTemplateSection;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Funding
{
    public const AWARDED = ['awarded', 'active', 'completed'];

    private const MAX_AMOUNT = 999999999999.99;

    public function __construct(private Projects $projects) {}

    // Funding records (ResearchFundingService).

    public function save(User $actor, ResearchProject $project, array $data, ?ResearchFunding $funding = null): ResearchFunding
    {
        $this->projects->must($actor, $project, 'contributor');
        foreach (['title' => __('Give the funding a title.'), 'funder_name' => __('Name the funder.')] as $k => $message) {
            if (trim((string) ($data[$k] ?? '')) === '') {
                throw new InvalidArgumentException($message);
            }
        }
        $amount = $this->amount($data['amount'] ?? null);
        $start = ($data['start_date'] ?? '') ?: null;
        $end = ($data['end_date'] ?? '') ?: null;
        if ($start && $end && $end < $start) {
            throw new InvalidArgumentException(__('The end date comes before the start.'));
        }
        $dmp = ($data['dmp_id'] ?? '') ? $project->dmps()->whereKey($data['dmp_id'])->value('id') ?? throw new InvalidArgumentException(__('That plan is not of this project.')) : null;
        $fields = ['title' => mb_substr(trim($data['title']), 0, 512), 'funder_name' => mb_substr(trim($data['funder_name']), 0, 512),
            'funder_type_id' => $this->concept('funder-type', (string) ($data['funder_type'] ?? 'other')), 'award_reference' => trim((string) ($data['award_reference'] ?? '')) ?: null,
            'amount' => $amount, 'currency_id' => $this->concept('currency', strtoupper((string) ($data['currency'] ?? config('workspace.currency', 'ZAR')))),
            'status_id' => $this->concept('funding-status', (string) ($data['status'] ?? 'applied')), 'start_date' => $start, 'end_date' => $end,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'dmp_id' => $dmp];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $funding, $fields) {
            $funding = $funding ? tap($funding)->update($fields) : $project->funding()->create($fields + ['created_by_id' => $actor->id]);
            $this->projects->log($project, $actor, 'funding', $funding, ['funder' => $funding->funder_name]);

            return $funding;
        }));
    }

    public function delete(User $actor, ResearchFunding $funding): void
    {
        $this->projects->must($actor, $funding->project, $funding->created_by_id === $actor->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $funding->delete()));
    }

    /** Heratio's isActiveNow: active says so; applied, declined and completed are not; awarded is when today falls in its period. */
    public static function isActiveNow(ResearchFunding $f): bool
    {
        $status = $f->status->notation;
        if ($status === 'active') {
            return true;
        }
        if ($status !== 'awarded' || (! $f->start_date && ! $f->end_date)) {
            return false;
        }
        $today = now(config('workspace.timezone'))->startOfDay();

        return (! $f->start_date || $f->start_date->lte($today)) && (! $f->end_date || $f->end_date->gte($today));
    }

    /** Totals awarded per currency (never across currencies), and counts by status and funder type. */
    public function summary(ResearchProject $project): array
    {
        $records = $project->funding()->with('status', 'funderType', 'currency')->get();
        $totals = [];
        foreach ($records as $r) {
            if ($r->amount !== null && in_array($r->status->notation, self::AWARDED, true)) {
                $code = $r->currency->notation;
                $totals[$code] ??= ['cents' => 0, 'count' => 0];
                $totals[$code] = ['cents' => $totals[$code]['cents'] + self::cents((string) $r->amount), 'count' => $totals[$code]['count'] + 1];
            }
        }
        ksort($totals);
        $totals = array_map(fn ($t) => ['amount' => sprintf('%d.%02d', intdiv($t['cents'], 100), $t['cents'] % 100), 'count' => $t['count']], $totals);

        return ['total' => $records->count(), 'by_currency' => $totals, 'active_now' => $records->filter(fn ($r) => self::isActiveNow($r))->count(),
            'by_status' => $records->countBy(fn ($r) => $r->status->notation)->all(), 'by_type' => $records->countBy(fn ($r) => $r->funderType->notation)->all()];
    }

    // Grant applications (GrantEngineService, without its AI drafting).

    public function startDraft(User $actor, ResearchProject $project, string $template, ?string $title = null): ResearchGrantDraft
    {
        $this->projects->must($actor, $project, 'contributor');
        $templateId = $this->concept('grant-template', $template);
        $sections = ResearchTemplateSection::where('template_id', $templateId)->orderBy('sort_order')->get();

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $project, $templateId, $sections, $title) {
            $label = Concept::find($templateId)->label([app()->getLocale(), 'en']);
            $draft = $project->grantDrafts()->create(['template_id' => $templateId, 'title' => mb_substr(trim((string) $title) ?: __(':template application for :project', ['template' => $label, 'project' => $project->title]), 0, 255),
                'status_id' => $this->concept('grant-draft-status', 'draft'), 'created_by_id' => $actor->id]);
            foreach ($sections as $s) {
                $draft->sections()->create(['section_key' => $s->section_key, 'label' => $s->label, 'hint' => $s->hint, 'sort_order' => $s->sort_order]);
            }
            $this->projects->log($project, $actor, 'grant', $draft, ['template' => $label]);

            return $draft;
        }));
    }

    /** The title, status and the body of each section, as given; sections not given are left as they are. */
    public function saveDraft(User $actor, ResearchGrantDraft $draft, array $data): void
    {
        $this->projects->must($actor, $draft->project, 'contributor');
        if (array_key_exists('title', $data) && trim((string) $data['title']) === '') {
            throw new InvalidArgumentException(__('Give the application a title.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $draft, $data) {
            $draft->update(array_filter(['title' => isset($data['title']) ? mb_substr(trim($data['title']), 0, 255) : null,
                'status_id' => isset($data['status']) ? $this->concept('grant-draft-status', (string) $data['status']) : null]));
            foreach ($draft->sections as $section) {
                if (array_key_exists($section->section_key, (array) ($data['sections'] ?? []))) {
                    $section->update(['body' => trim((string) $data['sections'][$section->section_key]) ?: null]);
                }
            }
            $this->projects->log($draft->project, $actor, 'grant', $draft, ['status' => $draft->status->notation]);
        }));
    }

    public function deleteDraft(User $actor, ResearchGrantDraft $draft): void
    {
        $this->projects->must($actor, $draft->project, $draft->created_by_id === $actor->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $draft->delete()));
    }

    /** Heratio's completeness: how many sections carry an answer. */
    public static function completeness(Collection $sections): array
    {
        $filled = $sections->filter(fn ($s) => trim((string) $s->body) !== '')->count();

        return ['filled' => $filled, 'total' => $sections->count(), 'pct' => $sections->count() ? (int) round($filled / $sections->count() * 100) : 0];
    }

    /** What the project already holds, to write an application from (Heratio's gatherProjectMaterial, read-only). */
    public function material(ResearchProject $project): array
    {
        $project->loadMissing('milestones.status', 'teamMembers.role', 'outputs.outputType');
        $text = fn (iterable $items, callable $line) => collect($items)->map($line)->filter()->implode("\n");
        $label = fn (Concept $c) => $c->label([app()->getLocale(), 'en']);
        $brief = DB::table('research_question_briefs as b')->join('research_question_brief_versions as v', fn ($j) => $j->on('v.brief_id', '=', 'b.id')->on('v.version_no', '=', 'b.current_version'))
            ->where('b.project_id', $project->id)->first();

        return array_filter([
            'summary' => trim((string) $project->description),
            'background' => trim(implode("\n\n", array_filter([$brief->problem_statement ?? null, $brief->research_gap ?? null]))),
            'aims' => trim(implode("\n\n", array_filter([$brief->primary_question ?? null, $brief->secondary_questions ?? null, $brief->hypothesis ?? null]))),
            'methodology' => $text(DB::table('research_method_protocols')->where('project_id', $project->id)->orderBy('created_at')->get(), fn ($p) => '- '.$p->title),
            'workplan' => $text($project->milestones, fn ($m) => '- '.$m->title.($m->due_date ? ' ('.$m->due_date->toDateString().')' : '')),
            'team' => $text($project->teamMembers, fn ($t) => '- '.$t->person_name.' - '.$label($t->role).($t->affiliation ? ', '.$t->affiliation : '')),
            'outputs' => $text($project->outputs, fn ($o) => '- '.$label($o->outputType).': '.$o->title),
            'ethics' => $text($project->ethicsApprovals()->with('status')->get(), fn ($e) => '- '.$e->title.' - '.$label($e->status).($e->reference_number ? ' ('.$e->reference_number.')' : '')),
            'data-management' => $text($project->dmps()->get(), fn ($d) => '- '.$d->title),
        ], fn ($v) => $v !== '');
    }

    // Funding calls (GrantEngineService's calls), the researcher's own.

    public function saveCall(User $user, array $data, ?ResearchGrantCall $call = null): ResearchGrantCall
    {
        if ($call && $call->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        foreach (['funder' => __('Name the funder.'), 'title' => __('Give the call a title.')] as $k => $message) {
            if (trim((string) ($data[$k] ?? '')) === '') {
                throw new InvalidArgumentException($message);
            }
        }
        $url = trim((string) ($data['url'] ?? ''));
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException(__('Give a web address.'));
        }
        $project = ($data['project_id'] ?? '') ? ResearchProject::find($data['project_id']) : null;
        if (($data['project_id'] ?? '') && (! $project || $this->projects->level($user, $project) < Projects::LEVELS['contributor'])) {
            throw new InvalidArgumentException(__('Choose one of your projects.'));
        }
        $fields = ['funder' => mb_substr(trim($data['funder']), 0, 255), 'title' => mb_substr(trim($data['title']), 0, 255), 'url' => $url ?: null, 'deadline' => ($data['deadline'] ?? '') ?: null,
            'status_id' => $this->concept('grant-call-status', (string) ($data['status'] ?? 'watching')), 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'project_id' => $project?->id];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $call ? tap($call)->update($fields)
            : ResearchGrantCall::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id])));
    }

    public function deleteCall(User $user, ResearchGrantCall $call): void
    {
        if ($call->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $call->delete()));
    }

    public function calls(User $user): Collection
    {
        return ResearchGrantCall::where('user_id', $user->id)->with('status', 'project')->orderByRaw('deadline nulls last')->orderBy('title')->get();
    }

    /** Amounts are added as whole cents, so totals are exact. */
    private static function cents(string $amount): int
    {
        [$whole, $part] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($part, 0, 2), 2, '0');
    }

    /** A decimal amount as a string with two places; commas and spaces as thousands separators are dropped. */
    private function amount(mixed $value): ?string
    {
        $raw = str_replace([',', ' '], '', trim((string) $value));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > self::MAX_AMOUNT) {
            throw new InvalidArgumentException(__('Give the amount as a number, such as 250000.00.'));
        }

        return number_format((float) $raw, 2, '.', '');
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
