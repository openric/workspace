<?php

// OpenRiC Workspace - a researcher's notices and inbox, research documents and their templates, print templates,
// research quotas and the rights policies on records (roadmap phase 19j, ADR 0017), after Heratio's
// ResearchNotificationsController, InboxController, ResearchDocumentTemplatesController, ResearchQuotaController and
// ResearchOdrlPoliciesController.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\ResearchAccessDecision;
use App\Models\ResearchDocument;
use App\Models\ResearchDocumentTemplate;
use App\Models\ResearcherType;
use App\Models\ResearchInboxItem;
use App\Models\ResearchNotification;
use App\Models\ResearchPrintTemplate;
use App\Models\ResearchProject;
use App\Models\ResearchQuotaPolicy;
use App\Models\ResearchRightsPolicy;
use App\Models\User;
use App\Research\DocumentTemplates;
use App\Research\Inbox;
use App\Research\Journals;
use App\Research\Notifications;
use App\Research\Projects;
use App\Research\Quotas;
use App\Research\RightsPolicies;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ResearchDeskController extends Controller
{
    public function __construct(private Notifications $notifications, private Inbox $inbox, private DocumentTemplates $documents, private Quotas $quotas,
        private RightsPolicies $policies, private Projects $projects) {}

    // Notices.

    public function notices(Request $request): View
    {
        $user = $request->user();

        return view('research.notifications', ['notices' => $this->notifications->list($user, $request->query('type')), 'preferences' => $this->notifications->preferences($user),
            'digests' => $this->choices('digest-frequency'), 'type' => $request->query('type')]);
    }

    public function read(Request $request, ?ResearchNotification $notice = null): RedirectResponse
    {
        return $this->attempt(fn () => $this->notifications->read($request->user(), $notice), __('Marked read.'));
    }

    public function preferences(Request $request): RedirectResponse
    {
        $data = $request->validate(['prefs' => ['array'], 'prefs.*.in_app' => ['nullable', 'boolean'], 'prefs.*.email' => ['nullable', 'boolean'], 'prefs.*.digest' => ['nullable', 'string', 'max:32']]);

        return $this->attempt(fn () => $this->notifications->savePreferences($request->user(), $data['prefs'] ?? []), __('Saved.'));
    }

    public function openNotice(Request $request, ResearchNotification $notice): RedirectResponse
    {
        abort_unless($notice->user_id === $request->user()->id, 404);
        $this->notifications->read($request->user(), $notice);

        return $notice->link && str_starts_with($notice->link, url('/')) ? redirect()->to($notice->link) : redirect()->route('research.notifications');
    }

    // The inbox.

    public function inbox(Request $request): View
    {
        $user = $request->user();
        $status = in_array($request->query('status'), ['inbox', 'triaged', 'archived'], true) ? $request->query('status') : 'inbox';

        return view('research.inbox', ['items' => $this->inbox->items($user, $status), 'counts' => $this->inbox->counts($user), 'status' => $status, 'kinds' => $this->choices('inbox-kind'),
            'projects' => $this->projects->contributing($user)]);
    }

    public function capture(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:500'], 'body' => ['nullable', 'string', 'max:50000'], 'source_url' => ['nullable', 'string', 'max:2000'],
            'kind' => ['nullable', 'string', 'max:32'], 'file' => ['nullable', 'file', 'max:51200']]);

        return $this->attempt(fn () => $this->inbox->capture($request->user(), $data, $request->file('file')), __('Captured.'));
    }

    public function inboxStep(Request $request, ResearchInboxItem $item, string $step): RedirectResponse
    {
        return $this->attempt(fn () => $this->inbox->step($request->user(), $item, $step, $request->input('project_id')), __('Done.'));
    }

    // Research documents and their templates.

    public function documents(Request $request): View
    {
        $user = $request->user();

        return view('research.documents', ['templates' => ResearchDocumentTemplate::where('institution_id', $user->institution_id)->with('documentType')->orderBy('name')->get(),
            'documents' => ResearchDocument::where('user_id', $user->id)->with('template', 'project')->orderByDesc('updated_at')->get(), 'mayRun' => app(Journals::class)->mayRun($user),
            'types' => $this->choices('document-type')]);
    }

    public function saveTemplate(Request $request, ?ResearchDocumentTemplate $template = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless(app(Journals::class)->mayRun($user) && (! $template || $template->institution_id === $user->institution_id), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'document_type' => ['nullable', 'string', 'max:64'], 'description' => ['nullable', 'string', 'max:20000'],
            'fields' => ['required', 'string', 'max:20000']]);

        return $this->attempt(fn () => $this->documents->saveTemplate($user, $data, $template), __('Saved.'));
    }

    public function document(Request $request, ResearchDocumentTemplate $template, ?ResearchDocument $document = null): View
    {
        $user = $request->user();
        abort_unless($template->institution_id === $user->institution_id && (! $document || ($document->user_id === $user->id && $document->template_id === $template->id)), 404);

        return view('research.document', ['template' => $template, 'document' => $document, 'projects' => $this->projects->contributing($user), 'print' => $request->boolean('print')]);
    }

    public function saveDocument(Request $request, ResearchDocumentTemplate $template, ?ResearchDocument $document = null): RedirectResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:255'], 'values' => ['array'], 'values.*' => ['nullable', 'string', 'max:50000'], 'project_id' => ['nullable', 'uuid']]);
        $saved = null;
        $back = $this->attempt(function () use ($request, $template, $data, $document, &$saved) {
            $saved = $this->documents->saveDocument($request->user(), $template, $data, $document);
        }, __('Saved.'));

        return $saved ? redirect()->route('research.documents.edit', [$template, $saved])->with('status', __('Saved.')) : $back;
    }

    // Print templates.

    public function printTemplates(Request $request): View
    {
        $user = $this->mustRun($request);

        return view('research.print-templates', ['templates' => ResearchPrintTemplate::where('institution_id', $user->institution_id)->with('templateType')->orderBy('name')->get(),
            'types' => $this->choices('print-template-type'), 'placeholders' => DocumentTemplates::SLIP_PLACEHOLDERS]);
    }

    public function savePrintTemplate(Request $request, ?ResearchPrintTemplate $template = null): RedirectResponse
    {
        $user = $this->mustRun($request);
        abort_if($template && $template->institution_id !== $user->institution_id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:50'], 'template_type' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'], 'body' => ['required', 'string', 'max:20000'], 'page_size' => ['nullable', Rule::in(Vocab::notations('print-page-size'))], 'orientation' => ['nullable', Rule::in(Vocab::notations('page-orientation'))],
            'margin_mm' => ['nullable', 'integer'], 'copies_default' => ['nullable', 'integer'], 'is_default' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => $this->documents->savePrintTemplate($user, $data, $template), __('Saved.'));
    }

    // Quotas.

    public function quotas(Request $request): View
    {
        $user = $this->mustRun($request);

        return view('research.quotas', ['policies' => ResearchQuotaPolicy::where('institution_id', $user->institution_id)->with('scope', 'period')->orderBy('created_at')->get(),
            'usage' => $this->quotas->usage($user), 'scopes' => $this->choices('quota-scope'), 'periods' => $this->choices('quota-period'),
            'kinds' => ResearcherType::where('institution_id', $user->institution_id)->orderBy('position')->get(), 'accounts' => User::where('institution_id', $user->institution_id)->orderBy('name')->get(),
            'projects' => ResearchProject::where('institution_id', $user->institution_id)->orderBy('title')->get()]);
    }

    public function saveQuota(Request $request, ?ResearchQuotaPolicy $policy = null): RedirectResponse
    {
        $user = $this->mustRun($request);
        abort_if($policy && $policy->institution_id !== $user->institution_id, 404);
        if ($request->boolean('remove') && $policy) {
            return $this->attempt(fn () => $this->quotas->delete($user, $policy), __('Removed.'));
        }
        $data = $request->validate(['scope' => ['required', 'string', 'max:32'], 'scope_key' => ['nullable', 'uuid'], 'period' => ['nullable', 'string', 'max:32'],
            'max_downloads' => ['nullable', 'integer', 'min:0'], 'max_storage_mb' => ['nullable', 'integer', 'min:0', 'max:10000000'], 'soft_warn_pct' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_active' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->quotas->save($user, $data, $policy), __('Saved.'));
    }

    // Rights policies on a record.

    public function rights(Request $request, RecordResource $record): View
    {
        $user = $request->user();
        abort_unless($record->institution_id === $user->institution_id && $user->may('update', 'description', $record), 404);

        return view('research.rights-policies', ['record' => $record, 'policies' => ResearchRightsPolicy::where('record_resource_id', $record->id)->with('policyType', 'action')->get(),
            'accounts' => User::where('institution_id', $user->institution_id)->get()->keyBy('id'), 'types' => $this->choices('odrl-policy-type'), 'actions' => $this->choices('odrl-action'),
            'decisions' => ResearchAccessDecision::where('record_resource_id', $record->id)->with('user', 'action')->orderByDesc('evaluated_at')->limit(50)->get()]);
    }

    public function saveRights(Request $request, RecordResource $record, ?ResearchRightsPolicy $policy = null): RedirectResponse
    {
        abort_if($policy && $policy->record_resource_id !== $record->id, 404);
        if ($request->boolean('remove') && $policy) {
            return $this->attempt(fn () => $this->policies->delete($request->user(), $policy), __('Removed.'));
        }
        $data = $request->validate(['policy_type' => ['required', 'string', 'max:32'], 'action' => ['required', 'string', 'max:32'], 'accounts' => ['nullable', 'string', 'max:5000'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'], 'max_uses' => ['nullable', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->policies->save($request->user(), $record, $data, $policy), __('Saved.'));
    }

    private function mustRun(Request $request): User
    {
        abort_unless(app(Journals::class)->mayRun($request->user()), 403);

        return $request->user();
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
