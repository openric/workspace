<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/EndangeredHeritageService.php at
 * commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d3): flag (one flag a
 * description, a second flag updating it), updateCaptureStatus, list, priorityList (flagged and in progress, the most
 * urgent first), priorityScore (the urgency's weight, five more for a digitisation gap, nothing once captured or
 * unflagged), statusCounts, urgencyCounts, publicRegister (published only), publicRiskCounts, dashboard (counts, and
 * capture progress as captured over captured and outstanding) and the disclaimer. Changed: the risks, urgencies and
 * statuses are vocabulary rows, the weights kept here by notation; a flag belongs to an institution and its
 * description; published is what the public catalogue shows; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Heritage;

use App\Api\PublicCatalogue;
use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\EndangeredItem;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Endangered
{
    public const RISKS = Vocab::BASE.'endangered-risk/';

    public const URGENCIES = Vocab::BASE.'endangered-urgency/';

    public const CAPTURE = Vocab::BASE.'capture-status/';

    /** Heratio's weights, by urgency; an urgency added in the Dropdown manager weighs as medium. */
    public const WEIGHTS = ['critical' => 1000, 'high' => 100, 'medium' => 10, 'low' => 1];

    /** On the worklist until captured or unflagged. */
    public const OUTSTANDING = ['flagged', 'in-progress'];

    public const DISCLAIMER = 'This register records items that curators judge should be captured sooner rather than later, and the documented reason why. A flag is a prioritisation aid offered in a spirit of care: it is not a prediction that an item will be lost, not a statement about any institution\'s stewardship, and not advice. Risk to heritage, and the order in which to act, are matters for qualified staff to assess against the evidence in every case.';

    /** Flag a description, or change its flag. $data: risk, urgency, capture_status (notations), reason. */
    public function flag(RecordResource $record, array $data, User $user): EndangeredItem
    {
        $values = ['risk_id' => $this->concept(self::RISKS, $data['risk'] ?? 'other'), 'urgency_id' => $this->concept(self::URGENCIES, $data['urgency'] ?? 'medium'),
            'capture_status_id' => $this->concept(self::CAPTURE, $data['capture_status'] ?? 'flagged'), 'reason' => trim((string) ($data['reason'] ?? '')) ?: null];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($record, $values, $user) {
            $item = EndangeredItem::firstOrNew(['record_resource_id' => $record->id], ['institution_id' => $record->institution_id, 'flagged_by_id' => $user->id]);
            $item->fill($values)->save();

            return $item;
        }));
    }

    public function setCaptureStatus(EndangeredItem $item, string $status, User $user): void
    {
        $id = $this->concept(self::CAPTURE, $status);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $item->update(['capture_status_id' => $id])));
    }

    public function list(Institution $institution, ?string $status = null): Collection
    {
        return EndangeredItem::where('institution_id', $institution->id)->with('record', 'risk.labels', 'urgency.labels', 'captureStatus.labels')
            ->when($status, fn ($q) => $q->where('capture_status_id', $this->concept(self::CAPTURE, $status)))
            ->orderByDesc('updated_at')->limit(1000)->get();
    }

    /** What to capture next: the outstanding flags, the highest score first, the oldest flag first among equals. */
    public function priorityList(Institution $institution): Collection
    {
        return $this->list($institution)->filter(fn (EndangeredItem $i) => in_array($i->captureStatus->notation, self::OUTSTANDING, true))
            ->sortBy(fn (EndangeredItem $i) => [-self::score($i->urgency->notation, $i->risk->notation, $i->captureStatus->notation), $i->created_at->getTimestamp()])->values();
    }

    public static function score(?string $urgency, ?string $risk, ?string $capture): int
    {
        if (! in_array($capture, self::OUTSTANDING, true)) {
            return 0;
        }

        return (self::WEIGHTS[$urgency] ?? self::WEIGHTS['medium']) + ($risk === 'digitisation-gap' ? 5 : 0);
    }

    /** The outstanding flags on what the public catalogue shows, most urgent first, perhaps of one risk. */
    public function publicRegister(PublicCatalogue $catalogue, ?string $risk = null): Collection
    {
        return $this->priorityList($catalogue->institution)->filter(fn (EndangeredItem $i) => ($risk === null || $i->risk->notation === $risk)
            && $catalogue->find('records', $i->record_resource_id) !== null)->values();
    }

    /** @return array<string,int> by notation, over a vocabulary-keyed column */
    public function counts(Institution $institution, string $column, string $vocabulary): array
    {
        return EndangeredItem::where('endangered_items.institution_id', $institution->id)->join('concepts', 'concepts.id', '=', "endangered_items.$column")
            ->groupBy('concepts.iri')->selectRaw('concepts.iri, count(*) AS c')->pluck('c', 'iri')
            ->mapWithKeys(fn ($c, $iri) => [substr($iri, strlen($vocabulary)) => (int) $c])->all();
    }

    /** The register at a glance; the priority list is what the public catalogue shows. */
    public function dashboard(PublicCatalogue $catalogue, int $top = 8): array
    {
        $institution = $catalogue->institution;
        $status = $this->counts($institution, 'capture_status_id', self::CAPTURE);
        $risks = $this->counts($institution, 'risk_id', self::RISKS);
        $urgencies = $this->counts($institution, 'urgency_id', self::URGENCIES);
        $total = array_sum($status);
        $captured = $status['captured'] ?? 0;
        $outstanding = max(0, $total - $captured - ($status['unflagged'] ?? 0));
        $public = $this->publicRegister($catalogue);

        return ['total' => $total, 'captured' => $captured, 'in_progress' => $status['in-progress'] ?? 0, 'flagged' => $status['flagged'] ?? 0, 'outstanding' => $outstanding,
            'capture_progress_pct' => $captured + $outstanding > 0 ? (int) round($captured / ($captured + $outstanding) * 100) : 0,
            'by_risk' => Concept::choices(self::RISKS)->get()->map(fn (Concept $c) => ['concept' => $c, 'count' => $risks[$c->notation] ?? 0])->all(),
            'by_urgency' => Concept::choices(self::URGENCIES)->get()->map(fn (Concept $c) => ['concept' => $c, 'count' => $urgencies[$c->notation] ?? 0])->all(),
            'public_total' => $public->count(), 'priority' => $public->take($top)->values()];
    }

    public function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', $vocabulary.$notation)->value('id') ?? throw new InvalidArgumentException(__('Unknown value :value.', ['value' => $notation]));
    }
}
