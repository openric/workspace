<?php

// OpenRiC Workspace - the intake of accessions: the queue with its statistics and filters, the workflow steps,
// assignment and priority, the checklist, attachments, timeline notes, and the accession settings with their
// checklist templates and numbering (PSIS's ahgAccessionManagePlugin accessionIntake module, Heratio's intake queue,
// dashboard, finalise, intake configuration and numbering; roadmap phase 9c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\AccessionAppraisalService;
use App\Description\AccessionIntakeService;
use App\Description\NumberingService;
use App\Files\FileResponse;
use App\Files\FileStore;
use App\Models\Accession;
use App\Models\AccessionAttachment;
use App\Models\AccessionChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Concept;
use App\Models\Identifier;
use App\Models\Name;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccessionIntakeController extends Controller
{
    /** PSIS's queue sorts. */
    private const SORTS = ['created_at', 'submitted_at', 'number'];

    public function __construct(private AccessionIntakeService $intake) {}

    /** PSIS's intake queue and Heratio's dashboard: the statistics, and the accessions filtered by status, priority, assignee, date and number or title. */
    public function queue(Request $request): View
    {
        $user = $request->user();
        $filters = ['status' => (string) $request->query('status', ''), 'priority' => (string) $request->query('priority', ''),
            'assigned' => (string) $request->query('assigned', ''), 'q' => trim((string) $request->query('q', '')),
            'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''), 'sort' => (string) $request->query('sort', 'created_at')];
        $day = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        $like = '%'.addcslashes($filters['q'], '%_\\').'%';
        $accessions = Accession::where('institution_id', $user->institution_id)->whereNull('deleted_at')
            ->with('names.nameType', 'descriptionValues.element', 'identifiers.identifierType', 'intakeStatus.labels', 'intakePriority.labels', 'assignedTo')
            ->when($filters['status'] !== '', fn (Builder $q) => $q->whereHas('intakeStatus', fn ($q) => $q->where('notation', $filters['status'])))
            ->when($filters['status'] === '', fn (Builder $q) => $q->whereHas('intakeStatus', fn ($q) => $q->whereNotIn('notation', ['accepted', 'rejected'])))
            ->when($filters['priority'] !== '', fn (Builder $q) => $q->whereHas('intakePriority', fn ($q) => $q->where('notation', $filters['priority'])))
            ->when($filters['assigned'] === 'me', fn (Builder $q) => $q->where('assigned_to_id', $user->id))
            ->when($filters['assigned'] === 'none', fn (Builder $q) => $q->whereNull('assigned_to_id'))
            ->when($filters['q'] !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'accessions')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', Identifier::where('subject_table', 'accessions')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($day($filters['from']), fn (Builder $q, $d) => $q->where('created_at', '>=', $d))
            ->when($day($filters['to']), fn (Builder $q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()))
            ->when($filters['sort'] === 'number', fn (Builder $q) => $q->orderBy(Identifier::select('value')->whereColumn('subject_id', 'accessions.id')
                ->where('subject_table', 'accessions')->whereHas('identifierType', fn ($q) => $q->where('iri', Vocab::IDENTIFIER_TYPES.'local'))->limit(1)))
            ->when($filters['sort'] === 'submitted_at', fn (Builder $q) => $q->orderByRaw('submitted_at DESC NULLS LAST'))
            ->orderByDesc('created_at')->paginate(30)->withQueryString();
        $choices = fn (string $vocabulary) => Concept::choices(Vocab::BASE.$vocabulary.'/')->with('labels')->get();

        return view('accessions.queue', ['accessions' => $accessions, 'filters' => $filters, 'stats' => $this->intake->stats($user->institution_id),
            'statuses' => $choices('intake-status'), 'priorities' => $choices('intake-priority'), 'processing' => DB::table('accessions as a')
                ->leftJoin('concept_labels as l', fn ($j) => $j->on('l.concept_id', '=', 'a.processing_status_id')->where('l.language', 'en'))
                ->where('a.institution_id', $user->institution_id)->whereNull('a.deleted_at')->selectRaw('l.pref_label as label, count(*) as n')->groupBy('l.pref_label')->orderByDesc('n')->get()]);
    }

    /** Submit, review, accept, reject or return. */
    public function transition(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession);
        $data = $request->validate(['to' => ['required', Rule::in(array_keys(AccessionIntakeService::TRANSITIONS))], 'text' => ['nullable', 'string', 'max:65535']]);
        $accession->load('intakeStatus.labels');

        return $this->attempt($accession, fn () => $this->intake->transition($accession, $data['to'], $data['text'] ?? null, $request->user()), __('Intake status saved.'));
    }

    public function assign(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession);
        $data = $request->validate(['assignee' => ['nullable', 'uuid'], 'priority' => ['nullable', 'string', 'max:64']]);
        $assignee = ($data['assignee'] ?? null) ? User::where('institution_id', $accession->institution_id)->findOrFail($data['assignee']) : null;

        return $this->attempt($accession, function () use ($request, $accession, $assignee, $data) {
            if ($assignee?->id !== $accession->assigned_to_id) {
                $this->intake->assign($accession, $assignee, $request->user());
            }
            if (($data['priority'] ?? '') !== '') {
                $this->intake->prioritise($accession, $data['priority'], $request->user());
            }
        }, __('Saved.'));
    }

    public function note(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession);
        $data = $request->validate(['note' => ['required', 'string', 'max:65535']]);

        return $this->attempt($accession, fn () => $this->intake->note($accession, $data['note'], $request->user()), __('Note added.'), 'timeline');
    }

    /** Apply a template, add an item, or tick, untick or remove one. */
    public function checklist(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession);
        $data = $request->validate(['action' => ['required', 'in:template,add,toggle,remove'], 'template' => ['nullable', 'uuid'], 'label' => ['nullable', 'string', 'max:500'], 'item' => ['nullable', 'uuid']]);
        $item = fn () => AccessionChecklistItem::where('accession_id', $accession->id)->findOrFail($data['item'] ?? null);

        return $this->attempt($accession, fn () => match ($data['action']) {
            'template' => $this->intake->applyTemplate($accession, ChecklistTemplate::where('institution_id', $accession->institution_id)->findOrFail($data['template'] ?? null), $request->user()),
            'add' => $this->intake->addItem($accession, (string) ($data['label'] ?? ''), $request->user()),
            'toggle' => $this->intake->toggle($item(), $request->user()),
            'remove' => $this->intake->removeItem($item(), $request->user()),
        }, __('Checklist saved.'), 'checklist');
    }

    public function attach(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession);
        $data = $request->validate(['file' => ['required', 'file', 'max:'.(config('workspace.max_upload_megabytes') * 1024)], 'category' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:65535']]);
        $upload = $request->file('file');

        return $this->attempt($accession, fn () => $this->intake->attach($accession, $upload->getRealPath(), $upload->getClientOriginalName(), $data['category'],
            $data['description'] ?? null, $request->user()), __('Attachment kept.'), 'attachments');
    }

    public function attachment(Request $request, Accession $accession, string $attachment): BinaryFileResponse
    {
        $this->own($request, $accession, 'read');
        $file = AccessionAttachment::where('accession_id', $accession->id)->findOrFail($attachment);
        $path = app(FileStore::class)->path($file->storage_path);
        abort_unless(is_file($path), 404);

        return FileResponse::make($file, $path, $request->boolean('download'));
    }

    public function detach(Request $request, Accession $accession, string $attachment): RedirectResponse
    {
        $this->own($request, $accession);
        $file = AccessionAttachment::where('accession_id', $accession->id)->findOrFail($attachment);

        return $this->attempt($accession, fn () => $this->intake->detach($file, $request->user()), __('Removed.'), 'attachments');
    }

    /** A new container for the accession's material, linked to it (PSIS's accessionContainer). */
    public function containerise(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession);
        abort_unless($request->user()->may('create', 'storage'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'type' => ['nullable', 'uuid'], 'condition' => ['nullable', 'string', 'max:64'],
            'weight_kg' => ['nullable', 'string', 'max:20'], 'item_count' => ['nullable', 'string', 'max:10'], 'barcode' => ['nullable', 'string', 'max:255'],
            'place' => ['nullable', 'uuid'], 'language' => ['required', 'string', 'max:35']]);

        return $this->attempt($accession, fn () => $this->intake->containerise($accession, $data, $request->user()), __('Container added.'), 'containers');
    }

    /** Heratio's intake configuration and numbering, and the checklist templates. */
    public function settings(Request $request): View
    {
        $institution = $request->user()->institution;
        $templates = $this->intake->templates($institution);

        return view('accessions.settings', ['institution' => $institution->fresh(), 'templates' => $templates, 'tokens' => NumberingService::TOKENS,
            'appraisalTemplates' => app(AccessionAppraisalService::class)->templates($institution),
            'sectors' => Concept::choices(Vocab::BASE.'appraisal-sector/')->with('labels')->get(),
            'priorities' => Concept::choices(Vocab::BASE.'intake-priority/')->with('labels')->get(),
            'sequences' => DB::table('numbering_sequences as s')->where('s.institution_id', $institution->id)->orderBy('s.kind')->get()]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $institution = $request->user()->institution;
        $data = $request->validate([
            'accession_numbering_mask' => ['required', 'string', 'max:100', fn ($a, $v, $fail) => NumberingService::valid((string) $v) || $fail(__('A numbering mask needs a sequence, {SEQ} or {SEQ:n}.'))],
            'agreement_numbering_mask' => ['required', 'string', 'max:100', fn ($a, $v, $fail) => NumberingService::valid((string) $v) || $fail(__('A numbering mask needs a sequence, {SEQ} or {SEQ:n}.'))],
            'accession_auto_assign' => ['nullable', 'boolean'], 'accession_requires_agreement' => ['nullable', 'boolean'], 'accession_requires_appraisal' => ['nullable', 'boolean'],
            'accession_container_barcodes' => ['nullable', 'boolean'], 'accession_rights_inheritance' => ['nullable', 'boolean'],
            'default_priority' => ['nullable', 'string', 'max:64'], 'checklist_template' => ['nullable', 'uuid'],
        ]);
        $priority = ($data['default_priority'] ?? '') === '' ? null : Concept::choices(Vocab::BASE.'intake-priority/')->where('notation', $data['default_priority'])->firstOrFail();
        $template = ($data['checklist_template'] ?? '') === '' ? null : ChecklistTemplate::where('institution_id', $institution->id)->findOrFail($data['checklist_template']);
        $institution->update(['accession_numbering_mask' => $data['accession_numbering_mask'], 'agreement_numbering_mask' => $data['agreement_numbering_mask'],
            'accession_auto_assign' => (bool) ($data['accession_auto_assign'] ?? false), 'accession_requires_agreement' => (bool) ($data['accession_requires_agreement'] ?? false),
            'accession_default_priority_id' => $priority?->id, 'accession_checklist_template_id' => $template?->id,
            'accession_requires_appraisal' => (bool) ($data['accession_requires_appraisal'] ?? false), 'accession_container_barcodes' => (bool) ($data['accession_container_barcodes'] ?? false),
            'accession_rights_inheritance' => (bool) ($data['accession_rights_inheritance'] ?? false)]);

        return redirect()->route('accessions.settings')->with('status', __('Accession settings saved.'));
    }

    public function saveTemplate(Request $request, ?string $template = null): RedirectResponse
    {
        $institution = $request->user()->institution;
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'items' => ['required', 'string', 'max:20000']]);
        $existing = $template ? ChecklistTemplate::where('institution_id', $institution->id)->findOrFail($template) : null;
        try {
            $this->intake->saveTemplate($institution, $existing, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route('accessions.settings')->withErrors(['template' => ucfirst($e->getMessage()).'.']);
        }

        return redirect(route('accessions.settings').'#templates')->with('status', __('Template saved.'));
    }

    public function removeTemplate(Request $request, string $template): RedirectResponse
    {
        ChecklistTemplate::where('institution_id', $request->user()->institution_id)->findOrFail($template)->delete();

        return redirect(route('accessions.settings').'#templates')->with('status', __('Removed.'));
    }

    private function attempt(Accession $accession, callable $change, string $status, string $section = 'intake'): RedirectResponse
    {
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            return redirect(route('accessions.show', $accession).'#'.$section)->withErrors(['accession' => ucfirst($e->getMessage()).'.']);
        }

        return redirect(route('accessions.show', $accession).'#'.$section)->with('status', $status);
    }

    private function own(Request $request, Accession $accession, string $action = 'update'): void
    {
        abort_unless($accession->institution_id === $request->user()->institution_id && $accession->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'accession', $accession), 403);
    }
}
