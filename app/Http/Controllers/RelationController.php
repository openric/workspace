<?php

// OpenRiC Workspace - relate a described entity to others, prove and assert its relations (ADR 0006, WS-006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Every action needs update on the page's entity; the entity at the other end,
// and a unit given as evidence, must be one the account may read.

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDescribedEntity;
use App\Models\Concept;
use App\Models\Relation;
use App\Models\RelationAssertion;
use App\Models\RelationEvidence;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class RelationController extends Controller
{
    use ResolvesDescribedEntity;

    public function __construct(private RelationService $relations) {}

    /** Choose the kind of relation, then find the entity at the other end. */
    public function create(Request $request, string $kind, string $id): View
    {
        [$subject] = $this->subject($request, $kind, $id);
        $options = $this->relations->options($subject);
        $chosen = collect($options)->first(fn ($o) => $request->query('relate') === $o['type'].'|'.$o['side']);
        $query = (string) $request->query('q', '');

        return view('relations.create', ['subject' => $subject, 'kind' => $kind, 'options' => $options, 'chosen' => $chosen, 'query' => $query,
            'candidates' => $chosen ? $this->relations->candidates($request->user(), $chosen['kinds'], $query, $subject) : collect(),
            'choices' => $this->choices()]);
    }

    public function store(Request $request, string $kind, string $id): RedirectResponse
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $data = $request->validate([
            'relate' => ['required', 'string', 'max:100'],
            // kind:id, such as repository:01a1...
            'other' => ['required', 'string', 'regex:/^[a-z-]+:[0-9a-f-]{36}$/'],
            'basis' => ['nullable', 'string', 'max:5000'],
            'basis_language' => ['nullable', 'string', 'max:35'],
        ] + $this->rules());
        $option = collect($this->relations->options($subject))->first(fn ($o) => $data['relate'] === $o['type'].'|'.$o['side']) ?? abort(422);
        [$otherKind, $otherId] = explode(':', $data['other'], 2);
        abort_unless(in_array($otherKind, $option['kinds'], true), 422);
        $other = $this->readable($request, $otherKind, $otherId);
        [$source, $target] = $option['side'] === 'source' ? [$subject, $other] : [$other, $subject];
        try {
            $this->relations->create($source, $option['type'], $target, $data, $request->user(),
                ['basis' => $data['basis'] ?? null, 'basis_language' => $data['basis_language'] ?? null]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['relations' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', 'Relation added.');
    }

    public function update(Request $request, string $kind, string $id, Relation $relation): RedirectResponse
    {
        [$subject, $route] = $this->subjectOf($request, $kind, $id, $relation);
        try {
            $this->relations->update($relation, $request->validate($this->rules()), $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withErrors(['relations' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', 'Relation saved.');
    }

    public function destroy(Request $request, string $kind, string $id, Relation $relation): RedirectResponse
    {
        [$subject, $route] = $this->subjectOf($request, $kind, $id, $relation);
        $this->relations->remove($relation, $request->user());

        return redirect()->route($route, $subject)->with('status', 'Relation removed.');
    }

    public function assert(Request $request, string $kind, string $id, Relation $relation): RedirectResponse
    {
        [$subject, $route] = $this->subjectOf($request, $kind, $id, $relation);
        $data = $request->validate([
            'holds' => ['required', Rule::in(['yes', 'no'])],
            'basis' => ['nullable', 'string', 'max:5000'],
            'basis_language' => ['nullable', 'string', 'max:35'],
            'supersedes' => ['nullable', 'uuid'],
        ]);
        $supersedes = isset($data['supersedes']) ? RelationAssertion::where('relation_id', $relation->id)->findOrFail($data['supersedes']) : null;
        try {
            $this->relations->assert($relation, $request->user(), $data['holds'] === 'yes', $data['basis'] ?? null, $data['basis_language'] ?? null, $supersedes);
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withErrors(['relations' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', 'Assertion recorded.');
    }

    public function review(Request $request, string $kind, string $id, Relation $relation, RelationAssertion $assertion): RedirectResponse
    {
        [$subject, $route] = $this->subjectOf($request, $kind, $id, $relation);
        abort_unless($assertion->relation_id === $relation->id, 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'reject'])]]);
        try {
            $this->relations->review($assertion, $data['decision'] === 'accept', $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withErrors(['relations' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', $data['decision'] === 'accept' ? 'Assertion accepted.' : 'Assertion rejected.');
    }

    /** Find a unit of description that proves the relation. */
    public function evidenceForm(Request $request, string $kind, string $id, Relation $relation): View
    {
        [$subject] = $this->subjectOf($request, $kind, $id, $relation);
        $query = (string) $request->query('q', '');

        return view('relations.evidence', ['subject' => $subject, 'kind' => $kind, 'relation' => $relation, 'query' => $query,
            'candidates' => $this->relations->candidates($request->user(), ['record'], $query)]);
    }

    public function addEvidence(Request $request, string $kind, string $id, Relation $relation): RedirectResponse
    {
        [$subject, $route] = $this->subjectOf($request, $kind, $id, $relation);
        $record = $this->readable($request, 'record', (string) $request->validate(['record' => ['required', 'uuid']])['record']);
        try {
            $this->relations->addEvidence($relation, $record, $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect()->route($route, $subject)->withErrors(['relations' => $e->getMessage()]);
        }

        return redirect()->route($route, $subject)->with('status', 'Evidence added.');
    }

    public function removeEvidence(Request $request, string $kind, string $id, Relation $relation, RelationEvidence $evidence): RedirectResponse
    {
        [$subject, $route] = $this->subjectOf($request, $kind, $id, $relation);
        abort_unless($evidence->relation_id === $relation->id, 404);
        $this->relations->removeEvidence($evidence, $request->user());

        return redirect()->route($route, $subject)->with('status', 'Evidence removed.');
    }

    /** The page's entity, which must be one end of the relation. */
    private function subjectOf(Request $request, string $kind, string $id, Relation $relation): array
    {
        [$subject, $route] = $this->subject($request, $kind, $id);
        $at = fn (string $side) => $relation->getAttribute($side.'_table') === $subject->getTable() && $relation->getAttribute($side.'_id') === $subject->getKey();
        abort_unless($at('source') || $at('target'), 404);

        return [$subject, $route];
    }

    /** An entity of this institution the account may read, or 404. */
    private function readable(Request $request, string $kind, string $id): Model
    {
        [$class, , $permission] = EntityKinds::get($kind) ?? abort(404);
        $entity = $class::find($id);
        abort_unless($entity && $entity->institution_id === $request->user()->institution_id && $entity->deleted_at === null
            && $request->user()->may('read', $permission, $entity), 404);

        return $entity;
    }

    private function rules(): array
    {
        return [
            'role' => ['nullable', 'string', 'max:64'],
            'state' => ['nullable', 'string', 'max:64'],
            'certainty' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'string', 'max:64'],
            'to' => ['nullable', 'string', 'max:64'],
            'source_of_information' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'language' => ['nullable', 'string', 'max:35'],
        ];
    }

    /** @return array{roles: Collection, states: Collection, certainties: Collection} */
    public static function choices(): array
    {
        return [
            'roles' => Concept::choices('https://openric.org/vocab/role-type/')->get(),
            'states' => Concept::choices('https://workspace.openric.org/vocab/relation-state/')->get(),
            'certainties' => Concept::choices('https://workspace.openric.org/vocab/relation-certainty/')->get(),
        ];
    }
}
