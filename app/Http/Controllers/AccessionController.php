<?php

// OpenRiC Workspace - accessions: browse, register, view and describe, their CAAIS rows, donors and deaccessions, a
// description made from one, and the CSV and CAAIS exports (Heratio's ahg-accession-manage AccessionController;
// roadmap phase 9a, ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Accessions are staff records under the accession permissions; the public never reaches them. Donors, the creators
// of the material and the descriptions an accession resulted in are made on its page as relations.

namespace App\Http\Controllers;

use App\Description\AccessionService;
use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\Elements;
use App\Description\IsoCodes;
use App\Models\Accession;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Identifier;
use App\Models\Name;
use App\Models\Relation;
use App\Models\Repository;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessionController extends Controller
{
    private const WITH = ['names.nameType', 'descriptionValues.element', 'identifiers.identifierType', 'processingStatus.labels', 'processingPriority.labels', 'dates.property'];

    public function __construct(private AccessionService $accessions) {}

    /** Heratio's accession browse: search by number or title, filter by processing status. */
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $accessions = $this->search($request, $query, $status)->paginate(50)->withQueryString();

        return view('accessions.index', ['accessions' => $accessions, 'query' => $query, 'status' => $status,
            'statuses' => Concept::choices(Vocab::BASE.'processing-status/')->with('labels')->get()]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'accession'), 403);

        return view('accessions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->may('create', 'accession'), 403);
        $data = $request->validate([
            'number' => ['required', 'string', 'max:255'], 'title' => ['nullable', 'string', 'max:2000'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
        ]);
        try {
            $accession = $this->accessions->create($request->user()->institution, $data['number'], $data['title'] ?? null, $data['language'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['number' => $e->getMessage()]);
        }

        return redirect()->route('accessions.show', $accession)->with('status', __('Accession registered. Add its donor, what came in and when.'));
    }

    public function show(Request $request, Accession $accession): View
    {
        $this->own($request, $accession);
        $accession->load([...self::WITH, 'descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.state.labels', 'identifiers.identifierType.labels',
            'repository.names.nameType', 'acquisitionType.labels', 'resourceType.labels', 'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels',
            'events.eventType.labels', 'extents.extentType.labels', 'extents.unit.labels', 'extents.contentType.labels', 'extents.carrierType.labels',
            'languages', 'preservationRequirements.requirementType.labels', 'sourceConfidentiality.confidentiality.labels', 'deaccessions.scope.labels']);
        $choices = fn (string $vocabulary) => Concept::choices(Vocab::BASE.$vocabulary.'/')->with('labels')->get();

        return view('accessions.show', ['accession' => $accession, 'dateChoices' => app(DateService::class)->choices('accession'),
            'relations' => app(RelationService::class)->of($accession), 'donors' => $this->accessions->donors($accession),
            'canUpdate' => $request->user()->may('update', 'accession', $accession), 'revisions' => $this->accessions->revisions($accession),
            'mayDescribe' => $request->user()->may('create', 'description'),
            'repositories' => $this->repositories($accession->institution_id),
            'levels' => Concept::bundledThenLocal(Vocab::BASE.'level-of-description/', 'level-of-description', $accession->institution_id),
            'choices' => ['event_type' => $choices('accession-event-type'), 'extent_type' => $choices('caais-extent-type'), 'unit' => $choices('caais-extent-unit'),
                'content_type' => $choices('caais-content-type'), 'carrier_type' => $choices('caais-carrier-type'), 'requirement_type' => $choices('caais-preservation-type'),
                'confidentiality' => $choices('caais-source-confidentiality'), 'scope' => $choices('deaccession-scope'), 'languages' => IsoCodes::choices('language')]]);
    }

    public function edit(Request $request, Accession $accession): View
    {
        $this->own($request, $accession, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $accession->load(self::WITH);
        $terms = collect(AccessionService::TERMS)->map(fn (string $vocabulary) => Concept::choices(Vocab::BASE.$vocabulary.'/')->with('labels')->get());

        return view('accessions.edit', ['accession' => $accession, 'language' => $language, 'terms' => $terms, 'repositories' => $this->repositories($accession->institution_id)]);
    }

    public function update(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession, 'update');
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'number' => ['required', 'string', 'max:255'], 'repository' => ['nullable', 'uuid'],
            'acquisition_type' => ['nullable', 'string', 'max:64'], 'resource_type' => ['nullable', 'string', 'max:64'],
            'processing_status' => ['nullable', 'string', 'max:64'], 'processing_priority' => ['nullable', 'string', 'max:64'],
            'values' => ['array'], 'values.*' => ['nullable', 'string', 'max:65535'],
        ]);
        try {
            DB::transaction(function () use ($accession, $data, $request) {
                $this->accessions->update($accession, $data, $request->user());
                $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::ACCESSION)));
                $this->accessions->describe($accession, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('accessions.show', $accession)->with('status', __('Accession saved.'));
    }

    public function addRow(Request $request, Accession $accession, string $rowKind): RedirectResponse
    {
        $this->own($request, $accession, 'update');
        $data = $request->validate(['row' => ['array'], 'row.*' => ['nullable', 'string', 'max:65535']]);

        return $this->attempt($accession, fn () => $this->accessions->addRow($accession, $rowKind, $data['row'] ?? [], $request->user()), __('Added.'), "caais-$rowKind");
    }

    public function removeRow(Request $request, Accession $accession, string $rowKind, string $row): RedirectResponse
    {
        $this->own($request, $accession, 'update');

        return $this->attempt($accession, fn () => $this->accessions->removeRow($accession, $rowKind, $row, $request->user()), __('Removed.'), "caais-$rowKind");
    }

    /** CAAIS 2.1.6, for one donor. */
    public function confidentiality(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession, 'update');
        $data = $request->validate(['donor' => ['required', 'uuid'], 'confidentiality' => ['nullable', 'string', 'max:64']]);
        $donor = Agent::where('institution_id', $accession->institution_id)->findOrFail($data['donor']);

        return $this->attempt($accession, fn () => $this->accessions->setConfidentiality($accession, $donor, $data['confidentiality'] ?? null, $request->user()), __('Confidentiality saved.'), 'donors');
    }

    public function deaccession(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession, 'update');
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'], 'scope' => ['required', 'string', 'max:64'], 'date' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string', 'max:65535'], 'extent' => ['nullable', 'string', 'max:65535'], 'reason' => ['nullable', 'string', 'max:65535'],
            'language' => ['required', 'string', 'max:35'],
        ]);

        return $this->attempt($accession, fn () => $this->accessions->deaccession($accession, $data, $request->user()), __('Deaccession recorded.'), 'deaccessions');
    }

    public function removeDeaccession(Request $request, Accession $accession, string $deaccession): RedirectResponse
    {
        $this->own($request, $accession, 'update');

        return $this->attempt($accession, fn () => $this->accessions->removeDeaccession($accession, $deaccession, $request->user()), __('Removed.'), 'deaccessions');
    }

    /** Heratio's "Create an archival description from this accession". */
    public function createDescription(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession, 'update');
        abort_unless($request->user()->may('create', 'description'), 403);
        $data = $request->validate(['level' => ['required', 'string', 'max:2048'], 'repository' => ['nullable', 'uuid'], 'language' => ['required', 'string', 'max:35']]);
        $repository = ($data['repository'] ?? null) ? Repository::where('institution_id', $accession->institution_id)->whereNull('deleted_at')->findOrFail($data['repository']) : null;
        try {
            $record = $this->accessions->createDescription($accession, $data['level'], $repository, $data['language'], $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route('accessions.show', $accession)->withErrors(['accession' => $e->getMessage()]);
        }

        return redirect()->route('records.edit', $record)->with('status', __('Archival description created from accession.'));
    }

    /** Heratio's donor browse: the authority records that took part in an accession as its donor. */
    public function donors(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $ids = Relation::where(['institution_id' => $institution, 'source_table' => 'accessions', 'target_table' => 'agents'])
            ->whereHas('role', fn ($q) => $q->where('iri', 'https://openric.org/vocab/role-type/donor'))
            ->whereIn('source_id', Accession::whereNull('deleted_at')->select('id'))
            ->selectRaw('target_id, count(*) as accessions')->groupBy('target_id')->pluck('accessions', 'target_id');
        $donors = Agent::whereIn('id', $ids->keys())->whereNull('deleted_at')->with('names.nameType', 'descriptionValues.element')->get()
            ->filter(fn (Agent $a) => $request->user()->may('read', 'authority', $a))
            ->sortBy(fn (Agent $a) => mb_strtolower((string) $a->described('agent-authorised-name', ['en'])))->values();

        return view('accessions.donors', ['donors' => $donors, 'counts' => $ids]);
    }

    /** Heratio's accession CSV: number, title, acquisition date, status, priority, created and updated. */
    public function csv(Request $request): StreamedResponse
    {
        $rows = $this->search($request, '', '')->with('dates.property')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Identifier', 'Title', 'Accession Date', 'Status', 'Priority', 'Created', 'Updated']);
            foreach ($rows as $a) {
                fputcsv($out, [$a->number(), $a->described('accession-title', ['en']), $a->dates->first(fn ($d) => $d->property->notation === 'acquisition')?->display(),
                    $a->processingStatus?->label(['en']), $a->processingPriority?->label(['en']), $a->created_at->toDateTimeString(), $a->updated_at->toDateTimeString()]);
            }
            fclose($out);
        }, 'accessions-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** One accession as Heratio's CAAIS 1.0 record; with ?external=1, confidential sources are withheld. */
    public function caais(Request $request, Accession $accession): JsonResponse
    {
        $this->own($request, $accession);
        $name = 'caais-'.preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $accession->number()).'.json';

        return response()->json($this->accessions->caaisEnvelope([$this->accessions->caais($accession, $request->boolean('external'))]), 200,
            ['Content-Disposition' => 'attachment; filename="'.$name.'"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function caaisAll(Request $request): JsonResponse
    {
        $records = $this->search($request, '', '')->get()->map(fn (Accession $a) => $this->accessions->caais($a, $request->boolean('external')))->all();

        return response()->json($this->accessions->caaisEnvelope($records), 200,
            ['Content-Disposition' => 'attachment; filename="caais-'.now()->format('Ymd').'.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The institution's accessions the account may read, by number. */
    private function search(Request $request, string $query, string $status): Builder
    {
        $user = $request->user();
        $like = '%'.addcslashes($query, '%_\\').'%';

        return Accession::where('institution_id', $user->institution_id)->whereNull('deleted_at')->with(self::WITH)
            ->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'accessions')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', Identifier::where('subject_table', 'accessions')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($status !== '', fn (Builder $q) => $q->whereHas('processingStatus', fn ($q) => $q->where('notation', $status)))
            ->orderBy(Identifier::select('value')->whereColumn('subject_id', 'accessions.id')->where('subject_table', 'accessions')
                ->whereHas('identifierType', fn ($q) => $q->where('iri', Vocab::IDENTIFIER_TYPES.'local'))->limit(1));
    }

    private function repositories(string $institutionId)
    {
        return Repository::where('institution_id', $institutionId)->whereNull('deleted_at')->with('names.nameType')->get()
            ->sortBy(fn (Repository $r) => mb_strtolower((string) $r->described('authorised-name', ['en'])))->values();
    }

    private function attempt(Accession $accession, callable $change, string $status, string $section): RedirectResponse
    {
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            return redirect(route('accessions.show', $accession).'#'.$section)->withErrors(['accession' => $e->getMessage()]);
        }

        return redirect(route('accessions.show', $accession).'#'.$section)->with('status', $status);
    }

    /** Another institution's accession, or a deleted one, does not exist here (404); without the permission, 403. */
    private function own(Request $request, Accession $accession, string $action = 'read'): void
    {
        abort_unless($accession->institution_id === $request->user()->institution_id && $accession->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'accession', $accession), 403);
    }
}
