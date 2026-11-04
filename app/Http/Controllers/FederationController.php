<?php

// OpenRiC Workspace - federation (roadmap phase 16a), after Heratio's ahg-federation FederationController and ahg-api's
// protocol and federation index: the institution's catalogue answering peers (its signed search, its key, its protocol
// descriptor and the index of the peers it federates with), the public federated search of its peers, and, for who may
// manage the institution, what it shares, its peers, a test of each and the log of its federated searches. From 16b, the
// harvest of OAI-PMH peers and the union catalogue, its members, its records served and its search.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Federation\Discovery;
use App\Federation\FederatedSearch;
use App\Federation\Harvest;
use App\Federation\Trust;
use App\Federation\UnionCatalogue;
use App\Federation\VocabularySync;
use App\Models\Concept;
use App\Models\FederationJoinRequest;
use App\Models\FederationMember;
use App\Models\FederationPeer;
use App\Models\FederationShareSetting;
use App\Models\FederationVocabularySync;
use App\Models\Institution;
use App\Models\Vocabulary;
use App\Support\Vocab;
use App\Vocabulary\LocalSkos;
use App\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FederationController extends Controller
{
    public function __construct(private FederatedSearch $federation, private Trust $trust) {}

    /** The institution's catalogue searched by a peer: JSON, signed, CORS-open. */
    public function answer(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q'));
        $institution = $request->attributes->get('institution');
        $answer = $query === '' ? ['total' => 0, 'results' => [], 'institution' => $institution->name] : $this->federation->answer($institution, $query, (int) $request->query('limit', 20));
        $response = $answer === null ? response()->json(['error' => 'This catalogue does not share with federation peers.'], 403) : response()->json($answer, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->trust->sign($response->header('Access-Control-Allow-Origin', '*'));
    }

    /** The key the institution's answers are signed with. */
    public function key(): JsonResponse
    {
        return response()->json($this->trust->keyDocument())->header('Access-Control-Allow-Origin', '*');
    }

    /** What this catalogue offers peers, as Heratio's /open-data/protocol says its own. */
    public function protocol(Request $request): JsonResponse
    {
        $institution = $request->attributes->get('institution');

        return response()->json(['protocol' => 'Federation Query Protocol', 'protocol_version' => '1.0', 'name' => $institution->name.' (OpenRiC Workspace)', 'self' => url('/'),
            'federation' => ['surfaces' => ['search', 'union', 'vocabularies'], 'search_url' => route('federation.answer'), 'public_key_url' => route('federation.key'),
                'peer_index' => route('federation.index-public'), 'union_url' => route('federation.union-records'), 'vocabularies_url' => route('federation.vocabularies'),
                'network_url' => route('federation.network'), 'join_url' => route('federation.join'),
                'signature' => Trust::SCHEME, 'shares' => FederationShareSetting::for($institution->id)->share_enabled]], 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Access-Control-Allow-Origin', '*');
    }

    /** The peers this catalogue federates with, as Heratio's /open-data/federation lists its own. */
    public function peerIndex(Request $request): JsonResponse
    {
        $institution = $request->attributes->get('institution');
        $peers = FederationPeer::where(['institution_id' => $institution->id, 'is_active' => true])->with('type', 'peerInstitution')->orderBy('name')->limit(200)->get()
            ->map(fn (FederationPeer $p) => array_filter(['name' => $p->name, 'type' => $p->type->notation,
                'base_url' => $p->type->notation === 'institution' ? $p->peerInstitution?->base_iri : rtrim((string) $p->base_url, '/')]))->values();

        return response()->json(['@context' => ['schema' => 'https://schema.org/'], '@type' => 'schema:Dataset', 'protocol' => 'Federation Query Protocol', 'protocol_version' => '1.0',
            'name' => $institution->name.' federation peer index', 'self' => url('/'), 'peer_count' => $peers->count(), 'peers' => $peers], 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Access-Control-Allow-Origin', '*');
    }

    /** The public catalogue's search of the institution's peers. */
    public function publicSearch(Request $request): View
    {
        $query = trim((string) $request->query('q'));
        $institution = $request->attributes->get('institution');

        return view('federation.search', ['institution' => $institution, 'query' => $query, 'found' => $query === '' ? null : $this->federation->search($institution, $query, $request->user()), 'staff' => false]);
    }

    public function index(Request $request): View
    {
        $institution = $request->user()->institution;
        $query = trim((string) $request->query('q'));

        return view('federation.index', ['institution' => $institution, 'share' => FederationShareSetting::for($institution->id),
            'peers' => FederationPeer::where('institution_id', $institution->id)->with('type.labels', 'peerInstitution', 'lastStatus.labels', 'lastVerification.labels')->orderBy('priority')->orderBy('name')->get(),
            'types' => Concept::choices(Vocab::BASE.'federation-peer-type/')->get(), 'levels' => Concept::choices(Vocab::BASE.'level-of-description/')->get(),
            'institutions' => Institution::where('id', '!=', $institution->id)->orderBy('name')->get(),
            'log' => DB::table('federation_search_log')->where('institution_id', $institution->id)->orderByDesc('created_at')->limit(30)->get(),
            'sessions' => DB::table('federation_harvest_sessions')->join('federation_peers', 'federation_peers.id', '=', 'federation_harvest_sessions.peer_id')
                ->where('federation_peers.institution_id', $institution->id)->select('federation_harvest_sessions.*', 'federation_peers.name as peer_name')
                ->orderByDesc('started_at')->limit(30)->get(),
            'members' => tap(DB::table('federation_members')->where('institution_id', $institution->id)->orderByDesc('is_self')->orderBy('name')->get(), fn () => app(UnionCatalogue::class)->self($institution)),
            'unionCount' => DB::table('federation_union_records')->whereIn('member_id', DB::table('federation_members')->where('institution_id', $institution->id)->pluck('id'))->count(),
            'trustLevels' => Concept::choices(Vocab::BASE.'federation-trust-level/')->get(), 'conflictRules' => Concept::choices(Vocab::BASE.'vocabulary-conflict-rule/')->get(),
            'directions' => Concept::choices(Vocab::BASE.'vocabulary-sync-direction/')->get(),
            'vocabularies' => Vocabulary::where('institution_id', $institution->id)->orderBy('title')->get(),
            'syncs' => DB::table('federation_vocabulary_syncs')->join('federation_peers', 'federation_peers.id', '=', 'federation_vocabulary_syncs.peer_id')
                ->join('vocabularies', 'vocabularies.id', '=', 'federation_vocabulary_syncs.vocabulary_id')->where('federation_peers.institution_id', $institution->id)
                ->select('federation_vocabulary_syncs.*', 'federation_peers.name as peer_name', 'vocabularies.title as vocabulary_title')->orderBy('federation_peers.name')->get(),
            'joins' => DB::table('federation_join_requests')->where('institution_id', $institution->id)->orderByDesc('created_at')->limit(50)->get(),
            'unionQuery' => trim((string) $request->query('u')), 'unionFound' => trim((string) $request->query('u')) === '' ? null : app(UnionCatalogue::class)->search($institution, trim((string) $request->query('u'))),
            'query' => $query, 'found' => $query === '' ? null : $this->federation->search($institution, $query, $request->user())]);
    }

    public function saveShare(Request $request): RedirectResponse
    {
        $data = $request->validate(['levels' => ['nullable', 'array'], 'levels.*' => ['uuid']]);
        $levels = Concept::choices(Vocab::BASE.'level-of-description/')->whereIn('id', $data['levels'] ?? [])->pluck('id')->values()->all();
        $share = FederationShareSetting::for($request->user()->institution_id);
        $vocabularies = Vocabulary::where('institution_id', $request->user()->institution_id)->whereIn('id', (array) $request->input('vocabularies', []))->pluck('id')->values()->all();
        $share->fill(['share_enabled' => $request->boolean('share_enabled'), 'levels' => $levels ?: null, 'require_verified' => $request->boolean('require_verified'),
            'shared_vocabularies' => $vocabularies ?: null])->save();

        return back()->with('status', __('Saved.'));
    }

    public function savePeer(Request $request, ?FederationPeer $peer = null): RedirectResponse
    {
        abort_unless($peer === null || $peer->institution_id === $request->user()->institution_id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'type' => ['required', 'string'], 'base_url' => ['nullable', 'url:http,https', 'max:500'],
            'search_url' => ['nullable', 'url:http,https', 'max:500'], 'peer_institution_id' => ['nullable', 'uuid'], 'api_key' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'], 'contact_email' => ['nullable', 'email', 'max:255'], 'timeout_ms' => ['nullable', 'integer', 'between:500,60000'],
            'max_results' => ['nullable', 'integer', 'between:1,200'], 'priority' => ['nullable', 'integer', 'between:0,10000'],
            'metadata_prefix' => ['nullable', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'], 'default_set' => ['nullable', 'string', 'max:255'], 'harvest_interval_hours' => ['nullable', 'integer', 'between:1,8760'],
            'rest_base_url' => ['nullable', 'url:http,https', 'max:500'], 'record_url_template' => ['nullable', 'string', 'max:500', 'regex:#^https?://#'],
            'trust_level' => ['nullable', 'string'], 'rate_limit_seconds' => ['nullable', 'integer', 'between:1,86400'], 'allowed_surfaces' => ['nullable', 'array'],
            'allowed_surfaces.*' => ['in:search,union,vocabularies']]);
        $type = Concept::choices(Vocab::BASE.'federation-peer-type/')->where('notation', $data['type'])->first() ?? abort(422);
        $local = $type->notation === 'institution';
        if ($local && (! ($data['peer_institution_id'] ?? null) || $data['peer_institution_id'] === $request->user()->institution_id || ! Institution::find($data['peer_institution_id']))) {
            return back()->withInput()->withErrors(['federation' => __('Choose another institution on this installation.')]);
        }
        if (! $local && (! ($data['base_url'] ?? null) || ! Webhooks::publicUrl($data['base_url']) || (($data['search_url'] ?? null) && ! Webhooks::publicUrl($data['search_url'])))) {
            return back()->withInput()->withErrors(['federation' => __('Give the peer\'s public http or https address; a peer is never this server or a private network.')]);
        }
        $fields = ['name' => $data['name'], 'type_id' => $type->id, 'base_url' => $local ? null : rtrim($data['base_url'], '/'), 'search_url' => $local ? null : ($data['search_url'] ?? null),
            'peer_institution_id' => $local ? $data['peer_institution_id'] : null, 'description' => $data['description'] ?? null, 'contact_email' => $data['contact_email'] ?? null,
            'timeout_ms' => (int) ($data['timeout_ms'] ?? 5000), 'max_results' => (int) ($data['max_results'] ?? 20), 'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => $request->boolean('is_active'), 'search_enabled' => $request->boolean('search_enabled'),
            'metadata_prefix' => $data['metadata_prefix'] ?? null, 'default_set' => $data['default_set'] ?? null, 'harvest_interval_hours' => $data['harvest_interval_hours'] ?? null,
            'config' => array_filter(['rest_base_url' => $data['rest_base_url'] ?? null, 'record_url_template' => $data['record_url_template'] ?? null]) ?: null,
            'trust_level_id' => ($data['trust_level'] ?? null) ? Concept::choices(Vocab::BASE.'federation-trust-level/')->where('notation', $data['trust_level'])->value('id') : null,
            'rate_limit_seconds' => $data['rate_limit_seconds'] ?? null, 'allowed_surfaces' => array_values($data['allowed_surfaces'] ?? []) ?: null];
        if (($data['rest_base_url'] ?? null) && ! Webhooks::publicUrl($data['rest_base_url'])) {
            return back()->withInput()->withErrors(['federation' => __('Give the peer\'s public http or https address; a peer is never this server or a private network.')]);
        }
        // An API key left empty keeps the one kept: it is never shown again.
        if (filled($data['api_key'] ?? null)) {
            $fields['api_key'] = $data['api_key'];
        }
        $peer ? $peer->update($fields) : FederationPeer::create([...$fields, 'institution_id' => $request->user()->institution_id]);

        return back()->with('status', __('Saved.'));
    }

    /** Ask one peer, as a search would (Heratio's testPeer). */
    public function testPeer(Request $request, FederationPeer $peer): RedirectResponse
    {
        abort_unless($peer->institution_id === $request->user()->institution_id, 404);
        $found = $this->federation->search($request->user()->institution, 'archive', $request->user());
        $mine = collect($found['peers'])->first(fn ($p) => $p['peer']->id === $peer->id);
        $lang = Vocab::languages();

        return back()->with('status', $mine ? __(':peer: :status, :count results in :ms ms; :verification.', ['peer' => $peer->name, 'status' => $mine['status'], 'count' => $mine['count'], 'ms' => $mine['ms'],
            'verification' => $mine['verification'] ? Concept::where('iri', Vocab::BASE.'federation-verification/'.$mine['verification'])->first()?->label($lang) : __('not verified')]).($mine['error'] ? ' '.$mine['error'] : '')
            : __('The peer is switched off, or not searched.'));
    }

    /** Harvest an OAI-PMH peer now, from its last harvest or in full. */
    public function harvest(Request $request, FederationPeer $peer): RedirectResponse
    {
        abort_unless($peer->institution_id === $request->user()->institution_id, 404);
        try {
            $session = app(Harvest::class)->run($peer, $request->user(), $request->boolean('full'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['federation' => $e->getMessage()]);
        }

        return back()->with('status', __('Harvested: :total records, :created new, :updated brought up to date, :deleted deleted, :errors not taken in.', ['total' => $session->records_total,
            'created' => $session->records_created, 'updated' => $session->records_updated, 'deleted' => $session->records_deleted, 'errors' => $session->records_errors])
            .($session->error_message ? ' '.$session->error_message : ''));
    }

    public function saveMember(Request $request, ?FederationMember $member = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'base_url' => ['nullable', 'url:http,https', 'max:500'], 'contact' => ['nullable', 'string', 'max:255'],
            'share_scope' => ['nullable', 'string', 'max:4000']]);
        abort_unless($member === null || $member->institution_id === $request->user()->institution_id, 404);
        $own = $member;
        if (! ($own?->is_self) && (! ($data['base_url'] ?? null) || ! Webhooks::publicUrl($data['base_url']))) {
            return back()->withInput()->withErrors(['federation' => __('Give the member\'s public http or https address; a member is never this server or a private network.')]);
        }
        app(UnionCatalogue::class)->saveMember($request->user()->institution, [...$data, 'base_url' => $own?->is_self ? null : $data['base_url'], 'is_enabled' => $request->boolean('is_enabled')], $member?->id);

        return back()->with('status', __('Saved.'));
    }

    public function publishUnion(Request $request): RedirectResponse
    {
        return $this->unionAct($request, 'publish');
    }

    public function fetchMember(Request $request, FederationMember $member): RedirectResponse
    {
        return $this->unionAct($request, 'fetch', $member->id);
    }

    /** Put the institution's own records into the union now, or fetch a member's. */
    private function unionAct(Request $request, string $action, ?string $member = null): RedirectResponse
    {
        $union = app(UnionCatalogue::class);
        try {
            if ($action === 'publish') {
                return back()->with('status', trans_choice(':count record is in the union.|:count records are in the union.', $n = $union->publish($request->user()->institution), ['count' => $n]));
            }
            $row = DB::table('federation_members')->where(['id' => $member, 'institution_id' => $request->user()->institution_id])->first() ?? abort(404);

            return back()->with('status', trans_choice(':count record fetched.|:count records fetched.', $n = $union->fetch($row), ['count' => $n]));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['federation' => $e->getMessage()]);
        }
    }

    /** The union, served to its members and to a union hub (Heratio's /union/harvest), JSON, paged. */
    public function unionRecords(Request $request): JsonResponse
    {
        return response()->json(app(UnionCatalogue::class)->records($request->attributes->get('institution'), (int) $request->query('page', 1), (int) $request->query('per_page', 100) ?: null,
            $request->boolean('self'), $request->query('from')), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)->header('Access-Control-Allow-Origin', '*');
    }

    /** The public catalogue's search of the union. */
    public function unionSearch(Request $request): View
    {
        $query = trim((string) $request->query('q'));
        $institution = $request->attributes->get('institution');

        return view('federation.union', ['institution' => $institution, 'query' => $query, 'found' => $query === '' ? null : app(UnionCatalogue::class)->search($institution, $query, (int) $request->query('page', 1))]);
    }

    /** The vocabularies the institution shares with peers (Heratio's exportTaxonomy listing). */
    public function vocabularies(Request $request): JsonResponse
    {
        $shared = app(VocabularySync::class)->shared($request->attributes->get('institution'));

        return response()->json(['vocabularies' => $shared->map(fn (Vocabulary $v) => ['id' => $v->id, 'title' => $v->title, 'iri' => $v->iri,
            'turtle' => route('federation.vocabulary', $v->id), 'changes' => route('federation.vocabulary-changes', $v->id)])->values()], 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Access-Control-Allow-Origin', '*');
    }

    /** A shared vocabulary as SKOS Turtle. */
    public function vocabulary(Request $request, string $vocabulary)
    {
        $found = app(VocabularySync::class)->shared($request->attributes->get('institution'))->firstWhere('id', $vocabulary) ?? abort(404);

        return response(app(LocalSkos::class)->export($found, 'turtle'), 200, ['Content-Type' => 'text/turtle; charset=utf-8', 'Access-Control-Allow-Origin' => '*']);
    }

    /** What changed in a shared vocabulary since a time (Heratio's federation_vocab_change). */
    public function vocabularyChanges(Request $request, string $vocabulary): JsonResponse
    {
        $found = app(VocabularySync::class)->shared($request->attributes->get('institution'))->firstWhere('id', $vocabulary) ?? abort(404);

        return response()->json(['vocabulary' => $found->iri, 'since' => $request->query('since'), 'changes' => app(VocabularySync::class)->changes($found, $request->query('since'))],
            200, [], JSON_UNESCAPED_SLASHES)->header('Access-Control-Allow-Origin', '*');
    }

    /** The network: the institution and the peers it federates with that it trusts, as Heratio's NetworkDirectoryService lists them. */
    public function network(Request $request): JsonResponse
    {
        $institution = $request->attributes->get('institution');
        $lang = ['en'];
        $peers = FederationPeer::where(['institution_id' => $institution->id, 'is_active' => true])->with('type', 'trustLevel', 'discoveryStatus', 'peerInstitution')->orderBy('name')->get()
            ->reject(fn ($p) => $p->trustLevel?->notation === 'untrusted')
            ->map(fn (FederationPeer $p) => array_filter(['name' => $p->name, 'type' => $p->type->notation, 'base_url' => $p->peerInstitution?->base_iri ?? $p->base_url,
                'trust_level' => $p->trustLevel?->notation, 'discovery_status' => $p->discoveryStatus?->notation, 'protocol_version' => $p->protocol_version,
                'surfaces' => $p->declared_surfaces, 'maturity' => $p->maturity_grade, 'last_probed_at' => $p->last_probed_at?->toIso8601String()]))->values();
        $members = DB::table('federation_members')->where(['institution_id' => $institution->id, 'is_enabled' => true, 'is_self' => false])->orderBy('name')->get(['name', 'base_url']);

        return response()->json(['@context' => ['schema' => 'https://schema.org/'], '@type' => 'schema:Dataset', 'name' => $institution->name.' federation network',
            'self' => ['name' => $institution->name, 'base_url' => $institution->base_iri, 'protocol' => route('federation.protocol')], 'peer_count' => $peers->count(), 'peers' => $peers,
            'union_members' => $members, 'join' => route('federation.join')], 200, [], JSON_UNESCAPED_SLASHES)->header('Access-Control-Allow-Origin', '*');
    }

    /** The public form to ask to join the institution's network. */
    public function joinForm(Request $request): View
    {
        return view('federation.join', ['institution' => $request->attributes->get('institution')]);
    }

    public function join(Request $request): RedirectResponse
    {
        // A form field no person fills in: a robot that fills it is thanked and nothing is kept.
        if (filled($request->input('website'))) {
            return redirect()->route('federation.join')->with('status', __('Thank you: your request will be reviewed.'));
        }
        $data = $request->validate(['institution_name' => ['required', 'string', 'max:255'], 'contact_name' => ['required', 'string', 'max:255'], 'contact_email' => ['required', 'email', 'max:255'],
            'base_url' => ['required', 'url:http,https', 'max:500'], 'what_they_share' => ['nullable', 'string', 'max:4000'], 'notes' => ['nullable', 'string', 'max:4000']]);
        DB::table('federation_join_requests')->insert([...$data, 'id' => (string) Str::uuid7(), 'institution_id' => $request->attributes->get('institution')->id,
            'status_id' => Concept::where('iri', Vocab::BASE.'join-request-status/pending')->value('id'), 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('federation.join')->with('status', __('Thank you: your request will be reviewed.'));
    }

    /** Approve a request to join, making a peer of it, or decline it with a reason (Heratio's JoinRequestService::setStatus). */
    public function decideJoin(Request $request, FederationJoinRequest $join, string $decision): RedirectResponse
    {
        abort_unless($join->institution_id === $request->user()->institution_id, 404);
        $row = $join;
        abort_unless(Concept::find($row->status_id)?->notation === 'pending', 422);
        $peer = null;
        if ($decision === 'approve') {
            if (! Webhooks::publicUrl($row->base_url)) {
                return back()->withErrors(['federation' => __('Give the peer\'s public http or https address; a peer is never this server or a private network.')]);
            }
            $peer = FederationPeer::create(['institution_id' => $row->institution_id, 'name' => $row->institution_name, 'type_id' => Concept::where('iri', Vocab::BASE.'federation-peer-type/workspace')->value('id'),
                'base_url' => rtrim($row->base_url, '/'), 'contact_email' => $row->contact_email, 'description' => $row->what_they_share, 'is_active' => true, 'search_enabled' => true,
                'trust_level_id' => Concept::where('iri', Vocab::BASE.'federation-trust-level/basic')->value('id')]);
        }
        DB::table('federation_join_requests')->where('id', $join->id)->update(['status_id' => Concept::where('iri', Vocab::BASE.'join-request-status/'.($decision === 'approve' ? 'approved' : 'declined'))->value('id'),
            'reviewed_by_id' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => trim((string) $request->input('review_note')) ?: null, 'peer_id' => $peer?->id, 'updated_at' => now()]);

        return back()->with('status', $decision === 'approve' ? __(':name is a peer now, trusted at the basic level.', ['name' => $row->institution_name]) : __('The request was declined.'));
    }

    /** Probe a peer's protocol descriptor now, or every peer's. */
    public function probe(Request $request, ?FederationPeer $peer = null): RedirectResponse
    {
        abort_unless($peer === null || $peer->institution_id === $request->user()->institution_id, 404);
        $found = $peer ? [$peer->name => app(Discovery::class)->probe($peer)] : app(Discovery::class)->probeAll($request->user()->institution_id);

        return back()->with('status', __('Probed: :list', ['list' => collect($found)->map(fn ($s, $n) => "$n: $s")->join('; ') ?: '-']));
    }

    public function saveSync(Request $request, ?FederationVocabularySync $sync = null): RedirectResponse
    {
        $data = $request->validate(['peer_id' => ['required', 'uuid'], 'vocabulary_id' => ['required', 'uuid'], 'remote_vocabulary' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'remote_title' => ['nullable', 'string', 'max:255'], 'direction' => ['required', 'in:pull,push,both'], 'conflict_rule' => ['required', 'in:prefer-local,prefer-remote'],
            'sync_interval_hours' => ['nullable', 'integer', 'between:1,8760']]);
        $institution = $request->user()->institution_id;
        FederationPeer::where(['id' => $data['peer_id'], 'institution_id' => $institution])->exists() || abort(422);
        Vocabulary::where(['id' => $data['vocabulary_id'], 'institution_id' => $institution])->exists() || abort(422);
        $fields = ['peer_id' => $data['peer_id'], 'vocabulary_id' => $data['vocabulary_id'], 'remote_vocabulary' => $data['remote_vocabulary'], 'remote_title' => $data['remote_title'] ?? null,
            'direction_id' => Concept::where('iri', Vocab::BASE.'vocabulary-sync-direction/'.$data['direction'])->value('id'),
            'conflict_rule_id' => Concept::where('iri', Vocab::BASE.'vocabulary-conflict-rule/'.$data['conflict_rule'])->value('id'),
            'sync_enabled' => $request->boolean('sync_enabled'), 'sync_interval_hours' => $data['sync_interval_hours'] ?? null, 'updated_at' => now()];
        if ($sync) {
            $own = DB::table('federation_vocabulary_syncs')->join('federation_peers', 'federation_peers.id', '=', 'federation_vocabulary_syncs.peer_id')
                ->where(['federation_vocabulary_syncs.id' => $sync->id, 'federation_peers.institution_id' => $institution])->exists();
            abort_unless($own, 404);
            DB::table('federation_vocabulary_syncs')->where('id', $sync->id)->update($fields);
        } else {
            DB::table('federation_vocabulary_syncs')->insert([...$fields, 'id' => (string) Str::uuid7(), 'created_at' => now()]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function runSync(Request $request, FederationVocabularySync $sync): RedirectResponse
    {
        $row = DB::table('federation_vocabulary_syncs')->join('federation_peers', 'federation_peers.id', '=', 'federation_vocabulary_syncs.peer_id')
            ->where(['federation_vocabulary_syncs.id' => $sync->id, 'federation_peers.institution_id' => $request->user()->institution_id])->select('federation_vocabulary_syncs.*')->first() ?? abort(404);
        $log = app(VocabularySync::class)->run($row, $request->user());

        return back()->with('status', __('Synced: :added new, :updated updated, :conflicts the institution had already.', ['added' => $log->terms_added, 'updated' => $log->terms_updated, 'conflicts' => $log->conflicts])
            .($log->error_message ? ' '.$log->error_message : ''));
    }

    /** Forget the key pinned for a peer, so the next signed answer pins its key again. */
    public function clearPin(Request $request, FederationPeer $peer): RedirectResponse
    {
        abort_unless($peer->institution_id === $request->user()->institution_id, 404);
        $peer->update(['pinned_key_id' => null, 'key_pinned_at' => null]);

        return back()->with('status', __('The pinned key is cleared; the next signed answer pins the peer\'s key again.'));
    }
}
