<?php

// OpenRiC Workspace - DOIs for units of description (roadmap phase 20c), after Heratio's ahg-doi-manage DoiController:
// the dashboard, every DOI and its state, the queue of requests to DataCite, one DOI with its log, the settings, the
// report, minting many at once, and on a unit's page its DOI minted, brought up to date, verified, made findable or
// hidden, and its funding. Who may publish descriptions works with DOIs; who administers the institution sets them up.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Dois\RecordDois;
use App\Models\Concept;
use App\Models\DoiQueueItem;
use App\Models\RecordDoi;
use App\Models\RecordFunding;
use App\Models\RecordResource;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class DoiController extends Controller
{
    public function __construct(private RecordDois $dois) {}

    public function index(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $states = Concept::choices(Vocab::BASE.'doi-state/')->get();
        $counts = RecordDoi::where('institution_id', $institution)->selectRaw('state_id, count(*) as n')->groupBy('state_id')->pluck('n', 'state_id');
        $queue = DoiQueueItem::where('institution_id', $institution)->selectRaw('status_id, count(*) as n')->groupBy('status_id')->pluck('n', 'status_id');

        return view('dois.index', ['states' => $states, 'counts' => $counts, 'queue' => $queue, 'queueStatuses' => Concept::choices(Vocab::BASE.'doi-queue-status/')->get(),
            'recent' => RecordDoi::where('institution_id', $institution)->with('record', 'state.labels')->orderByDesc('minted_at')->limit(10)->get(),
            'account' => $this->dois->account($institution), 'settings' => $this->dois->settings($institution)]);
    }

    public function browse(Request $request): View
    {
        $state = (string) $request->query('state');
        $q = trim((string) $request->query('q'));

        return view('dois.browse', ['states' => Concept::choices(Vocab::BASE.'doi-state/')->get(), 'state' => $state, 'q' => $q,
            'dois' => RecordDoi::where('institution_id', $request->user()->institution_id)->with('record', 'state.labels')
                ->when($state !== '', fn ($w) => $w->whereHas('state', fn ($s) => $s->where('notation', $state)))
                ->when($q !== '', fn ($w) => $w->where('doi', 'ilike', '%'.addcslashes($q, '%_\\').'%'))->orderByDesc('minted_at')->paginate(50)->withQueryString()]);
    }

    public function queue(Request $request): View
    {
        $status = (string) $request->query('status');

        return view('dois.queue', ['statuses' => Concept::choices(Vocab::BASE.'doi-queue-status/')->get(), 'status' => $status,
            'items' => DoiQueueItem::where('institution_id', $request->user()->institution_id)->with('record', 'action.labels', 'status.labels', 'createdBy')
                ->when($status !== '', fn ($w) => $w->whereHas('status', fn ($s) => $s->where('notation', $status)))->orderByDesc('created_at')->paginate(50)->withQueryString()]);
    }

    /** Try a failed request again, or take a waiting one out of the queue. */
    public function queueStep(Request $request, DoiQueueItem $item, string $step): RedirectResponse
    {
        abort_unless($item->institution_id === $request->user()->institution_id, 404);
        $step === 'retry'
            ? $item->update(['status_id' => $this->dois->concept('doi-queue-status', 'pending'), 'attempts' => 0, 'scheduled_at' => now(), 'last_error' => null])
            : $item->delete();

        return back()->with('status', $step === 'retry' ? __('It will be tried again.') : __('Taken out of the queue.'));
    }

    /** Heratio's sync: the queue worked now, rather than at the next scheduled run. */
    public function process(Request $request): RedirectResponse
    {
        $result = $this->dois->process();

        return back()->with('status', __(':done done, :failed failed.', $result));
    }

    public function show(Request $request, RecordDoi $doi): View
    {
        abort_unless($doi->institution_id === $request->user()->institution_id, 404);

        return view('dois.show', ['doi' => $doi->load('record', 'state.labels'), 'log' => DB::table('doi_log as l')->join('concepts as c', 'c.id', '=', 'l.action_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.performed_by_id')->where('l.record_doi_id', $doi->id)->orderByDesc('l.performed_at')
            ->get(['l.*', 'c.notation as action', 'u.name as performed_by'])]);
    }

    public function settings(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('dois.settings', ['settings' => $this->dois->settings($institution), 'account' => $this->dois->account($institution),
            'types' => Concept::choices(Vocab::BASE.'datacite-resource-type/')->get(), 'levels' => Concept::choices(Vocab::BASE.'level-of-description/')->get()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['default_publisher' => ['nullable', 'string', 'max:500'], 'default_resource_type_id' => ['nullable', 'uuid'],
            'suffix_pattern' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9._\/{}-]+$/'], 'auto_mint_level_ids' => ['nullable', 'array'], 'auto_mint_level_ids.*' => ['uuid'],
            'events_token' => ['nullable', 'string', 'max:2000']]);
        $settings = $this->dois->settings($request->user()->institution_id);
        $token = trim((string) ($data['events_token'] ?? ''));
        unset($data['events_token']);
        $settings->fill($data + ['auto_mint' => $request->boolean('auto_mint'), 'require_digital_object' => $request->boolean('require_digital_object'),
            'events_enabled' => $request->boolean('events_enabled'), 'auto_mint_level_ids' => array_values($data['auto_mint_level_ids'] ?? [])]);
        $token !== '' && $settings->events_token = $token;
        AuditLog::actingAs($request->user(), fn () => $settings->save());

        return back()->with('status', __('Saved.'));
    }

    public function report(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $base = fn () => RecordDoi::where('record_dois.institution_id', $institution);

        return view('dois.report', [
            'byState' => $base()->join('concepts as c', 'c.id', '=', 'record_dois.state_id')->selectRaw('c.notation, count(*) as n')->groupBy('c.notation')->pluck('n', 'notation'),
            'byMonth' => $base()->whereNotNull('minted_at')->selectRaw("to_char(minted_at, 'YYYY-MM') as month, count(*) as n")->groupBy('month')->orderByDesc('month')->limit(24)->pluck('n', 'month'),
            'byRepository' => $base()->join('record_resources as r', 'r.id', '=', 'record_dois.record_resource_id')->selectRaw('r.repository_id, count(*) as n')->groupBy('r.repository_id')->pluck('n', 'repository_id'),
            'test' => $base()->where('test', true)->count(),
            'failures' => DB::table('doi_log')->where(['institution_id' => $institution, 'succeeded' => false])->orderByDesc('performed_at')->limit(30)->get(),
            'events' => DB::table('datacite_events as e')->join('concepts as c', 'c.id', '=', 'e.status_id')->where('e.institution_id', $institution)->selectRaw('c.notation, count(*) as n')->groupBy('c.notation')->pluck('n', 'notation'),
        ]);
    }

    /** Heratio's batch mint: the published units of a repository, a level or all, that have no DOI, queued to be minted. */
    public function batch(Request $request): View|RedirectResponse
    {
        $institution = $request->user()->institution;
        if ($request->isMethod('post')) {
            $data = $request->validate(['repository_id' => ['nullable', 'uuid'], 'level_id' => ['nullable', 'uuid'], 'limit' => ['required', 'integer', 'between:1,5000']]);
            $catalogue = new PublicCatalogue($institution);
            // Chosen and paged in the database, oldest first (v0.0.146): a repository's are its units and every unit inside them.
            $units = $catalogue->query('records')->whereNotIn('e.id', RecordDoi::where('institution_id', $institution->id)->select('record_resource_id'))
                ->when($data['repository_id'] ?? null, fn ($q, $id) => $q->whereRaw('e.id IN (WITH RECURSIVE d(id) AS (SELECT id FROM record_resources WHERE repository_id = ?
                    UNION ALL SELECT c.id FROM record_resources c JOIN d ON c.parent_id = d.id) SELECT id FROM d)', [$id]))
                ->when($data['level_id'] ?? null, fn ($q, $id) => $q->where('e.level_id', $id))
                ->with(PublicCatalogue::WITH)->orderBy('e.created_at')->orderBy('e.id')->limit((int) $data['limit'])->get()->filter(fn ($u) => $catalogue->view->readable($u));
            $queued = $units->filter(fn ($u) => $this->dois->enqueue($u, 'mint', $request->user()))->count();

            return redirect()->route('dois.queue')->with('status', trans_choice(':count unit queued to be minted.|:count units queued to be minted.', $queued, ['count' => $queued]));
        }

        return view('dois.batch', ['repositories' => (new PublicCatalogue($institution))->visible('repositories'), 'levels' => Concept::choices(Vocab::BASE.'level-of-description/')->get(),
            'account' => $this->dois->account($institution->id)]);
    }

    /** On a unit: mint (findable or draft), update, publish a draft, verify or hide its DOI. */
    public function act(Request $request, RecordResource $record, string $step): RedirectResponse
    {
        abort_unless($record->institution_id === $request->user()->institution_id, 404);
        abort_unless($request->user()->may('publish', 'description', $record), 403);
        $doi = RecordDoi::where('record_resource_id', $record->id)->first();
        try {
            $message = match ($step) {
                'mint' => __('Registered: :doi', ['doi' => $this->dois->mint($record, $request->user(), true)->doi]),
                'reserve' => __('Reserved as a draft: :doi', ['doi' => $this->dois->mint($record, $request->user(), false)->doi]),
                'queue' => $this->dois->enqueue($record, 'mint', $request->user()) ? __('Queued to be minted.') : __('It is in the queue already.'),
                'update' => __('Sent to DataCite again: :state', ['state' => $this->dois->update($this->must($doi), $request->user())->state->notation]),
                'publish' => __('Made findable: :state', ['state' => $this->dois->update($this->must($doi), $request->user(), true)->state->notation]),
                'verify' => __('DataCite says it is :state.', ['state' => $this->dois->verify($this->must($doi), $request->user())->state->notation]),
                'hide' => __('Hidden: :state', ['state' => $this->dois->hide($this->must($doi), (string) $request->input('reason', ''), $request->user())->state->notation]),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['doi' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    public function addFunding(Request $request, RecordResource $record): RedirectResponse
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $request->user()->may('update', 'description', $record), 404);
        $data = $request->validate(['funder_name' => ['required', 'string', 'max:500'], 'funder_identifier' => ['nullable', 'string', 'max:500'],
            'funder_identifier_type' => ['nullable', Rule::in(Vocab::notations('funder-identifier-type'))], 'award_number' => ['nullable', 'string', 'max:255'],
            'award_uri' => ['nullable', 'url', 'max:1000'], 'award_title' => ['nullable', 'string', 'max:1000']]);
        $type = ($data['funder_identifier_type'] ?? null) ? $this->dois->concept('funder-identifier-type', $data['funder_identifier_type']) : null;
        unset($data['funder_identifier_type']);
        AuditLog::actingAs($request->user(), fn () => RecordFunding::create($data + ['record_resource_id' => $record->id, 'funder_identifier_type_id' => $type]));

        return back()->with('status', __('Funding added.'));
    }

    public function removeFunding(Request $request, RecordResource $record, RecordFunding $funding): RedirectResponse
    {
        abort_unless($funding->record_resource_id === $record->id && $record->institution_id === $request->user()->institution_id && $request->user()->may('update', 'description', $record), 404);
        AuditLog::actingAs($request->user(), fn () => $funding->delete());

        return back()->with('status', __('Funding removed.'));
    }

    private function must(?RecordDoi $doi): RecordDoi
    {
        return $doi ?? throw new RuntimeException(__('It has no DOI yet.'));
    }
}
