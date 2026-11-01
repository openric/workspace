<?php

// OpenRiC Workspace - the studios of a research project (roadmap phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's WritingStudioService, ReviewStudioService, PublicationStudioService, MethodStudioService,
// QuestionBuilderService, ArgumentBuilderService and SourceTriageService:
//
// - The writing studio: documents (thesis chapters, articles, reviews, sections) in sections, with a claim of the
//   ledger or a work of the project's bibliographies pulled into a section as its reference; each saved version kept
//   whole; given out as Markdown.
// - The review studio: reviewers' comments on the project or one of its claims, in threads, resolved or not.
// - The publication studio: a manuscript sent to a venue (of the journal directory, or named), moved through drafting,
//   submission, review, revision, acceptance and publication or rejection as Heratio's transitions allow; the venue's
//   requirements (seeded with Heratio's) ticked off; and each reviewer's point with the reply and what was revised.
//   Venues are suggested from the directory by the project's words, as Heratio's suggestForScope does.
// - The method studio: a protocol from a method template (Heratio's eleven: design science, archival, ethnography,
//   case study and the rest), its thirteen areas written with the template's prompts.
// - The question builder: the research design brief, each save a new version that is never changed, with the reason;
//   and a diagnosis of its weaknesses by Heratio's checks.
// - The argument builder: the nine steps from problem to implication, each resting on a claim, with Heratio's warnings
//   (missing steps, claims with no evidence or one source, contested claims, conclusions stronger than their claims).
// - Source triage: the works of the project's bibliographies and the items of its collections, sorted (essential,
//   useful, background and so on) and marked by how far they are read.
//
// The AI parts of these studios (Heratio's aiDraftSection, runReviewerTwin, aiFitSuggestion, aiDiagnosis and
// generateAiPreview) come with the AI services of phase 17, through the gateway.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Bibliography;
use App\Models\BibliographyEntry;
use App\Models\Concept;
use App\Models\ResearchArgument;
use App\Models\ResearchArgumentStep;
use App\Models\ResearchClaim;
use App\Models\ResearchCollection;
use App\Models\ResearchCollectionItem;
use App\Models\ResearchMethodProtocol;
use App\Models\ResearchMethodTemplate;
use App\Models\ResearchProject;
use App\Models\ResearchPublicationSubmission;
use App\Models\ResearchQuestionBrief;
use App\Models\ResearchReviewComment;
use App\Models\ResearchSourceTriage;
use App\Models\ResearchTargetJournal;
use App\Models\ResearchWritingDoc;
use App\Models\ResearchWritingSection;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Studios
{
    /** Heratio's PublicationStudioService::TRANSITIONS. */
    public const TRANSITIONS = ['drafting' => ['submitted', 'rejected'], 'submitted' => ['reviewed', 'accepted', 'rejected'], 'reviewed' => ['revised', 'accepted', 'rejected'],
        'revised' => ['submitted', 'reviewed', 'accepted', 'rejected'], 'accepted' => ['published'], 'published' => [], 'rejected' => ['drafting']];

    /** Heratio's DEFAULT_REQUIREMENTS of a venue. */
    public const REQUIREMENTS = ['Word count within the venue limit', 'Formatting matches the venue template', 'Reference style applied consistently', 'Data availability statement included',
        'Ethics statement included', 'Author contributions and conflicts declared', 'ORCID and affiliations complete'];

    /** Heratio's MethodStudioService::AREAS. */
    public const AREAS = ['design' => 'Research design', 'sampling' => 'Sampling / selection', 'data_sources' => 'Data sources', 'instruments' => 'Instruments',
        'coding_framework' => 'Coding / analysis framework', 'variables' => 'Variables / constructs', 'validity' => 'Validity', 'reliability' => 'Reliability', 'ethics' => 'Ethics',
        'consent' => 'Consent', 'bias_control' => 'Bias control', 'reproducibility' => 'Reproducibility', 'data_management' => 'Data management'];

    /** Heratio's QuestionBuilderService::FIELDS. */
    public const BRIEF = ['broad_topic', 'problem_statement', 'research_gap', 'primary_question', 'secondary_questions', 'hypothesis', 'scope_boundaries', 'key_definitions', 'assumptions', 'bias_risks'];

    public const BRIEF_LABELS = ['broad_topic' => 'Broad topic', 'problem_statement' => 'Problem statement', 'research_gap' => 'Research gap', 'primary_question' => 'Primary question',
        'secondary_questions' => 'Secondary questions (one a line)', 'hypothesis' => 'Hypothesis', 'scope_boundaries' => 'Scope boundaries', 'key_definitions' => 'Key definitions',
        'assumptions' => 'Assumptions', 'bias_risks' => 'Bias risks'];

    private const CONTESTED = ['rejected', 'contested', 'disputed', 'weak'];

    private const WEAK = ['idea', 'working', 'weak', 'needs-evidence', 'proposed', 'contested', 'rejected', 'disputed'];

    private const CONCLUSIONS = ['contribution', 'implication'];

    public function __construct(private Projects $projects) {}

    // The writing studio.

    public function saveDoc(User $user, ResearchProject $project, array $data, ?ResearchWritingDoc $doc = null): ResearchWritingDoc
    {
        $this->projects->must($user, $project, 'contributor');
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the document a title.'));
        }
        $fields = ['title' => mb_substr(trim($data['title']), 0, 500), 'doc_type_id' => $this->concept('writing-doc-type', (string) ($data['doc_type'] ?? 'section')),
            'status_id' => $this->concept('writing-status', (string) ($data['status'] ?? $doc?->status->notation ?? 'draft'))];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $doc ? tap($doc)->update($fields) : $project->writingDocs()->create($fields + ['created_by_id' => $user->id])));
    }

    public function saveSection(User $user, ResearchWritingDoc $doc, array $data, ?ResearchWritingSection $section = null): ResearchWritingSection
    {
        $this->projects->must($user, $doc->project, 'contributor');
        $fields = ['heading' => trim((string) ($data['heading'] ?? '')) ?: null, 'body' => (string) ($data['body'] ?? '') === '' ? null : (string) $data['body'],
            'sort_order' => (int) ($data['sort_order'] ?? $section?->sort_order ?? ((int) $doc->sections()->max('sort_order') + 1))];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($doc, $section, $fields) {
            $section = $section ? tap($section)->update($fields) : $doc->sections()->create($fields);
            $doc->touch();

            return $section;
        }));
    }

    /** Pull a claim or a bibliography's work into a section, as Heratio's formatClaimReference and formatSourceReference write it. */
    public function pull(User $user, ResearchWritingSection $section, string $kind, string $id): void
    {
        $project = $section->doc->project;
        $this->projects->must($user, $project, 'contributor');
        $text = match ($kind) {
            'claim' => ($c = ResearchClaim::where(['id' => $id, 'project_id' => $project->id])->first()) ? trim(Str::limit($c->object_value, 600)).' [Claim]' : null,
            'source' => ($e = BibliographyEntry::where('id', $id)->whereIn('bibliography_id', Bibliography::where('project_id', $project->id)->select('id'))->first())
                ? implode(' ', array_filter([trim((string) $e->authors), $e->date ? '('.trim($e->date).')' : null, trim((string) $e->title).'.', $e->container_title ? trim($e->container_title).'.' : null,
                    $e->publisher ? trim($e->publisher).'.' : null])) : null,
            default => null,
        } ?? throw new InvalidArgumentException(__('Choose one of the project\'s.'));
        $this->saveSection($user, $section->doc, ['heading' => $section->heading, 'body' => ($section->body ? $section->body."\n\n" : '').$text], $section);
    }

    public function removeSection(User $user, ResearchWritingSection $section): void
    {
        $this->projects->must($user, $section->doc->project, 'contributor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $section->delete()));
    }

    /** Keep the document as it is now, whole. */
    public function saveVersion(User $user, ResearchWritingDoc $doc, ?string $note = null): void
    {
        $this->projects->must($user, $doc->project, 'contributor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $doc->versions()->create(['version_no' => (int) $doc->versions()->max('version_no') + 1,
            'snapshot' => ['title' => $doc->title, 'sections' => $doc->sections()->get(['heading', 'body'])->toArray()], 'note' => trim((string) $note) ?: null,
            'created_by_id' => $user->id, 'created_at' => now()])));
    }

    /** Heratio's exportMarkdown. */
    public function markdown(ResearchWritingDoc $doc, array $languages = ['en']): string
    {
        $lines = ['# '.$doc->title, '', '_'.$doc->docType->label($languages).' - '.$doc->status->label($languages).'_', ''];
        foreach ($doc->sections()->get() as $s) {
            if ($s->heading) {
                array_push($lines, '## '.$s->heading, '');
            }
            if (trim((string) $s->body) !== '') {
                array_push($lines, trim($s->body), '');
            }
        }

        return implode("\n", $lines);
    }

    // The review studio.

    public function comment(User $user, ResearchProject $project, array $data): ResearchReviewComment
    {
        $this->projects->must($user, $project, 'viewer');
        if (trim((string) ($data['body'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Write something.'));
        }
        $claim = ($data['claim_id'] ?? null) ? ResearchClaim::where(['id' => $data['claim_id'], 'project_id' => $project->id])->first() ?? throw new InvalidArgumentException(__('Choose a claim of this project.')) : null;
        $thread = ($data['thread_id'] ?? null) ? $project->reviewComments()->where('id', $data['thread_id'])->whereNull('thread_id')->first() ?? throw new InvalidArgumentException(__('Reply to a comment of this project.')) : null;

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $project->reviewComments()->create(['claim_id' => $claim?->id ?? $thread?->claim_id, 'thread_id' => $thread?->id,
            'author_id' => $user->id, 'body' => trim($data['body'])])));
    }

    public function resolveComment(User $user, ResearchReviewComment $comment): void
    {
        $this->projects->must($user, $comment->project, $comment->author_id === $user->id ? 'viewer' : 'contributor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $comment->update(['resolved' => ! $comment->resolved])));
    }

    // The publication studio.

    public function submit(User $user, ResearchProject $project, array $data, ?ResearchPublicationSubmission $submission = null): ResearchPublicationSubmission
    {
        $this->projects->must($user, $project, 'contributor');
        $journal = ($data['target_journal_id'] ?? null) ? ResearchTargetJournal::where(['id' => $data['target_journal_id'], 'institution_id' => $project->institution_id])->first() : null;
        $venue = trim((string) ($data['venue_name'] ?? '')) ?: $journal?->title;
        if (! $venue) {
            throw new InvalidArgumentException(__('Name the venue, or choose one of the directory.'));
        }
        $fields = ['target_journal_id' => $journal?->id, 'venue_name' => $venue, 'manuscript_title' => trim((string) ($data['manuscript_title'] ?? '')) ?: null,
            'doi' => FieldAlerts::doi($data['doi'] ?? null), 'repository_url' => trim((string) ($data['repository_url'] ?? '')) ?: null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $submission, $fields) {
            if ($submission) {
                return tap($submission)->update($fields);
            }
            $submission = $project->publicationSubmissions()->create($fields + ['status_id' => $this->concept('publication-status', 'drafting'), 'created_by_id' => $user->id]);
            foreach (self::REQUIREMENTS as $i => $label) {
                $submission->requirements()->create(['label' => __($label), 'sort_order' => $i]);
            }

            return $submission;
        }));
    }

    /** Move a manuscript on, as Heratio's transitions allow; sent, it takes the day; decided, the day of the decision. */
    public function transition(User $user, ResearchPublicationSubmission $submission, string $to): void
    {
        $this->projects->must($user, $submission->project, 'contributor');
        $from = $submission->status->notation;
        in_array($to, self::TRANSITIONS[$from] ?? [], true) || throw new InvalidArgumentException(__('It cannot go from :from to :to.', ['from' => $from, 'to' => $to]));
        $today = now(config('workspace.timezone'))->toDateString();
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $submission->update(['status_id' => $this->concept('publication-status', $to)]
            + ($to === 'submitted' && ! $submission->submitted_at ? ['submitted_at' => $today] : []) + (in_array($to, ['accepted', 'rejected'], true) ? ['decision_at' => $today] : []))));
    }

    /** A requirement added, ticked, noted or removed; a reviewer's point and the reply added or removed. */
    public function submissionStep(User $user, ResearchPublicationSubmission $submission, string $step, array $data = []): void
    {
        $this->projects->must($user, $submission->project, 'contributor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => match ($step) {
            'requirement' => trim((string) ($data['label'] ?? '')) !== '' ? $submission->requirements()->create(['label' => trim($data['label']),
                'sort_order' => (int) $submission->requirements()->max('sort_order') + 1]) : throw new InvalidArgumentException(__('Give the requirement.')),
            'tick' => $submission->requirements()->findOrFail($data['requirement_id'] ?? null)->update(['met' => ! empty($data['met']), 'note' => trim((string) ($data['note'] ?? '')) ?: null]),
            'unrequire' => $submission->requirements()->findOrFail($data['requirement_id'] ?? null)->delete(),
            'response' => trim((string) ($data['point'] ?? '')) !== '' ? $submission->responses()->create(['reviewer_label' => trim((string) ($data['reviewer_label'] ?? '')) ?: null,
                'point' => trim($data['point']), 'response' => trim((string) ($data['response'] ?? '')) ?: null, 'revision_note' => trim((string) ($data['revision_note'] ?? '')) ?: null,
                'created_by_id' => $user->id]) : throw new InvalidArgumentException(__('Give the reviewer\'s point.')),
            'unrespond' => $submission->responses()->findOrFail($data['response_id'] ?? null)->delete(),
            'delete' => $submission->delete(),
        }));
    }

    /** Heratio's suggestForScope: the directory's journals whose scope or title holds the project's words, best first. */
    public function venues(ResearchProject $project, array $filters = [], int $limit = 10): Collection
    {
        $terms = collect(preg_split('/[^\p{L}]+/u', mb_strtolower($project->title.' '.$project->description.' '.($filters['scope'] ?? ''))) ?: [])->filter(fn ($t) => mb_strlen($t) >= 4)->unique();
        $journals = ResearchTargetJournal::where('institution_id', $project->institution_id)->whereHas('status', fn ($s) => $s->where('iri', Vocab::BASE.'target-journal-status/active'))
            ->when(! empty($filters['open_access']), fn ($q) => $q->where('open_access', true))->when($filters['market'] ?? null, fn ($q, $m) => $q->where('accreditation_market', mb_strtoupper($m)))->get();

        return $journals->map(fn ($j) => [$j, $terms->filter(fn ($t) => str_contains(mb_strtolower($j->subject_scope.' '.$j->title), $t))->count()])
            ->filter(fn ($p) => $p[1] > 0)->sortByDesc(fn ($p) => $p[1])->take($limit)->map(fn ($p) => $p[0]->setAttribute('match_score', $p[1]))->values();
    }

    // The method studio.

    public function templates(string $institutionId): Collection
    {
        return ResearchMethodTemplate::where('is_active', true)->where(fn ($w) => $w->whereNull('institution_id')->orWhere('institution_id', $institutionId))->orderBy('sort_order')->get();
    }

    public function saveProtocol(User $user, ResearchProject $project, array $data, ?ResearchMethodProtocol $protocol = null): ResearchMethodProtocol
    {
        $this->projects->must($user, $project, 'contributor');
        $template = $protocol?->template ?? $this->templates($project->institution_id)->firstWhere('code', $data['template'] ?? null) ?? throw new InvalidArgumentException(__('Choose a method from the list.'));
        $fields = array_map(fn ($v) => trim((string) $v), array_intersect_key((array) ($data['fields'] ?? []), self::AREAS));
        $values = ['title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: $template->name, 0, 255), 'fields' => array_filter($fields, fn ($v) => $v !== '') + ($protocol ? array_diff_key($protocol->fields, $fields) : []),
            'status_id' => $this->concept('protocol-status', (string) ($data['status'] ?? $protocol?->status->notation ?? 'draft'))];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $protocol ? tap($protocol)->update($values)
            : $project->methodProtocols()->create($values + ['template_id' => $template->id, 'created_by_id' => $user->id])));
    }

    // The question builder.

    /** Save the brief as a new version, never changing an earlier one, with the reason. */
    public function saveBrief(User $user, ResearchProject $project, array $data): ResearchQuestionBrief
    {
        $this->projects->must($user, $project, 'contributor');
        $values = array_map(fn ($v) => trim((string) $v) ?: null, array_intersect_key($data, array_flip(self::BRIEF)));

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $data, $values) {
            $brief = $project->questionBrief ?? $project->questionBrief()->create(['status_id' => $this->concept('brief-status', 'draft'), 'created_by_id' => $user->id]);
            $no = $brief->current_version + 1;
            $brief->versions()->create($values + ['version_no' => $no, 'change_reason' => trim((string) ($data['change_reason'] ?? '')) ?: ($no === 1 ? __('First version') : null),
                'created_by_id' => $user->id, 'created_at' => now()]);
            $brief->update(['current_version' => $no, 'status_id' => $this->concept('brief-status', (string) ($data['status'] ?? $brief->status->notation))]);

            return $brief;
        }));
    }

    /**
     * Heratio's diagnose: the checks of a brief, by its words alone.
     *
     * @return list<array{key: string, level: string, label: string, message: string}>
     */
    public static function diagnose(array $b): array
    {
        $v = fn (string $k) => trim((string) ($b[$k] ?? ''));
        [$topic, $problem, $gap, $primary, $secondary] = [$v('broad_topic'), $v('problem_statement'), $v('research_gap'), $v('primary_question'), $v('secondary_questions')];
        $primaryWords = $primary === '' ? 0 : count(preg_split('/\s+/', $primary));
        $secondaryCount = $secondary === '' ? 0 : count(array_filter(array_map('trim', preg_split('/\R/', $secondary))));
        $flag = fn (string $key, string $level, string $label, string $message) => compact('key', 'level', 'label', 'message');
        $flags = [];
        if ($primary !== '' && ($primaryWords <= 6 || ($v('scope_boundaries') === '' && preg_match('/\b(everything|all|global|the world|history of|impact of)\b/i', $primary.' '.$topic)))) {
            $flags[] = $flag('too-broad', 'warning', __('Possibly too broad'), __('The primary question reads broadly and the scope boundaries are thin. Add temporal, geographic, or population limits so the question is answerable within the project.'));
        }
        if ($primary !== '' && $primaryWords >= 35 && $secondaryCount === 0) {
            $flags[] = $flag('too-narrow', 'info', __('Possibly too narrow'), __('The question is long and highly specific with no secondary questions. Consider whether it yields enough material for a sustained study, or break it into linked sub-questions.'));
        }
        if ($primary !== '' && $gap === '') {
            $flags[] = $flag('already-answered', 'warning', __('Gap not stated'), __('No research gap is recorded, so the question may already be answered in the literature. State what is currently unknown and why it matters.'));
        }
        if ($primary !== '' && $v('hypothesis') === '' && $v('key_definitions') === '') {
            $flags[] = $flag('method-weak', 'info', __('Method scaffolding thin'), __('No hypothesis and no key definitions are recorded. Even for exploratory work, define the central terms so the question can be operationalised.'));
        }
        if (preg_match('/\b(human subjects?|personal data|patient|child(ren)?|indigenous|vulnerable|consent|health|medical|sacred|repatriation|human remains|ethnic|race|religion|sexual|minor)\b/i', $topic.' '.$problem.' '.$primary.' '.$secondary)) {
            $flags[] = $flag('ethics', 'danger', __('Ethically sensitive'), __('The brief touches subjects that often require ethics review, informed consent, or cultural protocols. Record the relevant safeguards in scope boundaries and assumptions before collecting sources.'));
        }
        if ($primary !== '' && $v('assumptions') === '' && $v('bias_risks') === '') {
            $flags[] = $flag('evidence-alignment', 'info', __('Evidence assumptions unrecorded'), __('No assumptions about evidence availability and no bias risks are recorded. Note whether the sources needed to answer the question are likely to exist and be accessible.'));
        }
        if (count(array_filter(self::BRIEF, fn ($f) => $v($f) !== '')) >= 8 && $gap !== '' && $v('scope_boundaries') !== '') {
            $flags[] = $flag('publishable', 'success', __('Looks publishable'), __('The brief is well formed: a clear gap, bounded scope, and most design fields completed. This question is in good shape to take into source collection.'));
        }

        return $flags ?: [$flag('ok', 'success', __('No issues detected'), __('The heuristic checks did not flag anything. Keep refining as your reading develops.'))];
    }

    // The argument builder.

    public function saveArgument(User $user, ResearchProject $project, array $data): ResearchArgument
    {
        $this->projects->must($user, $project, 'contributor');

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => tap($project->argument ?? $project->argument()->create(['created_by_id' => $user->id]))
            ->update(['title' => trim((string) ($data['title'] ?? '')) ?: null, 'central_thesis' => trim((string) ($data['central_thesis'] ?? '')) ?: null])));
    }

    /** Add a step in a slot, resting on a claim of the project or on none yet. */
    public function addStep(User $user, ResearchProject $project, string $slot, ?string $claimId = null, ?string $note = null): ResearchArgumentStep
    {
        $this->projects->must($user, $project, 'contributor');
        $claim = $claimId ? ResearchClaim::where(['id' => $claimId, 'project_id' => $project->id])->first() ?? throw new InvalidArgumentException(__('Choose a claim of this project.')) : null;

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $slot, $claim, $note) {
            $argument = $project->argument ?? $project->argument()->create(['created_by_id' => $user->id]);

            return $argument->steps()->create(['slot_id' => $this->concept('argument-slot', $slot), 'claim_id' => $claim?->id, 'note' => trim((string) $note) ?: null,
                'sort_order' => (int) $argument->steps()->max('sort_order') + 1]);
        }));
    }

    public function stepStep(User $user, ResearchArgumentStep $step, string $what, array $data = []): void
    {
        $project = $step->argument->project;
        $this->projects->must($user, $project, 'contributor');
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($step, $what, $data, $project) {
            match ($what) {
                'update' => $step->update(['claim_id' => ($data['claim_id'] ?? null) ? (ResearchClaim::where(['id' => $data['claim_id'], 'project_id' => $project->id])->value('id')
                    ?? throw new InvalidArgumentException(__('Choose a claim of this project.'))) : null, 'note' => trim((string) ($data['note'] ?? '')) ?: null]),
                'up', 'down' => $this->move($step, $what),
                'remove' => $step->delete(),
            };
        }));
    }

    /**
     * Heratio's computeWarnings for the argument.
     *
     * @return list<array{severity: string, slot: ?string, message: string}>
     */
    public function warnings(ResearchArgument $argument, array $languages = ['en']): array
    {
        $steps = $argument->steps()->with('slot', 'claim.status', 'claim.evidence')->get();
        $slots = Concept::choices(Vocab::BASE.'argument-slot/')->get();
        $warnings = [];
        $missing = $slots->reject(fn ($s) => $steps->contains(fn ($st) => $st->slot_id === $s->id))->map(fn ($s) => $s->label($languages));
        if ($missing->isNotEmpty()) {
            $warnings[] = ['severity' => 'info', 'slot' => null, 'message' => __('The argument is missing :n of the nine steps: :list.', ['n' => $missing->count(), 'list' => $missing->implode(', ')])];
        }
        foreach ($steps as $s) {
            $label = $s->slot->label($languages);
            $claim = $s->claim;
            if (! $claim) {
                $warnings[] = ['severity' => 'warning', 'slot' => $s->slot->notation, 'message' => __('The ":slot" step has no claim. Choose one from the ledger to support it.', ['slot' => $label])];

                continue;
            }
            $evidence = $claim->evidence->count();
            $status = $claim->status->notation;
            if ($evidence === 0) {
                $warnings[] = ['severity' => 'danger', 'slot' => $s->slot->notation, 'message' => __('The ":slot" claim has no evidence. An uncited claim cannot carry the argument.', ['slot' => $label])];
            }
            if ($evidence >= 2 && $claim->evidence->map(fn ($e) => $e->source_type_id.':'.$e->source_id)->unique()->count() === 1) {
                $warnings[] = ['severity' => 'warning', 'slot' => $s->slot->notation, 'message' => __('The ":slot" claim leans on one source across :n citations. Broaden the support.', ['slot' => $label, 'n' => $evidence])];
            }
            if (in_array($status, self::CONTESTED, true)) {
                $warnings[] = ['severity' => 'danger', 'slot' => $s->slot->notation, 'message' => __('The ":slot" claim is :status, which signals a contradiction or dispute. Resolve it before relying on it.', ['slot' => $label, 'status' => $claim->status->label($languages)])];
            }
            if (in_array($s->slot->notation, self::CONCLUSIONS, true)) {
                $why = array_filter([$evidence === 0 ? __('no evidence') : null, $claim->confidence !== null && $claim->confidence <= 0.40 ? __('low confidence') : null,
                    in_array($status, self::WEAK, true) ? __('status :status', ['status' => $claim->status->label($languages)]) : null]);
                if ($why) {
                    $warnings[] = ['severity' => 'warning', 'slot' => $s->slot->notation, 'message' => __('The ":slot" conclusion rests on a weak claim (:why). The conclusion may be stronger than the evidence supports.', ['slot' => $label, 'why' => implode(', ', $why)])];
                }
            }
        }
        $contested = $steps->filter(fn ($s) => $s->claim && in_array($s->claim->status->notation, self::CONTESTED, true))->pluck('claim_id')->unique();
        if ($contested->isNotEmpty() && $steps->whereNotNull('claim_id')->count() > $contested->count()) {
            $warnings[] = ['severity' => 'info', 'slot' => null, 'message' => __('This argument mixes :n contested or rejected claims with supported ones. Check the chain does not contradict itself.', ['n' => $contested->count()])];
        }

        return $warnings;
    }

    // Source triage.

    /** Heratio's getBoard: the project's works and items, each with how it is sorted and read. @return Collection<int, array> */
    public function board(ResearchProject $project): Collection
    {
        $types = Concept::choices(Vocab::BASE.'claim-source-type/')->get()->keyBy('notation');
        $triage = $project->sourceTriage()->with('triageCategory', 'readStatus')->get()->keyBy(fn ($t) => $t->source_type_id.':'.$t->source_id);
        $entries = BibliographyEntry::whereIn('bibliography_id', Bibliography::where('project_id', $project->id)->select('id'))->with('bibliography')->get()
            ->map(fn ($e) => ['type' => 'bibliography-entry', 'id' => $e->id, 'label' => trim($e->authors.' '.$e->title), 'from' => $e->bibliography->name]);
        $items = ResearchCollectionItem::whereIn('collection_id', ResearchCollection::where('project_id', $project->id)->select('id'))->with('collection')->get()
            ->map(fn ($i) => ['type' => 'collection-item', 'id' => $i->id, 'label' => trim(($i->reference_code ? $i->reference_code.' ' : '').($i->title ?? (app(Favourites::class)->entity($i)?->getAttribute('iri') ?? ''))), 'from' => $i->collection->name]);

        return $entries->concat($items)->map(fn ($s) => $s + ['triage' => $triage[$types[$s['type']]->id.':'.$s['id']] ?? null])->values();
    }

    public function triage(User $user, ResearchProject $project, string $type, string $id, array $data): ResearchSourceTriage
    {
        $this->projects->must($user, $project, 'contributor');
        $known = $this->board($project)->contains(fn ($s) => $s['type'] === $type && $s['id'] === $id);
        $known || throw new InvalidArgumentException(__('Choose one of the project\'s.'));
        $fields = ['triage_category_id' => ($data['triage_category'] ?? '') !== '' ? $this->concept('triage-category', (string) $data['triage_category']) : null,
            'read_status_id' => $this->concept('read-status', (string) (($data['read_status'] ?? '') ?: 'unread')), 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'updated_by_id' => $user->id];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => ResearchSourceTriage::updateOrCreate(['project_id' => $project->id,
            'source_type_id' => $this->concept('claim-source-type', $type), 'source_id' => $id], $fields)));
    }

    private function move(ResearchArgumentStep $step, string $way): void
    {
        $steps = $step->argument->steps()->get()->values();
        $i = $steps->search(fn ($s) => $s->id === $step->id);
        $j = $way === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $steps->count()) {
            [$steps[$i], $steps[$j]] = [$steps[$j], $steps[$i]];
        }
        $steps->each(fn ($s, $n) => $s->update(['sort_order' => $n]));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
