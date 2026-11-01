<?php

// OpenRiC Workspace - research reports (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ReportService and ResearchReportsController (from PSIS's): a report of one's own, of a kind (research
// summary, genealogical, historical, source analysis, finding aid, custom) whose template gives its first sections; in
// a project or not. Its sections - a title page, the contents, headings, text, one of the project's bibliographies or
// collections, its annotations or timeline - are written, moved and filled from the project (Heratio's
// autoPopulateFromProject). It is shown as a page to print. Peer reviewers, accounts of the institution, are asked
// for their feedback and a rating from one to five; they accept, decline or give it, and the author is told. Heratio
// keeps HTML content; here it is plain text.

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Bibliography;
use App\Models\Concept;
use App\Models\ResearchAnnotation;
use App\Models\ResearchCollection;
use App\Models\ResearchPeerReview;
use App\Models\ResearchProject;
use App\Models\ResearchReport;
use App\Models\ResearchReportSection;
use App\Models\ResearchReportTemplate;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Reports
{
    public const SECTIONS = Vocab::BASE.'research-report-section-type/';

    public function __construct(private Projects $projects, private Outbox $outbox) {}

    public function create(User $user, array $data): ResearchReport
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the report a title.'));
        }
        $project = ($data['project_id'] ?? null) ? ResearchProject::findOrFail($data['project_id']) : null;
        if ($project) {
            $this->projects->must($user, $project, 'contributor');
        }
        $type = (string) ($data['template_type'] ?? 'custom');
        $template = ResearchReportTemplate::whereHas('templateType', fn ($t) => $t->where('iri', Vocab::BASE.'report-template-type/'.$type))
            ->where(fn ($w) => $w->whereNull('institution_id')->orWhere('institution_id', $user->institution_id))->orderBy('is_system')->first()
            ?? throw new InvalidArgumentException(__('Choose a kind from the list.'));

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $data, $project, $template) {
            $report = ResearchReport::create(['institution_id' => $user->institution_id, 'user_id' => $user->id, 'project_id' => $project?->id, 'title' => mb_substr(trim($data['title']), 0, 500),
                'template_type_id' => $template->template_type_id, 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'status_id' => $this->concept('research-report-status', 'draft')]);
            foreach ($template->sections_config as $i => $section) {
                [$kind, $title] = array_pad(explode(':', (string) $section, 2), 2, null);
                $report->sections()->create(['section_type_id' => $this->concept('research-report-section-type', $kind), 'sort_order' => $i,
                    'title' => $title ?? ($kind === 'title-page' ? $report->title : null)]);
            }

            return $report;
        }));
    }

    public function update(User $user, ResearchReport $report, array $data): void
    {
        $this->mustWrite($user, $report);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $report->update(['title' => mb_substr(trim((string) ($data['title'] ?? $report->title)), 0, 500) ?: $report->title,
            'description' => trim((string) ($data['description'] ?? '')) ?: null, 'status_id' => $this->concept('research-report-status', (string) ($data['status'] ?? $report->status->notation))])));
    }

    public function delete(User $user, ResearchReport $report): void
    {
        $this->mustWrite($user, $report);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $report->delete()));
    }

    public function saveSection(User $user, ResearchReport $report, array $data, ?ResearchReportSection $section = null): ResearchReportSection
    {
        $this->mustWrite($user, $report);
        $type = (string) ($data['section_type'] ?? $section?->sectionType->notation ?? 'text');
        $bibliography = ($data['bibliography_id'] ?? null) ? $this->of(Bibliography::class, $user, $report, $data['bibliography_id']) : null;
        $collection = ($data['collection_id'] ?? null) ? $this->of(ResearchCollection::class, $user, $report, $data['collection_id']) : null;
        $fields = ['section_type_id' => $this->concept('research-report-section-type', $type), 'title' => trim((string) ($data['title'] ?? '')) ?: null,
            'content' => trim((string) ($data['content'] ?? '')) ?: null, 'bibliography_id' => $bibliography?->id, 'collection_id' => $collection?->id];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $section ? tap($section)->update($fields)
            : $report->sections()->create($fields + ['sort_order' => (int) $report->sections()->max('sort_order') + 1])));
    }

    /** Move a section up or down (Heratio's moveSection), or remove it. */
    public function sectionStep(User $user, ResearchReportSection $section, string $step): void
    {
        $report = $section->report;
        $this->mustWrite($user, $report);
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($report, $section, $step) {
            if ($step === 'remove') {
                $section->delete();

                return;
            }
            $sections = $report->sections()->get()->values();
            $i = $sections->search(fn ($s) => $s->id === $section->id);
            $j = $step === 'up' ? $i - 1 : $i + 1;
            if ($j >= 0 && $j < $sections->count()) {
                [$sections[$i], $sections[$j]] = [$sections[$j], $sections[$i]];
            }
            $sections->each(fn ($s, $n) => $s->update(['sort_order' => $n]));
        }));
    }

    /** Heratio's autoPopulateFromProject: empty list sections take the project's first bibliography and collection. @return int sections filled */
    public function fill(User $user, ResearchReport $report): int
    {
        $this->mustWrite($user, $report);
        $project = $report->project ?? throw new InvalidArgumentException(__('The report is not of a project.'));
        $bibliography = Bibliography::where('project_id', $project->id)->orderBy('name')->first();
        $collection = ResearchCollection::where('project_id', $project->id)->orderBy('name')->first();
        $n = 0;
        foreach ($report->sections()->with('sectionType')->get() as $s) {
            $type = $s->sectionType->notation;
            if ($type === 'bibliography' && ! $s->bibliography_id && $bibliography) {
                $s->update(['bibliography_id' => $bibliography->id]);
                $n++;
            } elseif ($type === 'collection-list' && ! $s->collection_id && $collection) {
                $s->update(['collection_id' => $collection->id]);
                $n++;
            } elseif ($type === 'text' && $s->content === null && $s->sort_order === $report->sections()->whereHas('sectionType', fn ($t) => $t->where('iri', self::SECTIONS.'text'))->min('sort_order') && $project->description) {
                $s->update(['content' => $project->description]);
                $n++;
            }
        }

        return $n;
    }

    /** What a section holds, to show: its text, and the entries, items, annotations or events it lists. */
    public function body(User $user, ResearchReportSection $section): array
    {
        $project = $section->report->project;

        return match ($section->sectionType->notation) {
            'bibliography' => $section->bibliography ? $section->bibliography->entries()->get()->map(fn ($e) => app(Bibliographies::class)->styled($e, $section->bibliography->citationStyle->notation))->all() : [],
            'collection-list' => $section->collection ? app(Collections::class)->items($user, $section->collection)->filter(fn ($i) => $i['readable'])->map(fn ($i) => trim(($i['reference'] ?? '').' '.$i['title']))->values()->all() : [],
            'annotation-list' => $project ? app(Annotations::class)->readable($user)->where('project_id', $project->id)->latest()->get()->map(fn (ResearchAnnotation $a) => trim(($a->title ? $a->title.': ' : '').$a->content))->all() : [],
            'timeline' => $project ? $project->timelineEvents()->get()->map(fn ($e) => $e->date_start->format('j M Y').($e->date_end ? ' - '.$e->date_end->format('j M Y') : '').': '.$e->label)->all() : [],
            default => [],
        };
    }

    // Peer review.

    public function requestReview(User $user, ResearchReport $report, string $email): ResearchPeerReview
    {
        $this->mustWrite($user, $report);
        $reviewer = User::where('institution_id', $report->institution_id)->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->whereNull('disabled_at')->first()
            ?? throw new InvalidArgumentException(__('No account of the institution has that e-mail address.'));
        if ($reviewer->id === $report->user_id) {
            throw new InvalidArgumentException(__('A report is not reviewed by its author.'));
        }
        $review = DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $report, $reviewer) {
            $report->update(['status_id' => $this->concept('research-report-status', 'review')]);

            return $report->peerReviews()->create(['requested_by_id' => $user->id, 'reviewer_id' => $reviewer->id, 'status_id' => $this->concept('peer-review-status', 'pending'), 'requested_at' => now()]);
        }));
        $this->outbox->send($reviewer, new ResearchMail($reviewer, 'A request to review :title', ['title' => $report->title],
            [[':who asks you to review the report ":title".', ['who' => $user->name, 'title' => $report->title]]], route('research.reports.show', $report), 'Open the report'));

        return $review;
    }

    /** The reviewer accepts, declines, or gives the review. */
    public function review(User $user, ResearchPeerReview $review, string $step, array $data = []): void
    {
        if ($review->reviewer_id !== $user->id || in_array($review->status->notation, ['completed', 'declined'], true)) {
            throw new InvalidArgumentException(__('There is no review of yours to give.'));
        }
        $rating = ($data['rating'] ?? '') === '' || ($data['rating'] ?? null) === null ? null : (int) $data['rating'];
        if ($step === 'complete' && (trim((string) ($data['feedback'] ?? '')) === '' || $rating !== null && ($rating < 1 || $rating > 5))) {
            throw new InvalidArgumentException(__('Give your feedback, and a rating from 1 to 5 if you rate it.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $review->update(match ($step) {
            'accept' => ['status_id' => $this->concept('peer-review-status', 'in-progress')],
            'decline' => ['status_id' => $this->concept('peer-review-status', 'declined'), 'completed_at' => now()],
            'complete' => ['status_id' => $this->concept('peer-review-status', 'completed'), 'feedback' => trim($data['feedback']), 'rating' => $rating, 'completed_at' => now()],
        })));
        if ($step !== 'accept' && ($author = $review->report->user)) {
            $this->outbox->send($author, new ResearchMail($author, 'A review of :title', ['title' => $review->report->title],
                [[$step === 'complete' ? ':who has reviewed the report ":title".' : ':who declined to review the report ":title".', ['who' => $user->name, 'title' => $review->report->title]]],
                route('research.reports.show', $review->report), 'Open the report'));
        }
    }

    /** Who may read a report: its author, its project's members, its reviewers. */
    public function mayRead(User $user, ResearchReport $report): bool
    {
        return $report->institution_id === $user->institution_id && ($report->user_id === $user->id || $report->project && $this->projects->level($user, $report->project) >= 1
            || $report->peerReviews()->where('reviewer_id', $user->id)->exists());
    }

    /** Who may change it: its author, or an editor of its project. */
    public function mayWrite(User $user, ResearchReport $report): bool
    {
        return $report->user_id === $user->id || $report->project && $this->projects->level($user, $report->project) >= Projects::LEVELS['editor'];
    }

    public function visible(User $user): Collection
    {
        return ResearchReport::where('institution_id', $user->institution_id)->with('templateType', 'status', 'project')->orderByDesc('updated_at')->get()
            ->filter(fn ($r) => $this->mayRead($user, $r))->values();
    }

    private function mustWrite(User $user, ResearchReport $report): void
    {
        $this->mayWrite($user, $report) || throw new InvalidArgumentException(__('That is not yours.'));
    }

    /** A bibliography or collection the report may list: the author's own, or its project's. */
    private function of(string $class, User $user, ResearchReport $report, string $id)
    {
        return $class::where('id', $id)->where(fn ($w) => $w->where('user_id', $user->id)->when($report->project_id, fn ($q) => $q->orWhere('project_id', $report->project_id)))->first()
            ?? throw new InvalidArgumentException(__('Choose one of your own, or of the project.'));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
