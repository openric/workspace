<?php

/**
 * AclService - Service for Heratio
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
 * OpenRiC Workspace - ported from Heratio, packages/ahg-acl/src/Services/AclService.php (check)
 * at commit a060339c592bfb4d903679899c04bd56c5fe5744 (governance/CODE_PROVENANCE.md, ADR 0005).
 * Changed: the Workspace's roles and permissions tables; a denial wins over a grant at the
 * same level (Heratio lets a grant win); an account's own permissions are tried before its
 * roles'; roles inherit from their parents, and every account has Signed in and Public; a
 * permission on a unit of description also covers the units inside it (as AtoM does); the
 * repository scope Heratio keeps in "constants" is a column.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Access;

use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AccessControl
{
    /** @var array<string, list<string>> account id (or 'public:'.institution) => ordered role ids */
    private array $roles = [];

    /** @var array<string, ?string> IRI => concept id */
    private array $concepts = [];

    /**
     * May this account (null: the public) take this action on this kind of record,
     * on this record or in general?
     *
     * @param  string  $action  notation in the permission vocabulary, e.g. update
     * @param  string  $kind  notation in the record-kind vocabulary, e.g. description
     */
    public function allows(?User $account, string $action, string $kind, ?Model $subject = null, ?Institution $institution = null): bool
    {
        if ($account?->isDisabled()) {
            return false;
        }
        $institutionId = $account?->institution_id ?? $subject?->getAttribute('institution_id') ?? $institution?->id;
        $actionId = $this->conceptId(Vocab::permission($action));
        $kindId = $this->conceptId(Vocab::kind($kind));
        if ($institutionId === null || $actionId === null || $kindId === null) {
            return false;
        }
        $roleIds = $this->roleIds($account, $institutionId);

        $cells = DB::table('permissions')
            ->where('institution_id', $institutionId)->where('kind_id', $kindId)->where('action_id', $actionId)
            ->where(fn ($q) => $q->whereIn('role_id', $roleIds)->when($account, fn ($q) => $q->orWhere('user_id', $account->id)))
            ->get(['user_id', 'role_id', 'repository_id', 'subject_id', 'is_grant']);
        if ($cells->isEmpty()) {
            return false;
        }

        // From the most specific scope to the least: the record and the units above it,
        // then its repository, then everything.
        foreach ($this->scopes($subject) as [$field, $value]) {
            $here = $cells->filter(fn ($c) => match ($field) {
                'subject_id' => $c->subject_id === $value,
                'repository_id' => $c->subject_id === null && $c->repository_id === $value,
                default => $c->subject_id === null && $c->repository_id === null,
            });
            // The account's own permissions before its roles'; at each level a denial wins.
            foreach ([$here->whereNotNull('user_id'), $here->whereNotNull('role_id')] as $level) {
                if ($level->isEmpty()) {
                    continue;
                }

                return ! $level->contains(fn ($c) => ! $c->is_grant);
            }
        }

        return false;
    }

    /**
     * Does the account hold this action on this kind anywhere: in general, or for some
     * repository or record? For menus and lists, which then show only what it may open.
     */
    public function allowsAnywhere(?User $account, string $action, string $kind, ?Institution $institution = null): bool
    {
        if ($this->allows($account, $action, $kind, null, $institution)) {
            return true;
        }
        if ($account?->isDisabled()) {
            return false;
        }
        $institutionId = $account?->institution_id ?? $institution?->id;
        $actionId = $this->conceptId(Vocab::permission($action));
        $kindId = $this->conceptId(Vocab::kind($kind));
        if ($institutionId === null || $actionId === null || $kindId === null) {
            return false;
        }
        $roleIds = $this->roleIds($account, $institutionId);

        return DB::table('permissions')
            ->where('institution_id', $institutionId)->where('kind_id', $kindId)->where('action_id', $actionId)->where('is_grant', true)
            ->where(fn ($q) => $q->whereNotNull('repository_id')->orWhereNotNull('subject_id'))
            ->where(fn ($q) => $q->whereIn('role_id', $roleIds)->when($account, fn ($q) => $q->orWhere('user_id', $account->id)))
            ->exists();
    }

    /**
     * May the account write values of this record in this language? Its original
     * language needs update; another language needs update, or translate together
     * with that language among the account's roles' translation languages (ADR 0005).
     */
    public function allowsLanguage(User $account, string $kind, Model $subject, string $language, string $originalLanguage): bool
    {
        if ($this->allows($account, 'update', $kind, $subject)) {
            return true;
        }
        if ($language === $originalLanguage || ! $this->allows($account, 'translate', $kind, $subject)) {
            return false;
        }

        return in_array($language, $this->translationLanguages($account), true);
    }

    /** @return list<string> the languages any of the account's roles may translate into */
    public function translationLanguages(User $account): array
    {
        return DB::table('role_translation_languages')->whereIn('role_id', $this->roleIds($account, $account->institution_id))
            ->distinct()->orderBy('language')->pluck('language')->all();
    }

    /** Forget the roles cached for an account, after its membership changed. */
    public function forget(User $account): void
    {
        unset($this->roles[$account->id]);
    }

    public function flush(): void
    {
        $this->roles = [];
        $this->concepts = [];
    }

    /** @return list<array{0: string, 1: ?string}> */
    private function scopes(?Model $subject): array
    {
        // An instantiation is read and changed as its unit of description is.
        if ($subject instanceof Instantiation) {
            $subject = $subject->record;
        }
        $scopes = [];
        if ($subject instanceof RecordResource) {
            for ($unit = $subject; $unit !== null; $unit = $unit->parent) {
                $scopes[] = ['subject_id', $unit->id];
            }
            $scopes[] = ['repository_id', $subject->repository_id];
        } elseif ($subject instanceof Repository) {
            $scopes[] = ['subject_id', $subject->id];
            $scopes[] = ['repository_id', $subject->id];
        } elseif ($subject !== null) {
            $scopes[] = ['subject_id', $subject->getKey()];
        }
        $scopes[] = ['all', null];

        return $scopes;
    }

    /** The account's roles and every role above them, then Signed in and Public (for the public, Public only). */
    private function roleIds(?User $account, string $institutionId): array
    {
        $key = $account?->id ?? 'public:'.$institutionId;
        if (isset($this->roles[$key])) {
            return $this->roles[$key];
        }
        $start = $account
            ? Role::whereIn('id', DB::table('role_members')->where('user_id', $account->id)->pluck('role_id'))->get()
            : collect();
        foreach ($account ? ['signed-in', 'public'] : ['public'] as $system) {
            $role = Role::where('institution_id', $institutionId)->whereHas('template', fn ($q) => $q->where('iri', Vocab::role($system)))->first();
            if ($role) {
                $start->push($role);
            }
        }
        $ids = [];
        foreach ($start as $role) {
            foreach ($role->lineage() as $ancestor) {
                $ids[$ancestor->id] = true;
            }
        }

        return $this->roles[$key] = array_keys($ids);
    }

    private function conceptId(string $iri): ?string
    {
        return array_key_exists($iri, $this->concepts)
            ? $this->concepts[$iri]
            : $this->concepts[$iri] = Concept::where('iri', $iri)->whereNull('deprecated_at')->value('id');
    }
}
