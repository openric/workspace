<?php

// OpenRiC Workspace - lectures, and training courses with their modules, assessment, enrolments, progress and
// certificates (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchLectureService and ResearchTrainingService. A lecture has sections (Markdown, with an
// image, video, sound or page beside it) and resources; it is the institution's, kept by who runs the research
// services, or a researcher's own. A published lecture, and a published course, is open to every signed-in account of
// the institution; the rest only to who keeps them. A course has modules, each perhaps a curriculum lecture, and one
// assessment of multiple-choice questions. A learner enrols, marks modules done, and takes the assessment: the best
// score is kept, and when it reaches the pass mark (the assessment's, else the course's) with every module done, the
// enrolment is completed and a certificate issued, once. Changed: Heratio's certificate number came from an MD5 of
// the enrolment and score, guessable; here it is the year and random characters, unique, and a certificate can be
// checked by its number on a public page. Heratio's correct answers were sent to the learner's browser with the
// questions; here they stay on the server.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\ResearchLecture;
use App\Models\TrainingCourse;
use App\Models\TrainingEnrolment;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Teaching
{
    public function __construct(private Journals $journals) {}

    public function mayRun(User $user): bool
    {
        return $this->journals->mayRun($user);
    }

    /** Who keeps it: its researcher, or, for the institution's, who runs the research services. */
    public function mayEdit(User $user, ResearchLecture|TrainingCourse $thing): bool
    {
        return $thing->institution_id === $user->institution_id && ($thing->user_id ? $thing->user_id === $user->id : $this->mayRun($user));
    }

    public function mayView(User $user, ResearchLecture|TrainingCourse $thing): bool
    {
        return $this->mayEdit($user, $thing) || ($thing->institution_id === $user->institution_id && $thing->status->notation === 'published');
    }

    public function lectures(User $user): Collection
    {
        return ResearchLecture::where('institution_id', $user->institution_id)->with('type', 'status', 'user')->orderBy('sort_order')->orderByRaw('scheduled_at desc nulls last')->orderBy('title')
            ->get()->filter(fn ($l) => $this->mayView($user, $l))->values();
    }

    public function courses(User $user): Collection
    {
        return TrainingCourse::where('institution_id', $user->institution_id)->with('status', 'user')->withCount('modules')->orderBy('sort_order')->orderBy('title')
            ->get()->filter(fn ($c) => $this->mayView($user, $c))->values();
    }

    // Lectures.

    public function saveLecture(User $user, array $data, ?ResearchLecture $lecture = null): ResearchLecture
    {
        if ($lecture && ! $this->mayEdit($user, $lecture)) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if (! $lecture && ! empty($data['institutional']) && ! $this->mayRun($user)) {
            throw new InvalidArgumentException(__('Only who runs the research services makes one of the institution.'));
        }
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give it a title.'));
        }
        foreach (['recording_url', 'slides_url'] as $k) {
            $this->mustBeUrl($data[$k] ?? null);
        }
        $cover = ($data['cover_iri'] ?? '') !== '' ? RecordResource::where(['institution_id' => $user->institution_id, 'iri' => trim($data['cover_iri'])])->first() : null;
        if (($data['cover_iri'] ?? '') !== '' && (! $cover || ! EntityKinds::readable($user, $cover))) {
            throw new InvalidArgumentException(__('No record has that IRI.'));
        }
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $fields = ['type_id' => $this->concept('lecture-type', (string) ($data['type'] ?? 'standalone')), 'title' => mb_substr(trim($data['title']), 0, 255), 'subtitle' => $text('subtitle'),
            'summary' => $text('summary'), 'speaker_name' => $text('speaker_name'), 'speaker_affiliation' => $text('speaker_affiliation'), 'scheduled_at' => $text('scheduled_at'),
            'location' => $text('location'), 'duration_minutes' => ($data['duration_minutes'] ?? '') !== '' ? max(1, (int) $data['duration_minutes']) : null, 'recording_url' => $text('recording_url'),
            'slides_url' => $text('slides_url'), 'curriculum_ref' => $text('curriculum_ref'), 'cover_record_id' => $cover?->id, 'status_id' => $this->concept('lecture-status', (string) ($data['status'] ?? 'draft')),
            'sort_order' => (int) ($data['sort_order'] ?? $lecture?->sort_order ?? 0)];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $lecture ? tap($lecture)->update($fields)
            : ResearchLecture::create($fields + ['institution_id' => $user->institution_id, 'user_id' => empty($data['institutional']) ? $user->id : null])));
    }

    public function saveSection(User $user, ResearchLecture $lecture, array $data, ?string $sectionId = null): void
    {
        $this->mustEdit($user, $lecture);
        $this->mustBeUrl($data['media_url'] ?? null);
        $fields = ['heading' => trim((string) ($data['heading'] ?? '')) ?: null, 'body' => trim((string) ($data['body'] ?? '')) ?: null, 'media_url' => trim((string) ($data['media_url'] ?? '')) ?: null,
            'media_type_id' => ($data['media_type'] ?? '') !== '' ? $this->concept('lecture-media-type', (string) $data['media_type']) : null,
            'sort_order' => (int) ($data['sort_order'] ?? ($lecture->sections()->max('sort_order') + 10))];
        if (! $fields['heading'] && ! $fields['body'] && ! $fields['media_url']) {
            throw new InvalidArgumentException(__('Give the section a heading, text or media.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $sectionId ? $lecture->sections()->findOrFail($sectionId)->update($fields) : $lecture->sections()->create($fields)));
    }

    public function saveResource(User $user, ResearchLecture $lecture, array $data): void
    {
        $this->mustEdit($user, $lecture);
        if (trim((string) ($data['label'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the resource a label.'));
        }
        $this->mustBeUrl($data['url'] ?? null);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $lecture->resources()->create(['label' => mb_substr(trim($data['label']), 0, 255), 'url' => trim((string) ($data['url'] ?? '')) ?: null,
            'resource_type_id' => $this->concept('lecture-resource-type', (string) ($data['resource_type'] ?? 'link')), 'sort_order' => $lecture->resources()->max('sort_order') + 10])));
    }

    /** Remove a lecture, or a section or resource of it. */
    public function removeFromLecture(User $user, ResearchLecture $lecture, string $part, ?string $id = null): void
    {
        $this->mustEdit($user, $lecture);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => match ($part) {
            'lecture' => $lecture->delete(),
            'section' => $lecture->sections()->findOrFail($id)->delete(),
            'resource' => $lecture->resources()->findOrFail($id)->delete(),
        }));
    }

    // Courses.

    public function saveCourse(User $user, array $data, ?TrainingCourse $course = null): TrainingCourse
    {
        if ($course && ! $this->mayEdit($user, $course)) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if (! $course && ! empty($data['institutional']) && ! $this->mayRun($user)) {
            throw new InvalidArgumentException(__('Only who runs the research services makes one of the institution.'));
        }
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give it a title.'));
        }
        $fields = ['title' => mb_substr(trim($data['title']), 0, 255), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'audience' => trim((string) ($data['audience'] ?? '')) ?: null,
            'language' => trim((string) ($data['language'] ?? '')) ?: null, 'pass_mark' => max(0, min(100, (int) ($data['pass_mark'] ?? 80))),
            'status_id' => $this->concept('course-status', (string) ($data['status'] ?? 'draft')), 'sort_order' => (int) ($data['sort_order'] ?? $course?->sort_order ?? 0)];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $course ? tap($course)->update($fields)
            : TrainingCourse::create($fields + ['institution_id' => $user->institution_id, 'user_id' => empty($data['institutional']) ? $user->id : null])));
    }

    public function saveModule(User $user, TrainingCourse $course, array $data, ?string $moduleId = null): void
    {
        $this->mustEdit($user, $course);
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the module a title.'));
        }
        $lecture = ($data['lecture_id'] ?? '') !== '' ? ResearchLecture::where('institution_id', $course->institution_id)->find($data['lecture_id']) : null;
        if (($data['lecture_id'] ?? '') !== '' && (! $lecture || ! $this->mayView($user, $lecture))) {
            throw new InvalidArgumentException(__('Choose a lecture of the institution.'));
        }
        $fields = ['title' => mb_substr(trim($data['title']), 0, 255), 'lecture_id' => $lecture?->id, 'body' => trim((string) ($data['body'] ?? '')) ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? ($course->modules()->max('sort_order') + 10))];
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $moduleId ? $course->modules()->findOrFail($moduleId)->update($fields) : $course->modules()->create($fields)));
    }

    public function removeFromCourse(User $user, TrainingCourse $course, string $part, ?string $id = null): void
    {
        $this->mustEdit($user, $course);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => match ($part) {
            'course' => $course->delete(),
            'module' => $course->modules()->findOrFail($id)->delete(),
            'enrolment' => $course->enrolments()->findOrFail($id)->delete(),
        }));
    }

    /** The assessment: each question with two or more options and the index of the right one. */
    public function saveAssessment(User $user, TrainingCourse $course, array $data): void
    {
        $this->mustEdit($user, $course);
        $questions = [];
        foreach ((array) ($data['questions'] ?? []) as $q) {
            $text = trim((string) ($q['question'] ?? ''));
            $options = array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($q['options'] ?? [])), fn ($o) => $o !== ''));
            if ($text === '' && ! $options) {
                continue;
            }
            $answer = (int) ($q['answer'] ?? -1);
            if ($text === '' || count($options) < 2 || ! isset($options[$answer])) {
                throw new InvalidArgumentException(__('Each question needs its text, two options or more, and which option is right.'));
            }
            $questions[] = ['question' => $text, 'options' => $options, 'answer' => $answer];
        }
        $pass = ($data['pass_mark'] ?? '') !== '' && $data['pass_mark'] !== null ? max(0, min(100, (int) $data['pass_mark'])) : null;
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $course->assessment()->updateOrCreate([], ['title' => trim((string) ($data['title'] ?? '')) ?: null, 'pass_mark' => $pass, 'questions' => $questions])));
    }

    // Enrolment and progress.

    /** Enrol oneself in a published course; or, for who keeps the course, someone by name. */
    public function enrol(User $actor, TrainingCourse $course, ?array $learner = null): TrainingEnrolment
    {
        if ($learner) {
            $this->mustEdit($actor, $course);
            if (trim((string) ($learner['learner_name'] ?? '')) === '') {
                throw new InvalidArgumentException(__('Give the learner\'s name.'));
            }
            $account = ($learner['learner_email'] ?? '') ? User::where('institution_id', $course->institution_id)->whereRaw('lower(email) = ?', [mb_strtolower(trim($learner['learner_email']))])->first() : null;
        } else {
            if (! $this->mayView($actor, $course) || $course->status->notation !== 'published') {
                throw new InvalidArgumentException(__('That course is not open for enrolment.'));
            }
            $account = $actor;
        }
        if ($account && $course->enrolments()->where('user_id', $account->id)->exists()) {
            throw new InvalidArgumentException(__('Enrolled already.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $course->enrolments()->create(['user_id' => $account?->id,
            'learner_name' => $account?->name ?? trim($learner['learner_name']), 'learner_email' => $account?->email ?? (trim((string) ($learner['learner_email'] ?? '')) ?: null),
            'status_id' => $this->concept('enrolment-status', 'enrolled'), 'enrolled_at' => now()])));
    }

    public function markModule(User $user, TrainingEnrolment $enrolment, string $moduleId, bool $done): void
    {
        $this->mustBeLearner($user, $enrolment);
        $module = $enrolment->course->modules()->findOrFail($moduleId);
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($enrolment, $module, $done) {
            $done ? $enrolment->progress()->firstOrCreate(['module_id' => $module->id], ['completed_at' => now()]) : $enrolment->progress()->where('module_id', $module->id)->delete();
            if ($enrolment->status->notation === 'enrolled') {
                $enrolment->update(['status_id' => $this->concept('enrolment-status', 'in-progress')]);
            }
        }));
    }

    /** Mark the answers [question index => option index]; the best score is kept; a pass with every module done completes the course. */
    public function submit(User $user, TrainingEnrolment $enrolment, array $answers): array
    {
        $this->mustBeLearner($user, $enrolment);
        $course = $enrolment->course;
        $questions = $course->assessment?->questions ?? [];
        if (! $questions) {
            throw new InvalidArgumentException(__('The course has no assessment yet.'));
        }
        $right = collect($questions)->filter(fn ($q, $i) => isset($answers[$i]) && (int) $answers[$i] === (int) $q['answer'])->count();
        $score = (int) round($right / count($questions) * 100);
        $passMark = $course->assessment->pass_mark ?? $course->pass_mark;
        $modulesDone = $course->modules()->count() <= $enrolment->progress()->count();
        $passed = $score >= $passMark;

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($enrolment, $score, $passed, $modulesDone, $passMark) {
            $best = max((int) $enrolment->score, $score);
            $enrolment->update(['score' => $best] + ($passed && $modulesDone && ! $enrolment->completed_at ? ['status_id' => $this->concept('enrolment-status', 'completed'), 'completed_at' => now()] : []));
            $certificate = $passed && $modulesDone ? ($enrolment->certificate ?? $enrolment->certificate()->create(['certificate_no' => $this->certificateNumber(), 'score' => $best, 'issued_at' => now()])) : null;

            return ['score' => $score, 'passed' => $passed, 'modules_done' => $modulesDone, 'pass_mark' => $passMark, 'certificate' => $certificate?->certificate_no];
        }));
    }

    public static function html(?string $markdown): string
    {
        return Journals::html($markdown);
    }

    private function certificateNumber(): string
    {
        do {
            $no = 'CERT-'.now(config('workspace.timezone'))->format('Y').'-'.strtoupper(Str::random(8));
        } while (DB::table('training_certificates')->where('certificate_no', $no)->exists());

        return $no;
    }

    private function mustBeLearner(User $user, TrainingEnrolment $enrolment): void
    {
        if ($enrolment->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function mustEdit(User $user, ResearchLecture|TrainingCourse $thing): void
    {
        if (! $this->mayEdit($user, $thing)) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function mustBeUrl(?string $url): void
    {
        if (trim((string) $url) !== '' && ! preg_match('#^https?://#i', trim($url))) {
            throw new InvalidArgumentException(__('Give a web address.'));
        }
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
