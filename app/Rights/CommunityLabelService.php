<?php

// OpenRiC Workspace - Traditional Knowledge and Biocultural Labels of Local Contexts on records (WS-007, roadmap phase 2).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's icip_tk_label (ahg-icip): a Label applied to a unit of
// description or an instantiation, by its community or by the institution, with
// the community (an authority record), the Local Contexts Hub project it comes
// from, and a note. A record carries each Label once. The Labels are the tk-label
// vocabulary, by their Local Contexts IRIs; nothing here lists them.

namespace App\Rights;

use App\Audit\AuditLog;
use App\Models\Agent;
use App\Models\CommunityLabel;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CommunityLabelService
{
    public const LABELS = Vocab::BASE.'tk-label/';

    public const APPLIERS = Vocab::BASE.'label-applier/';

    public function of(Model $subject): Collection
    {
        return CommunityLabel::where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()])
            ->with(['label.labels', 'label.broader.labels', 'community.names', 'appliedBy.labels'])->orderBy('created_at')->get();
    }

    /** The Labels to choose from, grouped as Local Contexts groups them (TK, BC). */
    public function choices(): \Illuminate\Support\Collection
    {
        return Concept::choices(self::LABELS)->whereNotNull('broader_id')->with('broader.labels')->get()->groupBy(fn (Concept $c) => $c->broader->notation);
    }

    /** Fields: label (notation, such as tk_a), applied_by (community or institution), community (an authority record's key), project, note. */
    public function apply(Model $subject, array $fields, ?User $actor = null): CommunityLabel
    {
        if (! $subject instanceof RecordResource && ! $subject instanceof Instantiation) {
            throw new InvalidArgumentException(__('a Label is applied to a unit of description or an instantiation'));
        }
        $label = Concept::choices(self::LABELS)->whereNotNull('broader_id')->where('notation', (string) ($fields['label'] ?? ''))->first()
            ?? throw new InvalidArgumentException(__('choose a Traditional Knowledge or Biocultural Label'));
        $where = ['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'label_id' => $label->id];
        if (CommunityLabel::where($where)->exists()) {
            throw new InvalidArgumentException(__('the record carries this Label already'));
        }
        $community = null;
        if (($key = trim((string) ($fields['community'] ?? ''))) !== '') {
            $community = Agent::where('institution_id', $subject->getAttribute('institution_id'))->whereNull('deleted_at')->find($key)
                ?? throw new InvalidArgumentException(__('the community is an authority record of the institution'));
        }
        $appliedBy = Concept::choices(self::APPLIERS)->where('notation', (string) ($fields['applied_by'] ?? 'institution'))->first()
            ?? throw new InvalidArgumentException(__('a Label is applied by the community or by the institution'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => CommunityLabel::create([...$where,
            'institution_id' => $subject->getAttribute('institution_id'), 'community_id' => $community?->id, 'applied_by_id' => $appliedBy->id,
            'local_contexts_project' => trim((string) ($fields['project'] ?? '')) ?: null, 'note' => trim((string) ($fields['note'] ?? '')) ?: null])));
    }

    public function remove(CommunityLabel $label, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $label->delete()));
    }
}
