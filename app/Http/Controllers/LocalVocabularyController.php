<?php

// OpenRiC Workspace - the institution's own vocabularies: list, create, browse, export and import (ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Any account of the institution reads its vocabularies, as Heratio's term
// browse is open; creating and changing need the vocabulary permissions.
// The bundled vocabularies are not here: they change only by import
// (VocabularyController publishes them).

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Models\Concept;
use App\Models\Vocabulary;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use App\Vocabulary\LocalSkos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class LocalVocabularyController extends Controller
{
    public function __construct(private ConceptService $concepts) {}

    public function index(Request $request): View
    {
        $vocabularies = Vocabulary::where('institution_id', $request->user()->institution_id)->with('kind.labels')
            ->withCount(['concepts', 'concepts as current_count' => fn ($q) => $q->whereNull('deprecated_at')])->orderBy('title')->get();

        return view('vocabularies.index', ['vocabularies' => $vocabularies->groupBy('kind_id'), 'kinds' => $this->kinds()]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'vocabulary'), 403);

        return view('vocabularies.create', ['kinds' => $this->kinds(), 'kind' => (string) $request->query('kind', 'subject')]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->may('create', 'vocabulary'), 403);
        $data = $this->validated($request, true);
        try {
            $vocabulary = $this->concepts->createScheme($request->user()->institution, $data['kind'], $data['title'], $data['language'], $data['description'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route('vocabularies.show', $vocabulary)->with('status', 'Vocabulary created.');
    }

    /** Browse its concepts: search labels, alternative labels and codes; or the hierarchy (view=tree); deprecated ones on request. */
    public function show(Request $request, Vocabulary $vocabulary): View
    {
        $this->own($request, $vocabulary);
        $query = trim((string) $request->query('q', ''));
        $deprecated = $request->boolean('deprecated');
        $tree = $request->query('view') === 'tree';
        $concepts = $vocabulary->concepts()->with('labels', 'broader.labels')
            ->when(! $deprecated, fn ($q) => $q->whereNull('deprecated_at'));
        if ($query !== '') {
            $like = '%'.addcslashes($query, '%_\\').'%';
            $concepts->where(fn ($q) => $q
                ->whereIn('id', DB::table('concept_labels')->where('pref_label', 'ilike', $like)->select('concept_id'))
                ->orWhereIn('id', DB::table('concept_alt_labels')->where('label', 'ilike', $like)->select('concept_id'))
                ->orWhere('notation', 'ilike', $like));
        }
        // ponytail: every matching concept on one page, sorted in PHP; a vocabulary past some thousands of concepts wants paging in SQL.
        $concepts = $concepts->get()->sortBy(fn (Concept $c) => mb_strtolower($c->anyLabel([app()->getLocale(), 'en'])))->values();
        $uses = DB::table('relations')->where('target_table', 'concepts')->whereIn('target_id', $concepts->pluck('id'))
            ->groupBy('target_id')->selectRaw('target_id, count(*) as n')->pluck('n', 'target_id');

        return view('vocabularies.show', ['vocabulary' => $vocabulary->load('kind.labels'), 'concepts' => $concepts, 'uses' => $uses,
            'query' => $query, 'deprecated' => $deprecated, 'tree' => $tree ? $concepts->groupBy(fn (Concept $c) => $c->broader_id ?? 'top') : null]);
    }

    public function edit(Request $request, Vocabulary $vocabulary): View
    {
        $this->own($request, $vocabulary, 'update');

        return view('vocabularies.edit', ['vocabulary' => $vocabulary]);
    }

    public function update(Request $request, Vocabulary $vocabulary): RedirectResponse
    {
        $this->own($request, $vocabulary, 'update');
        $data = $this->validated($request, false);
        try {
            $this->concepts->updateScheme($vocabulary, $data['title'], $data['language'], $data['description'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route('vocabularies.show', $vocabulary)->with('status', 'Vocabulary saved.');
    }

    public function export(Request $request, Vocabulary $vocabulary, string $format): Response
    {
        $this->own($request, $vocabulary);
        [, $mediaType, $extension] = LocalSkos::FORMATS[$format] ?? abort(404);
        $file = Str::slug($vocabulary->title) ?: 'vocabulary';

        return response(app(LocalSkos::class)->export($vocabulary, $format), 200, [
            'Content-Type' => $mediaType.'; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'.'.$extension.'"',
        ]);
    }

    public function importForm(Request $request, Vocabulary $vocabulary): View
    {
        $this->own($request, $vocabulary, 'create');

        return view('vocabularies.import', ['vocabulary' => $vocabulary]);
    }

    public function import(Request $request, Vocabulary $vocabulary): RedirectResponse
    {
        $this->own($request, $vocabulary, 'create');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'format' => ['required', Rule::in(LocalSkos::IMPORT_FORMATS)],
        ]);
        try {
            $count = app(LocalSkos::class)->import($vocabulary, (string) file_get_contents($data['file']->getRealPath()), $data['format'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('vocabularies.show', $vocabulary)->with('status', "Imported: {$count['created']} concepts added, {$count['updated']} updated.");
    }

    public function createConcept(Request $request, Vocabulary $vocabulary): View
    {
        $this->own($request, $vocabulary, 'create');
        $broader = $request->query('broader') ? $vocabulary->concepts()->with('labels')->find((string) $request->query('broader')) : null;

        return view('concepts.create', ['vocabulary' => $vocabulary, 'broader' => $broader, 'choices' => $this->broaderChoices($vocabulary)]);
    }

    public function storeConcept(Request $request, Vocabulary $vocabulary): RedirectResponse
    {
        $this->own($request, $vocabulary, 'create');
        $data = $request->validate(ConceptController::rules() + ['language' => ['required', 'string', 'max:35']]);
        try {
            $concept = $this->concepts->create($vocabulary, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['label' => $e->getMessage()]);
        }

        return redirect()->route('concepts.show', $concept)->with('status', 'Concept added.');
    }

    /** @return Collection<int, Concept> current concepts of the vocabulary, by label, for a broader-concept list */
    public static function broaderChoices(Vocabulary $vocabulary)
    {
        // ponytail: a select of every concept; a vocabulary past some thousands wants a search picker here.
        return $vocabulary->concepts()->whereNull('deprecated_at')->with('labels')->get()
            ->sortBy(fn (Concept $c) => mb_strtolower($c->anyLabel(['en'])))->values();
    }

    private function validated(Request $request, bool $withKind): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:1024'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
            'description' => ['nullable', 'string', 'max:5000'],
        ] + ($withKind ? ['kind' => ['required', Rule::in($this->kinds()->pluck('notation'))]] : []));
    }

    private function kinds()
    {
        return Concept::choices(Vocab::SCHEME_KINDS)->get();
    }

    /** Only the institution's own vocabularies are here (404); changing one needs the vocabulary permission (403). */
    private function own(Request $request, Vocabulary $vocabulary, ?string $action = null): void
    {
        abort_unless($vocabulary->institution_id !== null && $vocabulary->institution_id === $request->user()->institution_id, 404);
        abort_unless($action === null || $request->user()->may($action, 'vocabulary'), 403);
    }
}
