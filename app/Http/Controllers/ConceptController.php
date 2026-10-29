<?php

// OpenRiC Workspace - one concept of the institution's own vocabularies: view, describe, link, move, merge, deprecate (ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Reading is open to any account of the institution; describing, linking and
// moving need update on vocabularies; merging and deprecating, which stand
// for Heratio's merge and delete, need delete.

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\ConceptMatch;
use App\Models\ConceptProtocol;
use App\Models\Place;
use App\Models\Vocabulary;
use App\Relations\RelationService;
use App\Relations\RelationTypes;
use App\Rights\CommunityLabelService;
use App\Rights\CommunityProtocols;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ConceptController extends Controller
{
    public function __construct(private ConceptService $concepts) {}

    /** The fields of a concept in one language, as ConceptService takes them. */
    public static function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:1024'],
            'notation' => ['nullable', 'string', 'max:255'],
            'broader' => ['nullable', 'uuid'],
            'definition' => ['nullable', 'string', 'max:20000'],
            'scope_note' => ['nullable', 'string', 'max:20000'],
            'history_note' => ['nullable', 'string', 'max:20000'],
            'source_note' => ['nullable', 'string', 'max:20000'],
            'display_note' => ['nullable', 'string', 'max:20000'],
            'alt_labels' => ['nullable', 'string', 'max:20000'],
            'hidden_labels' => ['nullable', 'string', 'max:20000'],
            'narrower' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function show(Request $request, Concept $concept): View
    {
        $this->own($request, $concept);
        $concept->load('labels', 'altLabels', 'matches.matchType.labels', 'vocabulary.kind.labels', 'broader.labels', 'replacedBy.labels',
            'narrower.labels');
        $relations = app(RelationService::class)->of($concept);
        $places = Place::where('place_type_id', $concept->id)->whereNull('deleted_at')->with('names.nameType')->get();
        $sameKind = Vocabulary::where(['institution_id' => $concept->institution_id, 'kind_id' => $concept->vocabulary->kind_id])
            ->whereKeyNot($concept->vocabulary_id)->orderBy('title')->get();

        return view('concepts.show', ['concept' => $concept, 'relations' => $relations, 'places' => $places, 'related' => $concept->related(),
            'otherVocabularies' => $sameKind, 'matchTypes' => Concept::choices(Vocab::MATCH_TYPES)->get(),
            'siblings' => LocalVocabularyController::broaderChoices($concept->vocabulary)->reject(fn (Concept $c) => $c->id === $concept->id)->values(),
            'protocol' => ConceptProtocol::where('concept_id', $concept->id)->with('accessCondition.labels', 'label.labels', 'community.names')->first(),
            'conditions' => Concept::choices(CommunityProtocols::CONDITIONS)->whereNotNull('broader_id')->with('labels', 'broader')->get(),
            'labelChoices' => app(CommunityLabelService::class)->choices(),
            'relationType' => $concept->vocabulary->kind->notation === 'relation-type' ? RelationTypes::get($concept->id, $concept->institution_id) : null,
            'narrowable' => $concept->vocabulary->kind->notation === 'relation-type' ? RelationTypes::narrowable() : [],
            'communities' => Agent::where('institution_id', $concept->institution_id)->whereNull('deleted_at')->with('names.nameType', 'descriptionValues.element')->get()
                ->sortBy(fn (Agent $a) => mb_strtolower((string) $a->described('agent-authorised-name', ['en'])))->values()]);
    }

    /** Heratio's community protocol on a term: an access condition, the Label, the owning community and the region. */
    public function protocol(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'update');
        $data = $request->validate([
            'access_condition' => ['required', 'string', 'max:64'], 'label' => ['nullable', 'string', 'max:64'],
            'community' => ['nullable', 'uuid'], 'region' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt($concept, fn () => app(CommunityProtocols::class)->setProtocol($concept, $data, $request->user()), __('Community protocol saved.'));
    }

    /** A concept of a relation-type vocabulary as a relation type: the bundled type it narrows, how it reads and its converse term (Heratio's converse_term). */
    public function relationType(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'update');
        $data = $request->validate([
            'base' => ['required', 'string', 'max:64'], 'language' => ['required', 'string', 'max:35'],
            'forward' => ['required', 'string', 'max:255'], 'inverse' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt($concept, fn () => app(RelationTypes::class)->define($concept, $data['base'], $data['language'], $data['forward'], $data['inverse'] ?? null,
            $request->user()), __('Relation type saved.'));
    }

    public function edit(Request $request, Concept $concept): View
    {
        $this->own($request, $concept, 'update');
        $language = (string) $request->query('language', $concept->labels()->orderBy('created_at')->value('language') ?? 'en');
        abort_unless(Bcp47::valid($language), 404);
        $concept->load('labels', 'altLabels', 'vocabulary');
        // A concept cannot be broader than itself or anything below it.
        $below = $level = [$concept->id];
        while ($level !== []) {
            $level = Concept::whereIn('broader_id', $level)->pluck('id')->all();
            $below = [...$below, ...$level];
        }

        return view('concepts.edit', ['concept' => $concept, 'language' => $language,
            'choices' => LocalVocabularyController::broaderChoices($concept->vocabulary)->reject(fn (Concept $c) => in_array($c->id, $below, true))->values()]);
    }

    public function update(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'update');
        $data = $request->validate(['language' => ['required', 'string', 'max:35']] + array_replace(self::rules(), ['label' => ['nullable', 'string', 'max:1024']]));
        try {
            $this->concepts->update($concept, $data['language'], $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['label' => $e->getMessage()]);
        }

        return redirect()->route('concepts.show', $concept)->with('status', __('Concept saved.'));
    }

    public function relate(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'update');
        $other = $this->find($request, (string) $request->validate(['related' => ['required', 'uuid']])['related']);

        return $this->attempt($concept, fn () => $this->concepts->relate($concept, $other, $request->user()), __('Related concept added.'));
    }

    public function unrelate(Request $request, Concept $concept, Concept $other): RedirectResponse
    {
        $this->own($request, $concept, 'update');

        return $this->attempt($concept, fn () => $this->concepts->unrelate($concept, $other, $request->user()), __('Related concept removed.'));
    }

    public function addMatch(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'update');
        $data = $request->validate([
            'type' => ['required', Rule::in(Concept::choices(Vocab::MATCH_TYPES)->pluck('notation'))],
            'iri' => ['required', 'string', 'max:2048'],
            'target_label' => ['nullable', 'string', 'max:1024'],
            'target_scheme' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt($concept, fn () => $this->concepts->addMatch($concept, $data['type'], $data['iri'], $data['target_label'] ?? null, $data['target_scheme'] ?? null, $request->user()), __('Match added.'));
    }

    public function removeMatch(Request $request, Concept $concept, ConceptMatch $match): RedirectResponse
    {
        $this->own($request, $concept, 'update');
        abort_unless($match->concept_id === $concept->id, 404);

        return $this->attempt($concept, fn () => $this->concepts->removeMatch($match, $request->user()), __('Match removed.'));
    }

    public function move(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'update');
        $target = Vocabulary::where(['id' => $request->validate(['vocabulary' => ['required', 'uuid']])['vocabulary'], 'institution_id' => $concept->institution_id])->first() ?? abort(404);

        return $this->attempt($concept, function () use ($concept, $target, $request) {
            $moved = $this->concepts->move($concept, $target, $request->user());

            return __($moved === 1 ? ':count concept moved to :vocabulary.' : ':count concepts moved to :vocabulary.', ['count' => $moved, 'vocabulary' => $target->title]);
        });
    }

    public function merge(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'delete');
        $kept = $this->find($request, (string) $request->validate(['into' => ['required', 'uuid']])['into']);
        try {
            $this->concepts->merge($concept, $kept, $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route('concepts.show', $concept)->withErrors(['concept' => $e->getMessage()]);
        }

        return redirect()->route('concepts.show', $kept)->with('status', __('Merged: the duplicate now points here, and its labels are alternative labels.'));
    }

    public function deprecate(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'delete');

        return $this->attempt($concept, fn () => $this->concepts->deprecate($concept, $request->user()), __('Concept deprecated: it is kept, but no longer offered.'));
    }

    public function restore(Request $request, Concept $concept): RedirectResponse
    {
        $this->own($request, $concept, 'delete');

        return $this->attempt($concept, fn () => $this->concepts->restore($concept, $request->user()), __('Concept restored.'));
    }

    /** Run a change and go back to the concept with its outcome, or what was refused. */
    private function attempt(Concept $concept, callable $change, ?string $status = null): RedirectResponse
    {
        try {
            $result = $change();
        } catch (InvalidArgumentException $e) {
            return redirect()->route('concepts.show', $concept)->withErrors(['concept' => $e->getMessage()]);
        }

        return redirect()->route('concepts.show', $concept)->with('status', is_string($result) ? $result : $status);
    }

    private function find(Request $request, string $id): Concept
    {
        return Concept::where(['id' => $id, 'institution_id' => $request->user()->institution_id])->first() ?? abort(404);
    }

    /** Only concepts of the institution's own vocabularies are here (404); changing one needs the vocabulary permission (403). */
    private function own(Request $request, Concept $concept, ?string $action = null): void
    {
        abort_unless($concept->institution_id !== null && $concept->institution_id === $request->user()->institution_id, 404);
        abort_unless($action === null || $request->user()->may($action, 'vocabulary', $concept), 403);
        // A term a community protocol withholds is not there for who may not change it (Heratio's TermProtocolGate).
        abort_unless(app(CommunityProtocols::class)->allows($request->user(), $concept, 'vocabulary'), 404);
    }
}
