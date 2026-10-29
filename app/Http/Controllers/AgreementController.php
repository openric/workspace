<?php

// OpenRiC Workspace - donor agreements: the dashboard with its filters, add, view, edit and describe, rights,
// restrictions, reminders and documents, and the reminders list (Heratio's ahg-donor-manage agreementDashboard,
// agreementAdd, agreementView, agreementEdit and agreementReminders; roadmap phase 9b, ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Agreements are staff records under the agreement permissions; the public never reaches them. The donor, the
// accessions an agreement documents and the units of description it covers are made on its page as relations.

namespace App\Http\Controllers;

use App\Description\AgreementService;
use App\Description\Bcp47;
use App\Description\Elements;
use App\Files\FileResponse;
use App\Files\FileStore;
use App\Models\Agreement;
use App\Models\AgreementDocument;
use App\Models\AgreementReminder;
use App\Models\Concept;
use App\Models\Identifier;
use App\Models\Name;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AgreementController extends Controller
{
    private const WITH = ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType', 'type.labels', 'status.labels'];

    /** Heratio's "Expiring" filter, in days. */
    private const EXPIRING = ['7', '30', '90'];

    public function __construct(private AgreementService $agreements) {}

    /** Heratio's agreement dashboard: search by number or title, filter by status, type and how soon it expires. */
    public function index(Request $request): View
    {
        $filters = ['q' => trim((string) $request->query('q', '')), 'status' => (string) $request->query('status', ''),
            'type' => (string) $request->query('type', ''), 'expiring' => (string) $request->query('expiring', '')];
        $user = $request->user();
        $like = '%'.addcslashes($filters['q'], '%_\\').'%';
        $agreements = Agreement::where('institution_id', $user->institution_id)->whereNull('deleted_at')->with(self::WITH)
            ->when($filters['q'] !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'agreements')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', Identifier::where('subject_table', 'agreements')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->whereHas('status', fn ($q) => $q->where('notation', $filters['status'])))
            ->when($filters['type'] !== '', fn (Builder $q) => $q->whereHas('type', fn ($q) => $q->where('notation', $filters['type'])))
            ->when(in_array($filters['expiring'], self::EXPIRING, true), fn (Builder $q) => $q->whereNotNull('expires_on')
                ->whereBetween('expires_on', [now()->toDateString(), now()->addDays((int) $filters['expiring'])->toDateString()]))
            ->orderByRaw('expires_on IS NULL, expires_on')->orderBy('created_at')->paginate(50)->withQueryString();
        $choices = fn (string $vocabulary) => Concept::choices(Vocab::BASE.$vocabulary.'/')->with('labels')->get();

        return view('agreements.index', ['agreements' => $agreements, 'filters' => $filters, 'statuses' => $choices('agreement-status'),
            'types' => $choices('agreement-type'), 'donors' => $agreements->mapWithKeys(fn (Agreement $a) => [$a->id => $this->agreements->donors($a)])]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'agreement'), 403);

        return view('agreements.create', ['types' => Concept::choices(Vocab::BASE.'agreement-type/')->with('labels')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->may('create', 'agreement'), 403);
        $data = $request->validate([
            'number' => ['required', 'string', 'max:255'], 'type' => ['required', 'string', 'max:64'], 'title' => ['nullable', 'string', 'max:2000'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
        ]);
        try {
            $agreement = $this->agreements->create($request->user()->institution, $data['number'], $data['type'], $data['title'] ?? null, $data['language'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['number' => $e->getMessage()]);
        }

        return redirect()->route('agreements.show', $agreement)->with('status', __('Agreement created. Add its donor, its terms and the accessions it documents.'));
    }

    public function show(Request $request, Agreement $agreement): View
    {
        $this->own($request, $agreement);
        $agreement->load([...self::WITH, 'descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.state.labels', 'identifiers.identifierType.labels',
            'repository.names.nameType', 'parent.names.nameType', 'parent.identifiers.identifierType', 'supersedes.names.nameType', 'supersedes.identifiers.identifierType',
            'rights.right.labels', 'rights.permission.labels', 'restrictions.restrictionType.labels', 'restrictions.popiaCategory.labels',
            'reminders.type.labels', 'reminders.priority.labels', 'reminders.recurrence.labels', 'reminders.status.labels', 'reminders.recipients', 'reminders.completedBy',
            'documents.documentType.labels', 'documents.uploadedBy']);
        $choices = fn (string $vocabulary) => Concept::choices(Vocab::BASE.$vocabulary.'/')->with('labels')->get();
        $user = $request->user();

        return view('agreements.show', ['agreement' => $agreement, 'relations' => app(RelationService::class)->of($agreement),
            'donors' => $this->agreements->donors($agreement), 'canUpdate' => $user->may('update', 'agreement', $agreement),
            'accounts' => User::where('institution_id', $agreement->institution_id)->orderBy('name')->get()->filter(fn (User $u) => ! $u->isDisabled() && $u->may('read', 'agreement'))->values(),
            'choices' => ['right' => $choices('agreement-right'), 'permission' => $choices('agreement-permission'), 'restriction_type' => $choices('agreement-restriction-type'),
                'popia_category' => $choices('popia-category'), 'reminder_type' => $choices('reminder-type'), 'priority' => $choices('reminder-priority'),
                'recurrence' => $choices('reminder-recurrence'), 'document_type' => $choices('agreement-document-type')]]);
    }

    public function edit(Request $request, Agreement $agreement): View
    {
        $this->own($request, $agreement, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $agreement->load(self::WITH);
        $institution = $agreement->institution_id;

        return view('agreements.edit', ['agreement' => $agreement, 'language' => $language,
            'types' => Concept::choices(Vocab::BASE.'agreement-type/')->with('labels')->get(), 'statuses' => Concept::choices(Vocab::BASE.'agreement-status/')->with('labels')->get(),
            'repositories' => Repository::where('institution_id', $institution)->whereNull('deleted_at')->with('names.nameType')->get()
                ->sortBy(fn (Repository $r) => mb_strtolower((string) $r->described('authorised-name', ['en'])))->values(),
            'others' => Agreement::where('institution_id', $institution)->whereNull('deleted_at')->where('id', '<>', $agreement->id)->with(self::WITH)->get()
                ->sortBy(fn (Agreement $a) => (string) $a->number())->values()]);
    }

    public function update(Request $request, Agreement $agreement): RedirectResponse
    {
        $this->own($request, $agreement, 'update');
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'number' => ['required', 'string', 'max:255'], 'type' => ['required', 'string', 'max:64'], 'status' => ['required', 'string', 'max:64'],
            'repository' => ['nullable', 'uuid'], 'parent' => ['nullable', 'uuid'], 'supersedes' => ['nullable', 'uuid'],
            ...array_fill_keys(Agreement::DATES, ['nullable', 'string', 'max:10']),
            ...array_fill_keys(['transfer_method', 'received_by', 'legal_representative', 'legal_representative_title', 'repository_representative',
                'repository_representative_title', 'donor_signature_name', 'repository_signature_name', 'witness_name'], ['nullable', 'string', 'max:500']),
            'has_financial_terms' => ['nullable', 'boolean'], 'purchase_amount' => ['nullable', 'string', 'max:20'], 'currency' => ['nullable', 'string', 'max:3'],
            'is_template' => ['nullable', 'boolean'],
            'values' => ['array'], 'values.*' => ['nullable', 'string', 'max:65535'],
        ]);
        try {
            DB::transaction(function () use ($agreement, $data, $request) {
                $this->agreements->update($agreement, $data, $request->user());
                $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::AGREEMENT)));
                $this->agreements->describe($agreement, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('agreements.show', $agreement)->with('status', __('Agreement saved.'));
    }

    public function addRow(Request $request, Agreement $agreement, string $rowKind): RedirectResponse
    {
        $this->own($request, $agreement, 'update');
        $data = $request->validate(['row' => ['array'], 'row.*' => ['nullable', 'string', 'max:65535']]);

        return $this->attempt($agreement, fn () => $this->agreements->addRow($agreement, $rowKind, $data['row'] ?? [], $request->user()), __('Added.'), $rowKind);
    }

    public function removeRow(Request $request, Agreement $agreement, string $rowKind, string $row): RedirectResponse
    {
        $this->own($request, $agreement, 'update');

        return $this->attempt($agreement, fn () => $this->agreements->removeRow($agreement, $rowKind, $row, $request->user()), __('Removed.'), $rowKind);
    }

    public function addReminder(Request $request, Agreement $agreement): RedirectResponse
    {
        $this->own($request, $agreement, 'update');
        $data = $request->validate(['row' => ['array'], 'row.recipients' => ['array'], 'row.recipients.*' => ['uuid'],
            ...array_fill_keys(['row.type', 'row.priority', 'row.recurrence', 'row.subject', 'row.description', 'row.action_required', 'row.remind_on',
                'row.advance_days', 'row.recurrence_ends_on', 'row.notify_email'], ['nullable', 'string', 'max:65535'])]);

        return $this->attempt($agreement, fn () => $this->agreements->addReminder($agreement, $data['row'] ?? [], $request->user()), __('Reminder added.'), 'reminders');
    }

    /** Complete, snooze, cancel or reactivate a reminder. */
    public function reminderStatus(Request $request, Agreement $agreement, string $reminder): RedirectResponse
    {
        $this->own($request, $agreement, 'update');
        $data = $request->validate(['status' => ['required', 'in:active,completed,snoozed,cancelled'], 'completion_note' => ['nullable', 'string', 'max:65535'],
            'snoozed_until' => ['nullable', 'string', 'max:10'], 'back' => ['nullable', 'in:reminders']]);
        $row = AgreementReminder::where('agreement_id', $agreement->id)->findOrFail($reminder);
        try {
            $this->agreements->setReminderStatus($row, $data['status'], $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['agreement' => $e->getMessage()]);
        }

        return ($data['back'] ?? null) === 'reminders' ? redirect()->route('agreements.reminders')->with('status', __('Reminder saved.'))
            : redirect(route('agreements.show', $agreement).'#reminders')->with('status', __('Reminder saved.'));
    }

    public function addDocument(Request $request, Agreement $agreement): RedirectResponse
    {
        $this->own($request, $agreement, 'update');
        $data = $request->validate(['file' => ['required', 'file', 'max:'.(config('workspace.max_upload_megabytes') * 1024)],
            ...array_fill_keys(['document_type', 'title', 'description', 'document_date', 'version', 'is_signed', 'signed_on', 'signed_by',
                'is_confidential', 'access_restriction', 'sha256'], ['nullable', 'string', 'max:65535'])]);
        $upload = $request->file('file');

        return $this->attempt($agreement, fn () => $this->agreements->addDocument($agreement, $upload->getRealPath(), $upload->getClientOriginalName(), $data, $request->user()),
            __('Document kept.'), 'documents');
    }

    /** A document: a confidential one only for who may change the agreement. */
    public function document(Request $request, Agreement $agreement, string $document): BinaryFileResponse
    {
        $this->own($request, $agreement);
        $file = AgreementDocument::where('agreement_id', $agreement->id)->findOrFail($document);
        abort_if($file->is_confidential && ! $request->user()->may('update', 'agreement', $agreement), 403);
        $path = app(FileStore::class)->path($file->storage_path);
        abort_unless(is_file($path), 404);

        return FileResponse::make($file, $path, $request->boolean('download'));
    }

    public function removeDocument(Request $request, Agreement $agreement, string $document): RedirectResponse
    {
        $this->own($request, $agreement, 'update');

        return $this->attempt($agreement, fn () => $this->agreements->removeDocument($agreement, $document, $request->user()), __('Removed.'), 'documents');
    }

    /** Heratio's agreement reminders: every reminder of the institution's agreements, the next due first. */
    public function reminders(Request $request): View
    {
        $status = (string) $request->query('status', 'open');
        $reminders = AgreementReminder::where('institution_id', $request->user()->institution_id)
            ->whereHas('agreement', fn ($q) => $q->whereNull('deleted_at'))
            ->when($status === 'open', fn ($q) => $q->whereHas('status', fn ($q) => $q->whereIn('notation', ['active', 'snoozed'])))
            ->when(! in_array($status, ['open', ''], true), fn ($q) => $q->whereHas('status', fn ($q) => $q->where('notation', $status)))
            ->with(['agreement' => fn ($q) => $q->with(self::WITH), 'type.labels', 'priority.labels', 'status.labels', 'recurrence.labels'])
            ->orderBy('remind_on')->paginate(50)->withQueryString();

        return view('agreements.reminders', ['reminders' => $reminders, 'status' => $status,
            'statuses' => Concept::choices(Vocab::BASE.'reminder-status/')->with('labels')->get()]);
    }

    private function attempt(Agreement $agreement, callable $change, string $status, string $section): RedirectResponse
    {
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            return redirect(route('agreements.show', $agreement).'#'.$section)->withErrors(['agreement' => ucfirst($e->getMessage()).'.']);
        }

        return redirect(route('agreements.show', $agreement).'#'.$section)->with('status', $status);
    }

    /** Another institution's agreement, or a deleted one, does not exist here (404); without the permission, 403. */
    private function own(Request $request, Agreement $agreement, string $action = 'read'): void
    {
        abort_unless($agreement->institution_id === $request->user()->institution_id && $agreement->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'agreement', $agreement), 403);
    }
}
