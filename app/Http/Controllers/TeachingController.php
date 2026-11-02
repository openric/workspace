<?php

// OpenRiC Workspace - lectures, training courses, assessments, enrolments and certificates (roadmap phase 19j,
// ADR 0017), after Heratio's ResearchLectureController and ResearchTrainingController.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\ResearchLecture;
use App\Models\TrainingCertificate;
use App\Models\TrainingCourse;
use App\Models\TrainingEnrolment;
use App\Research\Teaching;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class TeachingController extends Controller
{
    public function __construct(private Teaching $teaching) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('research.teaching', ['lectures' => $this->teaching->lectures($user), 'courses' => $this->teaching->courses($user), 'mayRun' => $this->teaching->mayRun($user),
            'types' => $this->choices('lecture-type'), 'enrolments' => TrainingEnrolment::where('user_id', $user->id)->with('course', 'status', 'certificate')->get()->keyBy('course_id')]);
    }

    public function lecture(Request $request, ResearchLecture $lecture): View
    {
        abort_unless($this->teaching->mayView($request->user(), $lecture), 404);

        return view('research.lecture', ['lecture' => $lecture->load('type', 'status', 'sections.mediaType', 'resources.resourceType', 'coverRecord'),
            'mayEdit' => $this->teaching->mayEdit($request->user(), $lecture), 'types' => $this->choices('lecture-type'), 'statuses' => $this->choices('lecture-status'),
            'mediaTypes' => $this->choices('lecture-media-type'), 'resourceTypes' => $this->choices('lecture-resource-type')]);
    }

    public function saveLecture(Request $request, ?ResearchLecture $lecture = null): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'type' => ['nullable', 'string', 'max:64'], 'status' => ['nullable', 'string', 'max:64'],
            'subtitle' => ['nullable', 'string', 'max:255'], 'summary' => ['nullable', 'string', 'max:20000'], 'speaker_name' => ['nullable', 'string', 'max:255'],
            'speaker_affiliation' => ['nullable', 'string', 'max:255'], 'scheduled_at' => ['nullable', 'date'], 'location' => ['nullable', 'string', 'max:255'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10000'], 'recording_url' => ['nullable', 'string', 'max:1000'], 'slides_url' => ['nullable', 'string', 'max:1000'],
            'curriculum_ref' => ['nullable', 'string', 'max:255'], 'cover_iri' => ['nullable', 'string', 'max:500'], 'sort_order' => ['nullable', 'integer'], 'institutional' => ['nullable', 'boolean']]);
        $saved = null;
        $back = $this->attempt(function () use ($request, $data, $lecture, &$saved) {
            $saved = $this->teaching->saveLecture($request->user(), $data, $lecture);
        }, __('Saved.'));

        return $saved && ! $lecture ? redirect()->route('research.lectures.show', $saved)->with('status', __('Saved.')) : $back;
    }

    public function lectureStep(Request $request, ResearchLecture $lecture, string $step, ?string $id = null): RedirectResponse
    {
        $user = $request->user();

        return match ($step) {
            'section' => $this->attempt(fn () => $this->teaching->saveSection($user, $lecture, $request->validate(['heading' => ['nullable', 'string', 'max:255'], 'body' => ['nullable', 'string', 'max:100000'],
                'media_url' => ['nullable', 'string', 'max:1000'], 'media_type' => ['nullable', 'string', 'max:64'], 'sort_order' => ['nullable', 'integer']]), $id), __('Saved.')),
            'resource' => $this->attempt(fn () => $this->teaching->saveResource($user, $lecture, $request->validate(['label' => ['required', 'string', 'max:255'], 'url' => ['nullable', 'string', 'max:1000'],
                'resource_type' => ['nullable', 'string', 'max:64']])), __('Saved.')),
            'remove-section' => $this->attempt(fn () => $this->teaching->removeFromLecture($user, $lecture, 'section', $id), __('Removed.')),
            'remove-resource' => $this->attempt(fn () => $this->teaching->removeFromLecture($user, $lecture, 'resource', $id), __('Removed.')),
            'delete' => $this->attempt(fn () => $this->teaching->removeFromLecture($user, $lecture, 'lecture'), __('Removed.'), 'research.teaching'),
        };
    }

    public function course(Request $request, TrainingCourse $course): View
    {
        $user = $request->user();
        abort_unless($this->teaching->mayView($user, $course), 404);
        $mine = $course->enrolments()->where('user_id', $user->id)->with('status', 'progress', 'certificate')->first();

        return view('research.course', ['course' => $course->load('status', 'modules.lecture', 'assessment', 'enrolments.status', 'enrolments.certificate'), 'mine' => $mine,
            'mayEdit' => $this->teaching->mayEdit($user, $course), 'statuses' => $this->choices('course-status'), 'lectures' => $this->teaching->lectures($user)]);
    }

    public function saveCourse(Request $request, ?TrainingCourse $course = null): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:20000'], 'audience' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:40'], 'pass_mark' => ['nullable', 'integer', 'min:0', 'max:100'], 'status' => ['nullable', 'string', 'max:64'], 'sort_order' => ['nullable', 'integer'],
            'institutional' => ['nullable', 'boolean']]);
        $saved = null;
        $back = $this->attempt(function () use ($request, $data, $course, &$saved) {
            $saved = $this->teaching->saveCourse($request->user(), $data, $course);
        }, __('Saved.'));

        return $saved && ! $course ? redirect()->route('research.courses.show', $saved)->with('status', __('Saved.')) : $back;
    }

    public function courseStep(Request $request, TrainingCourse $course, string $step, ?string $id = null): RedirectResponse
    {
        $user = $request->user();

        return match ($step) {
            'module' => $this->attempt(fn () => $this->teaching->saveModule($user, $course, $request->validate(['title' => ['required', 'string', 'max:255'], 'lecture_id' => ['nullable', 'uuid'],
                'body' => ['nullable', 'string', 'max:100000'], 'sort_order' => ['nullable', 'integer']]), $id), __('Saved.')),
            'assessment' => $this->attempt(fn () => $this->teaching->saveAssessment($user, $course, $request->validate(['title' => ['nullable', 'string', 'max:255'], 'pass_mark' => ['nullable', 'integer', 'min:0', 'max:100'],
                'questions' => ['array', 'max:100'], 'questions.*.question' => ['nullable', 'string', 'max:2000'], 'questions.*.options' => ['array', 'max:10'], 'questions.*.options.*' => ['nullable', 'string', 'max:500'],
                'questions.*.answer' => ['nullable', 'integer', 'min:0']])), __('Saved.')),
            'enrol' => $this->attempt(fn () => $this->teaching->enrol($user, $course), __('You are enrolled.')),
            'enrol-learner' => $this->attempt(fn () => $this->teaching->enrol($user, $course, $request->validate(['learner_name' => ['required', 'string', 'max:255'], 'learner_email' => ['nullable', 'email', 'max:255']])), __('Enrolled.')),
            'remove-module' => $this->attempt(fn () => $this->teaching->removeFromCourse($user, $course, 'module', $id), __('Removed.')),
            'remove-enrolment' => $this->attempt(fn () => $this->teaching->removeFromCourse($user, $course, 'enrolment', $id), __('Removed.')),
            'delete' => $this->attempt(fn () => $this->teaching->removeFromCourse($user, $course, 'course'), __('Removed.'), 'research.teaching'),
        };
    }

    public function enrolmentStep(Request $request, TrainingEnrolment $enrolment, string $step, ?string $id = null): RedirectResponse
    {
        $user = $request->user();
        if ($step === 'submit') {
            try {
                $result = $this->teaching->submit($user, $enrolment, (array) $request->input('answers', []));
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['research' => $e->getMessage()]);
            }

            return back()->with('result', $result)->with('status', $result['passed'] ? __('Passed with :score%.', ['score' => $result['score']]) : __('Not passed: :score%, the pass mark is :mark%.', ['score' => $result['score'], 'mark' => $result['pass_mark']]));
        }

        return match ($step) {
            'done' => $this->attempt(fn () => $this->teaching->markModule($user, $enrolment, (string) $id, true), __('Marked done.')),
            'undo' => $this->attempt(fn () => $this->teaching->markModule($user, $enrolment, (string) $id, false), __('Marked not done.')),
        };
    }

    /** A certificate, checked by its number; public, so whoever is shown one can check it. */
    public function certificate(string $number): View
    {
        $certificate = TrainingCertificate::where('certificate_no', strtoupper($number))->with('enrolment.course')->firstOrFail();

        return view('research.certificate', ['certificate' => $certificate]);
    }

    private function choices(string $vocabulary)
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->get();
    }

    private function attempt(callable $do, string $done, ?string $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route($route) : back())->with('status', $done);
    }
}
