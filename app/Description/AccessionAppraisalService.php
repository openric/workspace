<?php

// OpenRiC Workspace - the appraisal and valuation of accessions, after PSIS's ahgAccessionManagePlugin
// AccessionAppraisalService and Heratio's accession appraisal, appraisal templates, valuation and valuation report
// (roadmap phase 9d, ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An appraisal weighs an accession: its type, appraiser, value, significance and recommendation, and criteria scored
// 1 to 5 with weights, from a template or added by hand; its score is their weighted average. When the institution
// asks for it, an accession is accepted only once an appraisal recommends something other than pending (Heratio's
// accession_require_appraisal). A valuation records what it is worth, for GRAP 103 and IPSAS 45; the latest is its
// current value, and the report adds up the current values. Each is written to the accession's timeline.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Accession;
use App\Models\AccessionAppraisal;
use App\Models\AccessionValuation;
use App\Models\Agent;
use App\Models\AppraisalCriterion;
use App\Models\AppraisalTemplate;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccessionAppraisalService
{
    /** PSIS's seeded "Archival Appraisal" template, given to an institution that has none. */
    public const STANDARD = ['name' => 'Archival Appraisal', 'description' => 'Standard archival appraisal criteria', 'sector' => 'archive', 'criteria' => [
        ['name' => 'Evidential value', 'weight' => 1.0, 'description' => 'Value as evidence of organization functions and activities'],
        ['name' => 'Informational value', 'weight' => 1.0, 'description' => 'Value of information content about persons, places, subjects'],
        ['name' => 'Intrinsic value', 'weight' => 0.8, 'description' => 'Inherent worth based on age, uniqueness, physical form'],
        ['name' => 'Research potential', 'weight' => 0.8, 'description' => 'Anticipated use by researchers'],
        ['name' => 'Institutional relevance', 'weight' => 1.0, 'description' => 'Alignment with collection development policy'],
        ['name' => 'Physical condition', 'weight' => 0.6, 'description' => 'State of preservation and conservation needs'],
        ['name' => 'Completeness', 'weight' => 0.7, 'description' => 'Degree to which records are complete and comprehensive']]];

    public function __construct(private AccessionIntakeService $intake) {}

    /**
     * Add or change an appraisal. Fields: type, significance, recommendation (notations), appraiser (an authority
     * record's key), monetary_value, currency, appraised_on (YYYY-MM-DD), summary, detailed_notes, and template (a key)
     * for a new one's criteria.
     */
    public function save(Accession $accession, ?AccessionAppraisal $appraisal, array $fields, User $actor): AccessionAppraisal
    {
        $values = ['type_id' => $this->term('appraisal-type', $fields['type'] ?? 'archival')->id,
            'significance_id' => ($fields['significance'] ?? '') === '' ? null : $this->term('appraisal-significance', $fields['significance'])->id,
            'recommendation_id' => $this->term('appraisal-recommendation', ($fields['recommendation'] ?? '') ?: 'pending')->id,
            'appraiser_id' => $this->agent($accession->institution_id, $fields['appraiser'] ?? null)?->id,
            ...$this->amount($fields, false),
            'appraised_on' => $this->day($fields['appraised_on'] ?? null),
            'summary' => $this->text($fields['summary'] ?? null), 'detailed_notes' => $this->text($fields['detailed_notes'] ?? null)];
        $template = ($fields['template'] ?? '') === '' ? null : AppraisalTemplate::where('institution_id', $accession->institution_id)->find($fields['template'])
            ?? throw new InvalidArgumentException(__('the template is one of the institution\'s'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($accession, $appraisal, $values, $template, $actor) {
            if ($appraisal) {
                $appraisal->update($values);
                $this->intake->record($accession, 'appraised', $actor, __('Appraisal updated'), ['appraisal_id' => $appraisal->id]);

                return $appraisal;
            }
            $appraisal = AccessionAppraisal::create([...$values, 'institution_id' => $accession->institution_id, 'accession_id' => $accession->id, 'recorded_by_id' => $actor->id]);
            if ($template) {
                $this->applyTemplate($appraisal, $template);
            }
            $this->intake->record($accession, 'appraised', $actor, __('Appraisal created: :type', ['type' => $appraisal->type->label(Vocab::languages())]), ['appraisal_id' => $appraisal->id]);

            return $appraisal;
        }));
    }

    public function remove(AccessionAppraisal $appraisal, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($appraisal, $actor) {
            $appraisal->criteria()->get()->each->delete();
            $appraisal->delete();
            $this->intake->record($appraisal->accession, 'note', $actor, __('Appraisal removed'));
        }));
    }

    /** Replace the appraisal's criteria with the template's (PSIS's applyTemplate). */
    public function applyTemplate(AccessionAppraisal $appraisal, AppraisalTemplate $template, ?User $actor = null): int
    {
        $template->institution_id === $appraisal->institution_id || throw new InvalidArgumentException(__('the template is one of the institution\'s'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($appraisal, $template) {
            $appraisal->criteria()->get()->each->delete();
            foreach (array_values($template->criteria) as $position => $c) {
                AppraisalCriterion::create(['appraisal_id' => $appraisal->id, 'name' => $c['name'], 'weight' => $c['weight'] ?? 1, 'notes' => $c['description'] ?? null, 'position' => $position]);
            }
            $appraisal->unsetRelation('criteria');

            return count($template->criteria);
        }));
    }

    /** Add a criterion, or score one. Fields: name, score (1 to 5, or empty), weight (above 0), notes. */
    public function saveCriterion(AccessionAppraisal $appraisal, ?AppraisalCriterion $criterion, array $fields, User $actor): AppraisalCriterion
    {
        $name = trim((string) ($fields['name'] ?? $criterion?->name)) ?: throw new InvalidArgumentException(__('give the criterion a name'));
        $score = trim((string) ($fields['score'] ?? ''));
        in_array($score, ['', '1', '2', '3', '4', '5'], true) || throw new InvalidArgumentException(__('score a criterion from 1 to 5'));
        $weight = trim((string) ($fields['weight'] ?? '')) ?: ($criterion?->weight ?? '1');
        is_numeric($weight) && (float) $weight > 0 && (float) $weight < 100 || throw new InvalidArgumentException(__('give the weight as a number above 0'));
        $values = ['name' => $name, 'score' => $score === '' ? null : (int) $score, 'weight' => $weight, 'notes' => $this->text($fields['notes'] ?? $criterion?->notes)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $criterion ? tap($criterion)->update($values)
            : AppraisalCriterion::create([...$values, 'appraisal_id' => $appraisal->id, 'position' => (int) $appraisal->criteria()->max('position') + 1])));
    }

    public function removeCriterion(AppraisalCriterion $criterion, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $criterion->delete()));
    }

    /** The institution's appraisal templates; one that has none is given PSIS's archival one, as its default. */
    public function templates(Institution $institution): Collection
    {
        if (! AppraisalTemplate::where('institution_id', $institution->id)->exists()) {
            AppraisalTemplate::create(['institution_id' => $institution->id, 'name' => self::STANDARD['name'], 'description' => self::STANDARD['description'],
                'sector_id' => $this->term('appraisal-sector', self::STANDARD['sector'])->id, 'criteria' => self::STANDARD['criteria'], 'is_default' => true]);
        }

        return AppraisalTemplate::where('institution_id', $institution->id)->with('sector.labels')->orderByDesc('is_default')->orderBy('name')->get();
    }

    /**
     * Add or change a template. Fields: name, description, sector (a notation), is_default, and criteria, one per line
     * as "name | weight | description".
     */
    public function saveTemplate(Institution $institution, ?AppraisalTemplate $template, array $fields, User $actor): AppraisalTemplate
    {
        $name = trim((string) ($fields['name'] ?? '')) ?: throw new InvalidArgumentException(__('give the template a name'));
        $criteria = [];
        foreach (preg_split('/\R/', (string) ($fields['criteria'] ?? '')) as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$criterion, $weight, $description] = array_map('trim', array_pad(explode('|', $line, 3), 3, ''));
            $criterion !== '' || throw new InvalidArgumentException(__('give each criterion a name'));
            $weight = $weight === '' ? 1.0 : (is_numeric($weight) && (float) $weight > 0 ? (float) $weight : throw new InvalidArgumentException(__('give the weight as a number above 0')));
            $criteria[] = ['name' => $criterion, 'weight' => $weight, 'description' => $description === '' ? null : $description];
        }
        $criteria !== [] || throw new InvalidArgumentException(__('give the template at least one criterion'));
        $values = ['name' => $name, 'description' => $this->text($fields['description'] ?? null), 'criteria' => $criteria,
            'sector_id' => ($fields['sector'] ?? '') === '' ? null : $this->term('appraisal-sector', $fields['sector'])->id, 'is_default' => (bool) ($fields['is_default'] ?? false)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $template, $values) {
            if ($values['is_default']) {
                AppraisalTemplate::where(['institution_id' => $institution->id, 'is_default' => true])->when($template, fn ($q) => $q->whereKeyNot($template->id))->get()->each->update(['is_default' => false]);
            }

            return $template ? tap($template)->update($values) : AppraisalTemplate::create([...$values, 'institution_id' => $institution->id]);
        }));
    }

    /** Heratio's finalisationBlockers: an appraisal that recommends, when the institution asks for one. */
    public function blockers(Accession $accession): array
    {
        $pending = $this->term('appraisal-recommendation', 'pending')->id;

        return Institution::whereKey($accession->institution_id)->value('accession_requires_appraisal')
            && ! AccessionAppraisal::where('accession_id', $accession->id)->where('recommendation_id', '!=', $pending)->exists()
            ? [__('no appraisal has been completed')] : [];
    }

    /** Record a valuation. Fields: type, method (notations), monetary_value, currency, valued_on, valuer, reference_document, notes. */
    public function value(Accession $accession, array $fields, User $actor): AccessionValuation
    {
        $values = ['type_id' => $this->term('valuation-type', ($fields['type'] ?? '') ?: 'initial')->id,
            'method_id' => ($fields['method'] ?? '') === '' ? null : $this->term('valuation-method', $fields['method'])->id,
            ...$this->amount($fields, true),
            'valued_on' => $this->day(($fields['valued_on'] ?? '') ?: now()->toDateString()),
            'valuer' => $this->text($fields['valuer'] ?? null), 'reference_document' => $this->text($fields['reference_document'] ?? null), 'notes' => $this->text($fields['notes'] ?? null)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($accession, $values, $actor) {
            $valuation = AccessionValuation::create([...$values, 'institution_id' => $accession->institution_id, 'accession_id' => $accession->id, 'recorded_by_id' => $actor->id]);
            $this->intake->record($accession, 'note', $actor, __('Valuation recorded: :currency :amount (:type)', ['currency' => $valuation->currency,
                'amount' => number_format((float) $valuation->monetary_value, 2), 'type' => $valuation->type->label(Vocab::languages())]), ['valuation_id' => $valuation->id]);

            return $valuation;
        }));
    }

    public function removeValuation(AccessionValuation $valuation, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($valuation, $actor) {
            $valuation->delete();
            $this->intake->record($valuation->accession, 'note', $actor, __('Valuation removed'));
        }));
    }

    /**
     * PSIS's valuation report: each accession's current valuation (its latest), totalled by currency, and how many by
     * type; with Heratio's list of the accessions valued.
     *
     * @return array{rows: Collection, by_currency: array<string, float>, by_type: array<string, int>, count: int}
     */
    public function report(string $institutionId): array
    {
        $rows = AccessionValuation::query()->where('institution_id', $institutionId)
            ->whereIn('accession_id', Accession::where('institution_id', $institutionId)->whereNull('deleted_at')->select('id'))
            ->whereRaw('id = (SELECT v.id FROM accession_valuations v WHERE v.accession_id = accession_valuations.accession_id ORDER BY v.valued_on DESC, v.created_at DESC LIMIT 1)')
            ->with('type.labels', 'method.labels', 'accession.names.nameType', 'accession.identifiers.identifierType', 'accession.descriptionValues.element')->orderByDesc('valued_on')->get();
        $languages = Vocab::languages();

        return ['rows' => $rows, 'count' => $rows->count(),
            'by_currency' => $rows->groupBy('currency')->map(fn ($r) => $r->sum(fn ($v) => (float) $v->monetary_value))->sortKeys()->all(),
            'by_type' => $rows->groupBy(fn ($v) => $v->type->label($languages))->map->count()->all()];
    }

    /** @return array{monetary_value: ?string, currency: string} */
    private function amount(array $fields, bool $required): array
    {
        $amount = str_replace([' ', ','], '', trim((string) ($fields['monetary_value'] ?? '')));
        if ($amount === '') {
            $required && throw new InvalidArgumentException(__('give the amount'));

            return ['monetary_value' => null, 'currency' => strtoupper(trim((string) ($fields['currency'] ?? ''))) ?: 'ZAR'];
        }
        $currency = strtoupper(trim((string) ($fields['currency'] ?? ''))) ?: 'ZAR';
        preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) || throw new InvalidArgumentException(__('give the amount as a number, such as 1500.00'));
        preg_match('/^[A-Z]{3}$/', $currency) || throw new InvalidArgumentException(__('give the currency as a three-letter code, such as ZAR'));

        return ['monetary_value' => $amount, 'currency' => $currency];
    }

    private function agent(string $institutionId, ?string $key): ?Agent
    {
        return ($key = trim((string) $key)) === '' ? null : (Agent::where('institution_id', $institutionId)->whereNull('deleted_at')->find($key)
            ?? throw new InvalidArgumentException(__('the appraiser is an authority record of the institution')));
    }

    private function day(?string $day): ?string
    {
        if (($day = trim((string) $day)) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date && $date->format('Y-m-d') === $day ? $day : throw new InvalidArgumentException(__('give the date as YYYY-MM-DD'));
    }

    private function text(?string $text): ?string
    {
        return ($t = trim((string) $text)) === '' ? null : $t;
    }

    private function term(string $vocabulary, string $notation): Concept
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
