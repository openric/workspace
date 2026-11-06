<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/LanguageTranscriptionService.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d4):
 * transcriptions, corrections, translations and notes people contribute about a published record in a language, each
 * pending until reviewed and its contributor named only with consent; approvedForItem, approvedForCulture, the
 * moderation queue, its counts and moderate, with the glossary's moderation beside them, as Heratio's language
 * moderation screen has both; the limits and the disclaimer. Changed: the kinds and statuses are vocabulary rows; the
 * record is one the public catalogue shows; its language defaults to the language of its title; the namespace and code
 * style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Language;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\LanguageContribution;
use App\Models\LanguageGlossaryEntry;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Contributions
{
    public const TYPES = Vocab::BASE.'language-contribution-type/';

    private const MAX_PER_ITEM = 100;

    private const MAX_PER_LANGUAGE = 200;

    private const MAX_QUEUE = 300;

    private const MAX_BODY_CHARS = 60000;

    public const DISCLAIMER = 'Heritage and endangered languages are living languages that belong to the communities who speak them. Transcriptions, corrections and translations contributed here are reviewed before they appear and are shared as a community resource. You are credited for your contribution only where you ask to be named; otherwise it appears anonymously. The collection holds these contributions on behalf of the community, not as a claim of authority over the language.';

    /** $data: type, language (the title's when not given), body, source, contributor_name, credit_consent. */
    public function contribute(RecordResource $record, array $data, ?User $user): LanguageContribution
    {
        ($body = trim((string) ($data['body'] ?? ''))) !== '' || throw new InvalidArgumentException(__('Write the transcription, correction, translation or note.'));
        $type = Concept::where('iri', self::TYPES.($data['type'] ?? 'transcription'))->value('id') ?? throw new InvalidArgumentException(__('Choose what kind of contribution it is.'));
        $language = Corpus::language($data['language'] ?? null) ?? Corpus::language($record->originalLanguage('title')) ?? throw new InvalidArgumentException(__('Choose the language.'));
        $name = mb_substr(trim((string) ($data['contributor_name'] ?? '')), 0, 255);
        $consent = ! empty($data['credit_consent']) && $name !== '';

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => LanguageContribution::create(['institution_id' => $record->institution_id, 'record_resource_id' => $record->id,
            'language' => $language, 'type_id' => $type, 'body' => mb_substr($body, 0, self::MAX_BODY_CHARS), 'source' => mb_substr(trim((string) ($data['source'] ?? '')), 0, 512) ?: null,
            'contributor_name' => $consent ? $name : null, 'credit_consent' => $consent, 'status_id' => Corpus::status('pending'), 'contributed_by_id' => $user?->id])));
    }

    public function approvedForItem(RecordResource $record): Collection
    {
        return LanguageContribution::where(['record_resource_id' => $record->id, 'status_id' => Corpus::status('approved')])->with('type.labels')->orderByDesc('created_at')->limit(self::MAX_PER_ITEM)->get();
    }

    public function approvedForLanguage(Institution $institution, string $code): Collection
    {
        return LanguageContribution::where(['institution_id' => $institution->id, 'language' => $code, 'status_id' => Corpus::status('approved')])
            ->with('type.labels', 'record')->orderByDesc('created_at')->limit(self::MAX_PER_LANGUAGE)->get();
    }

    /** What waits for review, or was reviewed: the contributions and the glossary entries of one status. */
    public function queue(Institution $institution, string $status = 'pending'): array
    {
        $id = Corpus::status($status);

        return ['contributions' => LanguageContribution::where(['institution_id' => $institution->id, 'status_id' => $id])->with('type.labels', 'record')->orderByDesc('created_at')->limit(self::MAX_QUEUE)->get(),
            'glossary' => LanguageGlossaryEntry::where(['institution_id' => $institution->id, 'status_id' => $id])->orderByDesc('created_at')->limit(self::MAX_QUEUE)->get()];
    }

    /** @return array<string,int> what waits and was reviewed, contributions and glossary entries together */
    public function counts(Institution $institution): array
    {
        $count = fn (string $table) => DB::table($table)->join('concepts', 'concepts.id', '=', "$table.status_id")->where("$table.institution_id", $institution->id)
            ->groupBy('concepts.iri')->selectRaw('concepts.iri, count(*) AS c')->pluck('c', 'iri');
        $out = [];
        foreach ($count('language_contributions') as $iri => $c) {
            $out[substr($iri, strlen(Corpus::STATUSES))] = (int) $c;
        }
        foreach ($count('language_glossary') as $iri => $c) {
            $key = substr($iri, strlen(Corpus::STATUSES));
            $out[$key] = ($out[$key] ?? 0) + (int) $c;
        }

        return $out;
    }

    /** Approve, or not publish, a contribution or a glossary entry. */
    public function moderate(Model $item, string $status, User $moderator): void
    {
        ($item instanceof LanguageContribution || $item instanceof LanguageGlossaryEntry) || throw new InvalidArgumentException(__('That cannot be moderated here.'));
        $id = Concept::where('iri', Corpus::STATUSES.$status)->value('id') ?? throw new InvalidArgumentException(__('Unknown value :value.', ['value' => $status]));
        DB::transaction(fn () => AuditLog::actingAs($moderator, fn () => $item->update(['status_id' => $id, 'moderated_by_id' => $moderator->id, 'moderated_at' => now()])));
    }
}
