<?php

// OpenRiC Workspace - label templates, and labels printed for records and containers (roadmap phase 22a), after
// Heratio's ahg-label (LabelController, LabelTemplateController, the batch page).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Labels\Labels;
use App\Models\Concept;
use App\Models\Container;
use App\Models\LabelTemplate;
use App\Models\RecordResource;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class LabelController extends Controller
{
    public function __construct(private Labels $labels) {}

    public function templates(Request $request): View
    {
        $choices = fn (string $v) => Concept::choices(Vocab::BASE.$v.'/')->get();

        return view('labels.templates', ['templates' => $this->labels->templates($request->user()->institution_id), 'pageSizes' => $choices('label-page-size'),
            'barcodeSources' => $choices('label-barcode-source'), 'qrTargets' => $choices('label-qr-target')]);
    }

    public function saveTemplate(Request $request, ?LabelTemplate $template = null): RedirectResponse
    {
        abort_if($template && $template->institution_id !== $request->user()->institution_id, 404);
        try {
            $this->labels->save($request->user()->institution_id, $request->all(), $template);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['labels' => $e->getMessage()]);
        }

        return redirect()->route('labels.templates')->with('status', __('Saved.'));
    }

    /** A sheet of labels: records (one, or those inside one) or containers, each that the account may read. */
    public function print(Request $request): View
    {
        $data = $request->validate(['kind' => ['required', 'in:records,containers'], 'ids' => ['required', 'array', 'max:500'], 'ids.*' => ['uuid'],
            'inside' => ['nullable', 'boolean'], 'template' => ['nullable', 'uuid'], 'skip' => ['nullable', 'integer', 'min:0', 'max:300']]);
        $user = $request->user();
        $templates = $this->labels->templates($user->institution_id);
        $template = $templates->firstWhere('id', $data['template'] ?? null) ?? $templates->first();
        if ($data['kind'] === 'records') {
            $entities = RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')->whereIn('id', $data['ids'])->get();
            if ($request->boolean('inside')) {
                $entities = RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')->whereIn('parent_id', $entities->pluck('id'))->orderBy('position')->get();
            }
            $entities = $entities->filter(fn ($r) => EntityKinds::readable($user, $r))->sortBy(fn ($r) => $r->referenceCode() ?? '', SORT_NATURAL)->values();
        } else {
            abort_unless($user->mayAnywhere('read', 'storage'), 403);
            $entities = Container::where('institution_id', $user->institution_id)->whereIn('id', $data['ids'])->with('identifiers.identifierType')->get();
        }
        abort_if($entities->isEmpty(), 404);

        return view('labels.print', ['template' => $template, 'templates' => $templates, 'page' => Labels::PAGES[$template->pageSize->notation],
            'labels' => $entities->map(fn ($e) => $this->labels->label($e, $template)), 'skip' => (int) ($data['skip'] ?? 0), 'query' => $request->except('template', 'skip')]);
    }
}
