<?php

// OpenRiC Workspace - the file plan and its imports, classification rules, captured e-mail, declarations, vital
// records and compliance assessments (roadmap phase 13c, ADR 0015), after Heratio's FilePlanController,
// ClassificationController, EmailCaptureController, ReviewController (declarations and vital records) and
// ComplianceController. Who takes part in retention and disposal does this, as there.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Files\FileStore;
use App\Models\Activity;
use App\Models\ClassificationLogEntry;
use App\Models\ClassificationRule;
use App\Models\ComplianceAssessment;
use App\Models\Concept;
use App\Models\DisposalClass;
use App\Models\EmailCapture;
use App\Models\FilePlanImport;
use App\Models\FilePlanNode;
use App\Models\RecordDeclaration;
use App\Models\RecordResource;
use App\Models\VitalRecord;
use App\Relations\EntityKinds;
use App\Retention\ClassificationRules;
use App\Retention\Compliance;
use App\Retention\Declarations;
use App\Retention\EmailCaptures;
use App\Retention\FilePlan;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecordsManagementController extends Controller
{
    public function __construct(private FilePlan $plan, private ClassificationRules $rules, private EmailCaptures $emails, private Declarations $declarations, private Compliance $compliance) {}

    // The file plan.

    public function filePlan(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('records-management.file-plan', $this->planChoices($institution) + ['imports' => FilePlanImport::where('institution_id', $institution)->with('source', 'status', 'importedBy')->latest('created_at')->limit(10)->get()]);
    }

    private function planChoices(string $institution): array
    {
        return ['nodes' => $this->plan->tree($institution), 'types' => $this->choices('file-plan-node-type'), 'statuses' => $this->choices('file-plan-status'),
            'actions' => $this->choices('disposal-action-type'), 'classes' => $this->classes($institution), 'activities' => Activity::where('institution_id', $institution)->whereNull('deleted_at')->get()
                ->sortBy(fn ($a) => EntityKinds::label($a))->values()];
    }

    public function node(Request $request, FilePlanNode $node): View
    {
        $this->own($request, $node->institution_id);

        return view('records-management.node', $this->planChoices($node->institution_id) + ['node' => $node->load('nodeType', 'status', 'activity', 'disposalClass', 'disposalAction', 'parent'), 'trail' => $this->plan->breadcrumb($node),
            'children' => $node->children()->with('nodeType')->get(), 'placements' => $node->placements()->with('record', 'placedBy', 'rule')->orderByDesc('placed_at')->paginate(50)]);
    }

    public function saveNode(Request $request, ?FilePlanNode $node = null): RedirectResponse
    {
        $node && $this->own($request, $node->institution_id);
        $data = $request->validate(['code' => ['required', 'string', 'max:50'], 'title' => ['required', 'string', 'max:255'], 'node_type' => ['nullable', 'string', 'max:32'],
            'parent_id' => ['nullable', 'uuid'], 'activity_id' => ['nullable', 'uuid'], 'description' => ['nullable', 'string', 'max:20000'], 'disposal_class_id' => ['nullable', 'uuid'],
            'retention_period' => ['nullable', 'string', 'max:100'], 'disposal_action' => ['nullable', 'string', 'max:32'], 'status' => ['nullable', 'string', 'max:32'],
            'source_department' => ['nullable', 'string', 'max:255'], 'source_agency_code' => ['nullable', 'string', 'max:50'], 'position' => ['nullable', 'integer']]);

        return $this->attempt(fn () => $this->plan->save($request->user(), $data, $node), __('Saved.'));
    }

    public function removeNode(Request $request, FilePlanNode $node): RedirectResponse
    {
        $this->own($request, $node->institution_id);

        return $this->attempt(fn () => $this->plan->delete($request->user(), $node), __('Removed.'), 'records-management.file-plan');
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', 'in:spreadsheet,directory,xml'], 'file' => ['required', 'file', 'max:20480'], 'department' => ['nullable', 'string', 'max:255'],
            'agency_code' => ['nullable', 'string', 'max:50'], 'node_type' => ['nullable', 'string', 'max:32'], 'place_records' => ['nullable', 'boolean']]);
        $import = null;
        $back = $this->attempt(function () use ($request, $data, &$import) {
            $import = match ($data['source']) {
                'spreadsheet' => $this->plan->importSpreadsheet($request->user(), $request->file('file'), $data),
                'directory' => $this->plan->importListing($request->user(), $request->file('file'), $data),
                'xml' => $this->plan->importXml($request->user(), $request->file('file'), $data),
            };
        }, __('Imported.'));

        return $import ? back()->with('status', __(':n of :total nodes imported; :placed records placed.', ['n' => $import->imported_nodes, 'total' => $import->total_nodes, 'placed' => $import->linked_records]))
            ->with('import_errors', $import->errors ?? []) : $back;
    }

    // A record's place, declaration and vital record.

    public function recordStep(Request $request, RecordResource $record, string $step): RedirectResponse
    {
        $this->own($request, $record->institution_id);
        abort_unless($request->user()->may('delete', 'description', $record), 403);
        $user = $request->user();

        return match ($step) {
            'place' => $this->attempt(fn () => $this->plan->place($user, $record, FilePlanNode::where('institution_id', $record->institution_id)->findOrFail($request->input('file_plan_node_id'))), __('Placed.')),
            'unplace' => $this->attempt(fn () => $this->plan->unplace($user, $record), __('Removed from the file plan.')),
            'classify' => $this->attempt(fn () => $this->rules->classify($user, $record) ?? throw new InvalidArgumentException(__('No rule placed it.')), __('Placed by a rule.')),
            'declare' => $this->attempt(fn () => $this->declarations->request($user, $record, $request->input('notes')), __('Its declaration awaits approval.')),
            'vital' => $this->attempt(fn () => $this->declarations->flagVital($user, $record, (string) $request->input('reason'), (int) $request->input('review_cycle_days', 365)), __('Flagged as a vital record.')),
        };
    }

    public function declarationStep(Request $request, RecordDeclaration $declaration, string $step): RedirectResponse
    {
        $this->own($request, $declaration->record->institution_id);

        return $this->attempt(fn () => $this->declarations->decide($request->user(), $declaration, $step === 'approve', $request->input('notes')), $step === 'approve' ? __('Declared a record.') : __('Rejected.'));
    }

    public function vitalStep(Request $request, VitalRecord $vital, string $step): RedirectResponse
    {
        $this->own($request, $vital->record->institution_id);

        return match ($step) {
            'review' => $this->attempt(fn () => $this->declarations->reviewVital($request->user(), $vital), __('Reviewed.')),
            'unflag' => $this->attempt(fn () => $this->declarations->unflagVital($request->user(), $vital), __('No longer a vital record.')),
        };
    }

    /** Declarations awaiting approval, declared records, and vital records with their reviews. */
    public function declarations(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $records = fn ($q) => $q->where('institution_id', $institution)->whereNull('deleted_at');

        return view('records-management.declarations', [
            'pending' => RecordDeclaration::whereHas('record', $records)->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'declaration-status/pending-approval'))->with('record', 'requestedBy')->get(),
            'declared' => RecordDeclaration::whereHas('record', $records)->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'declaration-status/declared'))->with('record', 'declaredBy')->latest('declared_at')->limit(100)->get(),
            'vital' => VitalRecord::whereHas('record', $records)->where('is_active', true)->with('record', 'lastReviewedBy')->orderBy('next_review_on')->get()]);
    }

    // Classification rules.

    public function rules(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('records-management.rules', ['rules' => ClassificationRule::where('institution_id', $institution)->with('ruleType', 'node', 'disposalClass', 'applyOn')->orderByDesc('priority')->get(),
            'types' => $this->choices('classification-rule-type'), 'uses' => $this->choices('classification-apply-on'), 'nodes' => $this->plan->tree($institution), 'classes' => $this->classes($institution),
            'log' => ClassificationLogEntry::whereHas('rule', fn ($q) => $q->where('institution_id', $institution))->with('rule', 'node')->latest('classified_at')->limit(30)->get()]);
    }

    public function saveRule(Request $request, ?ClassificationRule $rule = null): RedirectResponse
    {
        $rule && $this->own($request, $rule->institution_id);
        if ($rule && $request->boolean('remove')) {
            return $this->attempt(fn () => $this->rules->delete($request->user(), $rule), __('Removed.'));
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'rule_type' => ['required', 'string', 'max:32'],
            'match_pattern' => ['required', 'string', 'max:1024'], 'file_plan_node_id' => ['required', 'uuid'], 'disposal_class_id' => ['nullable', 'uuid'], 'priority' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'], 'apply_on' => ['nullable', 'string', 'max:32']]);

        return $this->attempt(fn () => $this->rules->save($request->user(), $data, $rule), __('Saved.'));
    }

    public function classifyAll(Request $request): RedirectResponse
    {
        $placed = $this->rules->classifyAll($request->user());

        return back()->with('status', trans_choice(':count record placed by the rules.|:count records placed by the rules.', $placed, ['count' => $placed]));
    }

    // Captured e-mail.

    public function emails(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('records-management.emails', ['emails' => EmailCapture::where('institution_id', $institution)->with('status', 'source', 'node', 'record')->latest()->paginate(50)]);
    }

    public function capture(Request $request): RedirectResponse
    {
        $request->validate(['files' => ['required', 'array', 'max:50'], 'files.*' => ['file', 'max:51200']]);
        $done = 0;
        $errors = [];
        foreach ($request->file('files') as $file) {
            try {
                $this->emails->capture($request->user(), $file);
                $done++;
            } catch (InvalidArgumentException $e) {
                $errors[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        return back()->with('status', trans_choice(':count message captured.|:count messages captured.', $done, ['count' => $done]))->withErrors($errors ? ['records' => implode(' ', $errors)] : []);
    }

    public function email(Request $request, EmailCapture $email): View
    {
        $this->own($request, $email->institution_id);

        return view('records-management.email', ['email' => $email->load('status', 'source', 'node', 'record', 'disposalClass', 'capturedBy'), 'nodes' => $this->plan->tree($email->institution_id),
            'classes' => $this->classes($email->institution_id)]);
    }

    public function emailStep(Request $request, EmailCapture $email, string $step): RedirectResponse
    {
        $this->own($request, $email->institution_id);
        $user = $request->user();
        if ($step === 'declare') {
            $parent = $request->filled('parent_iri') ? RecordResource::where(['institution_id' => $email->institution_id, 'iri' => trim($request->input('parent_iri'))])->first() : null;
            if ($request->filled('parent_iri') && ! $parent) {
                return back()->withErrors(['records' => __('No record has that IRI.')]);
            }
            try {
                $record = $this->emails->declare($user, $email, $request->input('language', 'en'), $parent);
            } catch (InvalidArgumentException $e) {
                return back()->withErrors(['records' => $e->getMessage()]);
            }

            return redirect()->route('records.show', $record)->with('status', __('Declared a record.'));
        }

        return match ($step) {
            'classify' => $this->attempt(fn () => $this->emails->classify($user, $email, (string) $request->input('file_plan_node_id'), $request->input('disposal_class_id') ?: null), __('Placed.')),
        };
    }

    /** The message as it was captured. */
    public function original(Request $request, EmailCapture $email): BinaryFileResponse
    {
        $this->own($request, $email->institution_id);

        return response()->download(app(FileStore::class)->path($email->storage_path), $email->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-SHA256' => $email->sha256]);
    }

    // Compliance assessments.

    public function assessments(Request $request): View
    {
        return view('records-management.compliance', ['assessments' => ComplianceAssessment::where('institution_id', $request->user()->institution_id)->with('framework', 'status')->latest('assessed_at')->get(),
            'frameworks' => $this->choices('compliance-framework')]);
    }

    public function createAssessment(Request $request): RedirectResponse
    {
        $data = $request->validate(['framework' => ['required', 'string', 'max:32'], 'reference' => ['required', 'string', 'max:100'], 'title' => ['required', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'max:20000'], 'period_start' => ['nullable', 'date_format:Y-m-d'], 'period_end' => ['nullable', 'date_format:Y-m-d']]);
        $made = null;
        $back = $this->attempt(function () use ($request, $data, &$made) {
            $made = $this->compliance->create($request->user(), $data);
        }, __('Assessed.'));

        return $made ? redirect()->route('records-management.assessment', $made)->with('status', __('Assessed.')) : $back;
    }

    public function assessment(Request $request, ComplianceAssessment $assessment): View
    {
        $this->own($request, $assessment->institution_id);

        return view('records-management.assessment', ['a' => $assessment->load('framework', 'status', 'assessedBy')]);
    }

    public function assessmentStep(Request $request, ComplianceAssessment $assessment, string $step): RedirectResponse
    {
        $this->own($request, $assessment->institution_id);

        return match ($step) {
            'run' => $this->attempt(fn () => $this->compliance->run($request->user(), $assessment), __('Assessed again.')),
            'sign-off' => $this->attempt(fn () => $this->compliance->signOff($request->user(), $assessment, (string) $request->input('signed_off_by')), __('Signed off.')),
        };
    }

    private function classes(string $institution)
    {
        return DisposalClass::whereHas('schedule', fn ($q) => $q->where('institution_id', $institution))->where('is_active', true)->orderBy('reference')->get();
    }

    private function choices(string $vocabulary)
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->get();
    }

    private function own(Request $request, string $institutionId): void
    {
        abort_unless($institutionId === $request->user()->institution_id, 404);
    }

    private function attempt(callable $do, string $done, ?string $route = null): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['records' => $e->getMessage()]);
        } catch (\UnhandledMatchError) {
            abort(404);
        }

        return ($route ? redirect()->route($route) : back())->with('status', $done);
    }
}
