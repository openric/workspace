<?php

// OpenRiC Workspace - the report builder (roadmap phase 8), after Heratio's ReportBuilderController (ahg-reports: index,
// create, store, edit, update, view, cloneReport, export, apiStatusChange, apiSectionSave, apiSectionDelete,
// apiSectionReorder, templates, apiTemplateSave, apiTemplateApply, deleteTemplate) and PSIS's ahgReportBuilderPlugin.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\CustomReport;
use App\Models\CustomReportSection;
use App\Models\ReportArchive;
use App\Models\ReportSchedule;
use App\Models\ReportShare;
use App\Models\ReportTemplate;
use App\Models\ReportVersion;
use App\Models\Repository;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Reports\ReportBuilder;
use App\Reports\ReportDelivery;
use App\Reports\ReportExport;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportBuilderController extends Controller
{
    public function __construct(private ReportBuilder $builder) {}

    /** The reports the account may read, the templates, and a new report. */
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->sources($request) !== [], 403);
        $reports = CustomReport::where('institution_id', $user->institution_id)->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('shared', true))
            ->with('owner', 'status.labels')->orderByDesc('updated_at')->get()->filter(fn ($r) => ReportBuilder::mayUse($user, $r->source))->values();

        return view('reports.builder.index', ['reports' => $reports, 'sources' => $this->sources($request), 'lists' => app(ReportController::class)->listsFor($user),
            'templates' => $this->templates($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'source' => ['required_without:template', 'nullable', Rule::in(array_keys(ReportBuilder::SOURCES))],
            'template' => ['nullable', 'uuid'], 'shared' => ['nullable', 'boolean'],
        ]);
        $template = isset($data['template']) ? $this->templates($request)->firstWhere('id', $data['template']) ?? abort(404) : null;
        try {
            $report = $this->builder->create($request->user(), $data, $template);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['source' => $e->getMessage()]);
        }

        return redirect()->route('reports.builder.edit', $report)->with('status', __('Report created.'));
    }

    public function show(Request $request, CustomReport $report): View
    {
        $this->readable($request, $report);

        return view('reports.builder.show', ['report' => $report->load('owner', 'status.labels'), 'document' => $this->builder->document($report),
            'mayEdit' => $report->user_id === $request->user()->id, 'statuses' => Concept::choices(ReportBuilder::STATUSES)->with('labels')->get(),
            'frequencies' => Concept::choices(ReportDelivery::FREQUENCIES)->with('labels')->get(), 'archives' => $report->archives()->limit(20)->get(),
            'schedules' => $report->schedules()->with('frequency.labels')->get(), 'shares' => $report->shares()->get(), 'versions' => $report->versions()->with('author')->get(),
            'accounts' => User::where('institution_id', $report->institution_id)->whereNull('disabled_at')->orderBy('name')->get()->filter(fn ($u) => ReportBuilder::mayUse($u, $report->source))->values(),
            'lists' => app(ReportController::class)->listsFor($request->user())]);
    }

    public function edit(Request $request, CustomReport $report): View
    {
        $this->own($request, $report);
        $lang = Vocab::languages();

        return view('reports.builder.edit', ['report' => $report->load('sections.type.labels'), 'columns' => $this->builder->columns($report->source),
            'countBy' => $this->builder->countBy($report->source), 'sectionTypes' => Concept::choices(ReportBuilder::SECTION_TYPES)->with('labels')->get(),
            'levels' => $report->source === 'record' ? Concept::choices(Vocab::BASE.'level-of-description/')->with('labels')->get() : collect(),
            'types' => match ($report->source) {
                'agent' => Concept::choices(Vocab::BASE.'agent-type/')->with('labels')->get(),
                'container' => Concept::choices(Vocab::BASE.'container-type/')->with('labels')->get(),
                default => collect(),
            },
            'repositories' => $report->source === 'record' ? Repository::where('institution_id', $report->institution_id)->whereNull('deleted_at')->with(EntityKinds::get('repository')['with'])->get()
                ->sortBy(fn ($r) => mb_strtolower((string) EntityKinds::label($r)))->values() : collect(),
            'lang' => $lang, 'lists' => app(ReportController::class)->listsFor($request->user())]);
    }

    public function update(Request $request, CustomReport $report): RedirectResponse
    {
        $this->own($request, $report);
        $data = $request->validate($this->builder->rules($report->source));
        $report->update(['name' => $data['name'], 'description' => $data['description'] ?? null, 'columns' => array_values($data['columns']),
            'filters' => array_filter($data['filters'] ?? [], fn ($v) => $v !== null && $v !== ''), 'sort' => array_filter($data['sort'] ?? []), 'shared' => (bool) ($data['shared'] ?? false)]);

        return redirect()->route('reports.builder.edit', $report)->with('status', __('Report saved.'));
    }

    public function status(Request $request, CustomReport $report): RedirectResponse
    {
        $this->own($request, $report);
        $data = $request->validate(['status' => ['required', 'uuid']]);
        $status = Concept::choices(ReportBuilder::STATUSES)->find($data['status']) ?? abort(422);
        $report->update(['status_id' => $status->id]);

        return redirect()->route('reports.builder.show', $report)->with('status', __('Status changed to :status.', ['status' => $status->label(Vocab::languages())]));
    }

    public function copy(Request $request, CustomReport $report): RedirectResponse
    {
        $this->readable($request, $report);

        return redirect()->route('reports.builder.edit', $this->builder->copy($report, $request->user()))->with('status', __('Report copied.'));
    }

    public function saveTemplate(Request $request, CustomReport $report): RedirectResponse
    {
        $this->readable($request, $report);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $this->builder->saveAsTemplate($report, $data['name'], $request->user());

        return redirect()->route('reports.builder.show', $report)->with('status', __('Saved as the template :name.', ['name' => $data['name']]));
    }

    public function destroy(Request $request, CustomReport $report): RedirectResponse
    {
        $this->own($request, $report);
        app(ReportDelivery::class)->remove($report);

        return redirect()->route('reports.builder.index')->with('status', __('Report removed.'));
    }

    public function addSection(Request $request, CustomReport $report): RedirectResponse
    {
        $this->own($request, $report);
        $data = $request->validate(['type' => ['required', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:20000'], 'by' => ['nullable', 'string', 'max:32']]);
        try {
            $this->builder->addSection($report, $data['type'], $data + ['config' => ['by' => $data['by'] ?? null]]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['section' => $e->getMessage()]);
        }

        return redirect()->route('reports.builder.edit', $report)->with('status', __('Section added.'));
    }

    public function updateSection(Request $request, CustomReport $report, CustomReportSection $section): RedirectResponse
    {
        $this->ownSection($request, $report, $section);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:255'], 'content' => ['nullable', 'string', 'max:20000'], 'by' => ['nullable', 'string', 'max:32']]);
        if ($section->type->notation === 'counts' && ! array_key_exists($data['by'] ?? '', $this->builder->countBy($report->source))) {
            return back()->withErrors(['section' => __('Choose what to count by.')]);
        }
        $section->update(['title' => $data['title'] ?? null, 'content' => $data['content'] ?? null, 'config' => $section->type->notation === 'counts' ? ['by' => $data['by']] : []]);

        return redirect()->route('reports.builder.edit', $report)->with('status', __('Section saved.'));
    }

    public function moveSection(Request $request, CustomReport $report, CustomReportSection $section): RedirectResponse
    {
        $this->ownSection($request, $report, $section);
        $this->builder->move($section, $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction']);

        return redirect()->route('reports.builder.edit', $report);
    }

    public function removeSection(Request $request, CustomReport $report, CustomReportSection $section): RedirectResponse
    {
        $this->ownSection($request, $report, $section);
        $section->delete();

        return redirect()->route('reports.builder.edit', $report)->with('status', __('Section removed.'));
    }

    public function export(Request $request, CustomReport $report, string $format): Response
    {
        $this->readable($request, $report);
        [$extension, $type] = ReportExport::FORMATS[$format] ?? abort(404);
        $body = app(ReportExport::class)->render($this->builder->document($report), $format);

        return response($body, 200, ['Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($report->name) ?: 'report').'.'.$extension.'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Make the report in a format now and keep the file. */
    public function generate(Request $request, CustomReport $report): RedirectResponse
    {
        $this->own($request, $report);
        $format = $request->validate(['format' => ['required', Rule::in(array_keys(ReportExport::FORMATS))]])['format'];
        app(ReportDelivery::class)->generate($report, $format, null, $request->user());

        return redirect()->route('reports.builder.show', $report)->with('status', __('The file was made and kept.'));
    }

    public function archive(Request $request, CustomReport $report, ReportArchive $archive): StreamedResponse
    {
        $this->readable($request, $report);
        abort_unless($archive->report_id === $report->id && Storage::disk('local')->exists($archive->path), 404);
        [$extension, $type] = ReportExport::FORMATS[$archive->format];

        return Storage::disk('local')->download($archive->path, (Str::slug($report->name) ?: 'report').'-'.$archive->generated_at->format('Y-m-d-Hi').'.'.$extension,
            ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function storeSchedule(Request $request, CustomReport $report): RedirectResponse
    {
        $this->own($request, $report);
        $data = $request->validate([
            'frequency' => ['required', 'string', 'max:32'], 'day_of_week' => ['nullable', 'integer', 'between:1,7'], 'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'time_of_day' => ['required', 'date_format:H:i'], 'format' => ['required', Rule::in(array_keys(ReportExport::FORMATS))],
            'recipients' => ['required', 'array', 'min:1'], 'recipients.*' => ['uuid'],
        ]);
        try {
            app(ReportDelivery::class)->schedule($report, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['schedule' => $e->getMessage()]);
        }

        return redirect()->route('reports.builder.show', $report)->with('status', __('Schedule added.'));
    }

    public function destroySchedule(Request $request, CustomReport $report, ReportSchedule $schedule): RedirectResponse
    {
        $this->own($request, $report);
        abort_unless($schedule->report_id === $report->id, 404);
        $schedule->delete();

        return redirect()->route('reports.builder.show', $report)->with('status', __('Schedule removed.'));
    }

    public function storeShare(Request $request, CustomReport $report): RedirectResponse
    {
        $this->own($request, $report);
        $days = (int) $request->validate(['days' => ['required', 'integer', 'between:1,'.ReportDelivery::SHARE_DAYS_MAX]])['days'];
        [, $token] = app(ReportDelivery::class)->share($report, $days, $request->user());

        // The link is shown once: only its hash is kept.
        return redirect()->route('reports.builder.show', $report)->with('share_link', route('reports.shared', $token));
    }

    public function revokeShare(Request $request, CustomReport $report, ReportShare $share): RedirectResponse
    {
        $this->own($request, $report);
        abort_unless($share->report_id === $report->id, 404);
        $share->update(['revoked_at' => now()]);

        return redirect()->route('reports.builder.show', $report)->with('status', __('Share link revoked.'));
    }

    /** A report opened by its share link, without signing in. */
    public function shared(string $token): Response
    {
        $report = preg_match('/^[A-Za-z0-9]{64}$/', $token) ? app(ReportDelivery::class)->open($token) : null;
        abort_if($report === null, 404);

        return response()->view('reports.builder.print', ['document' => $this->builder->document($report->load('status.labels'))])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function storeVersion(Request $request, CustomReport $report): RedirectResponse
    {
        $this->own($request, $report);
        $version = app(ReportDelivery::class)->version($report, $request->user(), $request->validate(['summary' => ['nullable', 'string', 'max:500']])['summary'] ?? null);

        return redirect()->route('reports.builder.show', $report)->with('status', __('Kept as version :number.', ['number' => $version->number]));
    }

    public function restoreVersion(Request $request, CustomReport $report, ReportVersion $version): RedirectResponse
    {
        $this->own($request, $report);
        abort_unless($version->report_id === $report->id, 404);
        app(ReportDelivery::class)->restore($version, $request->user());

        return redirect()->route('reports.builder.show', $report)->with('status', __('Version :number restored.', ['number' => $version->number]));
    }

    public function destroyTemplate(Request $request, ReportTemplate $template): RedirectResponse
    {
        abort_unless($template->institution_id === $request->user()->institution_id && $template->created_by_id === $request->user()->id, 403);
        $template->delete();

        return redirect()->route('reports.builder.index')->with('status', __('Template removed.'));
    }

    /** @return array<string, string> the data sources the account may build reports on: kind => heading */
    private function sources(Request $request): array
    {
        return collect(ReportBuilder::SOURCE_TITLES)->filter(fn ($t, $kind) => ReportBuilder::mayUse($request->user(), $kind))->map(fn ($t) => __($t))->all();
    }

    /** The bundled templates and the institution's own, on the sources the account may use. */
    private function templates(Request $request)
    {
        return ReportTemplate::where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $request->user()->institution_id))->orderBy('name')->get()
            ->filter(fn ($t) => ReportBuilder::mayUse($request->user(), $t->structure['source'] ?? ''))->values();
    }

    /** Another institution's report does not exist here (404); a private one, or one on a kind the account may not read, is forbidden. */
    private function readable(Request $request, CustomReport $report): void
    {
        abort_unless($report->institution_id === $request->user()->institution_id, 404);
        abort_unless(($report->shared || $report->user_id === $request->user()->id) && ReportBuilder::mayUse($request->user(), $report->source), 403);
    }

    /** Only the report's owner changes it. */
    private function own(Request $request, CustomReport $report): void
    {
        $this->readable($request, $report);
        abort_unless($report->user_id === $request->user()->id, 403);
    }

    private function ownSection(Request $request, CustomReport $report, CustomReportSection $section): void
    {
        $this->own($request, $report);
        abort_unless($section->report_id === $report->id, 404);
    }
}
