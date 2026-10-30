<?php

// OpenRiC Workspace - the appraisal and valuation of accessions: appraisals and their criteria, valuations, the
// valuation report and the appraisal templates (PSIS's ahgAccessionManagePlugin accessionAppraisal module, Heratio's
// accession appraisal, appraisal templates, valuation and valuation report; roadmap phase 9d).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\AccessionAppraisalService;
use App\Models\Accession;
use App\Models\AccessionAppraisal;
use App\Models\AccessionValuation;
use App\Models\AppraisalCriterion;
use App\Models\AppraisalTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class AccessionAppraisalController extends Controller
{
    public function __construct(private AccessionAppraisalService $appraisals) {}

    /** Add an appraisal, or change one. */
    public function save(Request $request, Accession $accession, ?string $appraisal = null): RedirectResponse
    {
        $this->own($request, $accession);
        $data = $request->validate(['type' => ['required', 'string', 'max:64'], 'significance' => ['nullable', 'string', 'max:64'], 'recommendation' => ['nullable', 'string', 'max:64'],
            'appraiser' => ['nullable', 'uuid'], 'monetary_value' => ['nullable', 'string', 'max:30'], 'currency' => ['nullable', 'string', 'max:3'],
            'appraised_on' => ['nullable', 'string', 'max:10'], 'summary' => ['nullable', 'string', 'max:65535'], 'detailed_notes' => ['nullable', 'string', 'max:65535'],
            'template' => ['nullable', 'uuid']]);
        $existing = $appraisal ? AccessionAppraisal::where('accession_id', $accession->id)->findOrFail($appraisal) : null;

        return $this->attempt($accession, fn () => $this->appraisals->save($accession, $existing, $data, $request->user()), __('Appraisal saved.'), 'appraisal');
    }

    public function remove(Request $request, Accession $accession, string $appraisal): RedirectResponse
    {
        $this->own($request, $accession);
        $existing = AccessionAppraisal::where('accession_id', $accession->id)->findOrFail($appraisal);

        return $this->attempt($accession, fn () => $this->appraisals->remove($existing, $request->user()), __('Removed.'), 'appraisal');
    }

    /** Add a criterion, score one, remove one, or replace them with a template's. */
    public function criteria(Request $request, Accession $accession, string $appraisal): RedirectResponse
    {
        $this->own($request, $accession);
        $existing = AccessionAppraisal::where('accession_id', $accession->id)->findOrFail($appraisal);
        $data = $request->validate(['action' => ['required', 'in:add,score,remove,template'], 'criterion' => ['nullable', 'uuid'], 'template' => ['nullable', 'uuid'],
            'name' => ['nullable', 'string', 'max:500'], 'score' => ['nullable', 'string', 'max:1'], 'weight' => ['nullable', 'string', 'max:10'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $criterion = fn () => AppraisalCriterion::where('appraisal_id', $existing->id)->findOrFail($data['criterion'] ?? null);

        return $this->attempt($accession, fn () => match ($data['action']) {
            'add' => $this->appraisals->saveCriterion($existing, null, $data, $request->user()),
            'score' => $this->appraisals->saveCriterion($existing, $criterion(), $data, $request->user()),
            'remove' => $this->appraisals->removeCriterion($criterion(), $request->user()),
            'template' => $this->appraisals->applyTemplate($existing, AppraisalTemplate::where('institution_id', $accession->institution_id)->findOrFail($data['template'] ?? null), $request->user()),
        }, __('Criteria saved.'), 'appraisal');
    }

    public function value(Request $request, Accession $accession): RedirectResponse
    {
        $this->own($request, $accession);
        $data = $request->validate(['type' => ['required', 'string', 'max:64'], 'method' => ['nullable', 'string', 'max:64'], 'monetary_value' => ['required', 'string', 'max:30'],
            'currency' => ['nullable', 'string', 'max:3'], 'valued_on' => ['nullable', 'string', 'max:10'], 'valuer' => ['nullable', 'string', 'max:255'],
            'reference_document' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:65535']]);

        return $this->attempt($accession, fn () => $this->appraisals->value($accession, $data, $request->user()), __('Valuation recorded.'), 'valuation');
    }

    public function removeValuation(Request $request, Accession $accession, string $valuation): RedirectResponse
    {
        $this->own($request, $accession);
        $existing = AccessionValuation::where('accession_id', $accession->id)->findOrFail($valuation);

        return $this->attempt($accession, fn () => $this->appraisals->removeValuation($existing, $request->user()), __('Removed.'), 'valuation');
    }

    /** PSIS's and Heratio's valuation report: the current value of each accession valued, and the totals. */
    public function report(Request $request): View
    {
        return view('accessions.valuation-report', $this->appraisals->report($request->user()->institution_id));
    }

    public function saveTemplate(Request $request, ?string $template = null): RedirectResponse
    {
        $institution = $request->user()->institution;
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'sector' => ['nullable', 'string', 'max:64'],
            'is_default' => ['nullable', 'boolean'], 'criteria' => ['required', 'string', 'max:20000']]);
        $existing = $template ? AppraisalTemplate::where('institution_id', $institution->id)->findOrFail($template) : null;
        try {
            $this->appraisals->saveTemplate($institution, $existing, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect(route('accessions.settings').'#appraisal-templates')->withErrors(['template' => ucfirst($e->getMessage()).'.']);
        }

        return redirect(route('accessions.settings').'#appraisal-templates')->with('status', __('Template saved.'));
    }

    public function removeTemplate(Request $request, string $template): RedirectResponse
    {
        AppraisalTemplate::where('institution_id', $request->user()->institution_id)->findOrFail($template)->delete();

        return redirect(route('accessions.settings').'#appraisal-templates')->with('status', __('Removed.'));
    }

    private function attempt(Accession $accession, callable $change, string $status, string $section): RedirectResponse
    {
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            return redirect(route('accessions.show', $accession).'#'.$section)->withErrors(['accession' => ucfirst($e->getMessage()).'.']);
        }

        return redirect(route('accessions.show', $accession).'#'.$section)->with('status', $status);
    }

    private function own(Request $request, Accession $accession): void
    {
        abort_unless($accession->institution_id === $request->user()->institution_id && $accession->deleted_at === null, 404);
        abort_unless($request->user()->may('update', 'accession', $accession), 403);
    }
}
