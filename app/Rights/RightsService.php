<?php

/**
 * ExtendedRightsService - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-extended-rights/src/Services/ExtendedRightsService.php
 * at commit 4131171e4 (heratio-dev; governance/CODE_PROVENANCE.md, roadmap phase 2): saveRightsRecord,
 * deleteRightsRecord, getGrantsForRecord and createGrant. Kept: a right per record with its basis, statement,
 * licence, copyright status, jurisdiction, determination date, citation, dates, holder and note, and its grants
 * replaced as a whole when saved. Changed: the Workspace's tables, UUIDs and persistent IRIs (a right is a
 * rico:Rule); every list a vocabulary (rights-basis, rights-statement, licence, copyright-status, rights-act,
 * rights-restriction) instead of codes; a right is held by a unit of description or an instantiation; its holder
 * is an authority record; a right is marked deleted, never removed; the audit log is the Workspace's.
 * Embargoes, orphan works and retention are not here (roadmap phases 13 and 18).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Rights;

use App\Audit\AuditLog;
use App\Description\Bcp47;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Right;
use App\Models\RightGrant;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RightsService
{
    public const BASES = Vocab::BASE.'rights-basis/';

    public const STATEMENTS = Vocab::BASE.'rights-statement/';

    public const LICENCES = Vocab::BASE.'licence/';

    public const COPYRIGHT_STATUSES = Vocab::BASE.'copyright-status/';

    public const ACTS = Vocab::BASE.'rights-act/';

    public const RESTRICTIONS = Vocab::BASE.'rights-restriction/';

    /** The rights of a unit of description or an instantiation that are not deleted, with what they need to be shown. */
    public function of(Model $subject): Collection
    {
        return Right::where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()])->whereNull('deleted_at')
            ->with(['basis.labels', 'statement.labels', 'licence.labels', 'copyrightStatus.labels', 'holder.names', 'grants.act.labels', 'grants.restriction.labels'])
            ->orderBy('created_at')->get();
    }

    /**
     * Fields: basis (rights-basis notation, required), statement (such as InC), licence (such as CC-BY-4.0),
     * copyright_status, jurisdiction (ISO 3166-1 alpha-2), determined_on, starts_on, ends_on (YYYY-MM-DD),
     * citation, holder (an authority record's key), note and note_language (BCP 47), and grants:
     * [{act, restriction, starts_on, ends_on, note}].
     */
    public function add(Model $subject, array $fields, ?User $actor = null): Right
    {
        if (! $subject instanceof RecordResource && ! $subject instanceof Instantiation) {
            throw new InvalidArgumentException(__('a right is held by a unit of description or an instantiation'));
        }
        $values = $this->values($subject->getAttribute('institution_id'), $fields);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($subject, $values, $fields) {
            $institution = Institution::findOrFail($subject->getAttribute('institution_id'));
            $id = (string) Str::uuid7();
            $right = new Right([...$values, 'institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Right::IRI_SEGMENT.'/'.$id,
                'subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()]);
            $right->id = $id;
            $right->save();
            $this->setGrants($right, $fields['grants'] ?? []);

            return $right;
        }));
    }

    public function update(Right $right, array $fields, ?User $actor = null): void
    {
        $values = $this->values($right->institution_id, $fields);
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($right, $values, $fields) {
            $right->update($values);
            // As Heratio: the grants sent replace the right's grants.
            if (array_key_exists('grants', $fields)) {
                $this->setGrants($right, $fields['grants'] ?? []);
            }
        }));
    }

    /**
     * Give each of the records the same right, as Heratio's batch assignment does for the objects chosen; with
     * $withInstantiations, their instantiations too. A record the account may not change is skipped and counted.
     *
     * @param  iterable<RecordResource>  $records
     * @return array{added: int, skipped: int}
     */
    public function assignToMany(iterable $records, array $fields, bool $withInstantiations, User $actor): array
    {
        $added = $skipped = 0;
        $this->values($actor->institution_id, $fields); // refuse a malformed right before anything is written
        DB::transaction(function () use ($records, $fields, $withInstantiations, $actor, &$added, &$skipped) {
            foreach ($records as $record) {
                if (! $actor->may('update', 'description', $record)) {
                    $skipped++;

                    continue;
                }
                $subjects = $withInstantiations ? [$record, ...Instantiation::where('record_resource_id', $record->id)->whereNull('deleted_at')->get()] : [$record];
                foreach ($subjects as $subject) {
                    $this->add($subject, $fields, $actor);
                    $added++;
                }
            }
        });

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Mark the right deleted: it leaves every page and export, and its IRI is kept. */
    public function remove(Right $right, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $right->update(['deleted_at' => now()])));
    }

    /** @param  list<array<string, mixed>>  $grants */
    private function setGrants(Right $right, array $grants): void
    {
        $right->grants()->get()->each->delete();
        $seen = [];
        foreach ($grants as $grant) {
            $act = trim((string) ($grant['act'] ?? ''));
            if ($act === '') {
                continue;
            }
            if (isset($seen[$act])) {
                throw new InvalidArgumentException(__('each act is granted once in a right'));
            }
            $seen[$act] = true;
            [$starts, $ends] = [$this->day($grant['starts_on'] ?? null), $this->day($grant['ends_on'] ?? null)];
            if ($starts && $ends && $starts > $ends) {
                throw new InvalidArgumentException(__('a grant cannot end before it starts'));
            }
            RightGrant::create(['right_id' => $right->id, 'act_id' => $this->concept(self::ACTS, $act)->id,
                'restriction_id' => $this->concept(self::RESTRICTIONS, (string) ($grant['restriction'] ?? 'allow'))->id,
                'starts_on' => $starts, 'ends_on' => $ends, 'note' => trim((string) ($grant['note'] ?? '')) ?: null]);
        }
        $right->unsetRelation('grants');
    }

    /** @return array<string, mixed> */
    private function values(string $institutionId, array $fields): array
    {
        $optional = fn (string $vocabulary, string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $this->concept($vocabulary, $v)->id;
        $jurisdiction = strtoupper(trim((string) ($fields['jurisdiction'] ?? '')));
        if ($jurisdiction !== '' && ! preg_match('/^[A-Z]{2}$/', $jurisdiction)) {
            throw new InvalidArgumentException(__('give the jurisdiction as a two-letter country code, such as ZA'));
        }
        [$starts, $ends] = [$this->day($fields['starts_on'] ?? null), $this->day($fields['ends_on'] ?? null)];
        if ($starts && $ends && $starts > $ends) {
            throw new InvalidArgumentException(__('a right cannot end before it starts'));
        }
        $holder = null;
        if (($key = trim((string) ($fields['holder'] ?? ''))) !== '') {
            $holder = Agent::where('institution_id', $institutionId)->whereNull('deleted_at')->find($key)
                ?? throw new InvalidArgumentException(__('the rights holder is an authority record of the institution'));
        }
        $note = trim((string) ($fields['note'] ?? ''));
        $language = trim((string) ($fields['note_language'] ?? ''));
        if ($note !== '' && ! Bcp47::valid($language)) {
            throw new InvalidArgumentException(__('give the language of the note as a BCP 47 tag, such as en or af'));
        }

        return [
            'basis_id' => $this->concept(self::BASES, (string) ($fields['basis'] ?? ''))->id,
            'statement_id' => $optional(self::STATEMENTS, 'statement'),
            'licence_id' => $optional(self::LICENCES, 'licence'),
            'copyright_status_id' => $optional(self::COPYRIGHT_STATUSES, 'copyright_status'),
            'jurisdiction' => $jurisdiction === '' ? null : $jurisdiction,
            'determined_on' => $this->day($fields['determined_on'] ?? null),
            'citation' => trim((string) ($fields['citation'] ?? '')) ?: null,
            'starts_on' => $starts, 'ends_on' => $ends,
            'holder_id' => $holder?->id,
            'note' => $note === '' ? null : $note, 'note_language' => $note === '' ? null : $language,
        ];
    }

    private function day(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException(__('give a date as YYYY-MM-DD'));
        }

        return $value;
    }

    /** A current concept of the vocabulary, by its notation. */
    private function concept(string $vocabulary, string $notation): Concept
    {
        return Concept::choices($vocabulary)->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the vocabulary :vocabulary', ['value' => $notation, 'vocabulary' => $vocabulary]));
    }
}
