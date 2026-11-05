<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/RepatriationKnowledgeService.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d2): contribute
 * (pending until reviewed, the contributor named only with consent), approvedForClaim, moderationQueue,
 * moderationCounts, moderate and the disclaimer, with its limits. Changed: the kinds and statuses are vocabulary rows;
 * a contribution belongs to the claim's institution and its queue is that institution's; the namespace and code
 * style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repatriation;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RepatriationClaim;
use App\Models\RepatriationKnowledge;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Knowledge
{
    public const TYPES = Vocab::BASE.'repatriation-knowledge-type/';

    public const STATUSES = Vocab::BASE.'repatriation-knowledge-status/';

    private const MAX_PER_CLAIM = 100;

    private const MAX_QUEUE = 300;

    private const MAX_BODY_CHARS = 60000;

    public const DISCLAIMER = 'Knowledge about a displaced object belongs to the communities connected to it. What is shared here - oral history, provenance knowledge, corrections and links to source communities - is contributed by people, reviewed before it appears, and held as a record of an open dialogue. A contribution is not a legal determination of origin, ownership or wrongful removal, and is not advice. Contributors are credited by name only where they ask to be; otherwise their contribution appears anonymously. Origin, ownership and lawful-transfer history are matters for the relevant communities, holding institutions and qualified staff to assess together, case by case, under the applicable law.';

    /** $data: type (a notation), body, source, contributor_name, credit_consent. */
    public function contribute(RepatriationClaim $claim, array $data, ?User $user): RepatriationKnowledge
    {
        ($body = trim((string) ($data['body'] ?? ''))) !== '' || throw new InvalidArgumentException(__('Write what you know.'));
        $type = Concept::where('iri', self::TYPES.($data['type'] ?? 'other'))->value('id') ?? throw new InvalidArgumentException(__('Choose what kind of knowledge it is.'));
        $name = mb_substr(trim((string) ($data['contributor_name'] ?? '')), 0, 255);
        $consent = ! empty($data['credit_consent']) && $name !== '';

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => RepatriationKnowledge::create(['institution_id' => $claim->institution_id, 'claim_id' => $claim->id,
            'type_id' => $type, 'body' => mb_substr($body, 0, self::MAX_BODY_CHARS), 'source' => mb_substr(trim((string) ($data['source'] ?? '')), 0, 512) ?: null,
            'contributor_name' => $consent ? $name : null, 'credit_consent' => $consent, 'contributed_by_id' => $user?->id, 'status_id' => self::status('pending')])));
    }

    public function approvedFor(RepatriationClaim $claim): Collection
    {
        return $claim->knowledge()->where('status_id', self::status('approved'))->with('type.labels')->limit(self::MAX_PER_CLAIM)->get();
    }

    public function queue(Institution $institution, string $status = 'pending'): Collection
    {
        return RepatriationKnowledge::where('institution_id', $institution->id)->where('status_id', self::status($status))
            ->with('type.labels', 'status.labels', 'claim.record')->orderByDesc('created_at')->limit(self::MAX_QUEUE)->get();
    }

    /** @return array<string,int> contributions by status notation */
    public function counts(Institution $institution): array
    {
        return RepatriationKnowledge::where('repatriation_knowledge.institution_id', $institution->id)->join('concepts', 'concepts.id', '=', 'repatriation_knowledge.status_id')
            ->groupBy('concepts.iri')->selectRaw('concepts.iri, count(*) AS c')->pluck('c', 'iri')
            ->mapWithKeys(fn ($c, $iri) => [substr($iri, strlen(self::STATUSES)) => (int) $c])->all();
    }

    public function moderate(RepatriationKnowledge $contribution, string $status, User $moderator): void
    {
        $id = Concept::where('iri', self::STATUSES.$status)->value('id') ?? throw new InvalidArgumentException(__('Unknown value :value.', ['value' => $status]));
        DB::transaction(fn () => AuditLog::actingAs($moderator, fn () => $contribution->update(['status_id' => $id, 'moderated_by_id' => $moderator->id, 'moderated_at' => now()])));
    }

    public static function status(string $notation): string
    {
        return Concept::where('iri', self::STATUSES.$notation)->valueOrFail('id');
    }
}
