<?php

/**
 * ApiKeyService - Service for Heratio
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
 * OpenRiC Workspace - ported from Heratio, packages/ahg-api/src/Services/ApiKeyService.php
 * at commit 5100c0f92 (heratio-dev; governance/CODE_PROVENANCE.md, ADR 0007).
 * Changed: Workspace tables and UUIDs; scopes are api-scope concepts, and the scopes an
 * account may give its keys come from config workspace.api_scope_requires and its
 * permissions; a key lists its own scopes cut to what its account holds now, so an
 * account that loses a permission loses the scope on every key at once; revoking is
 * audited and keeps the row; one class issues keys for oneself and, for an
 * administrator, for another account of the institution.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Accounts;

use App\Audit\AuditLog;
use App\Models\ApiKey;
use App\Models\Concept;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiKeyService
{
    /** @return Collection<int, Concept> the scopes the account's keys may carry now, in vocabulary order */
    public function allowedScopes(User $account): Collection
    {
        return $this->scopeConcepts()->filter(function (Concept $scope) use ($account) {
            $requires = config('workspace.api_scope_requires')[$this->notation($scope)] ?? null;

            return $requires !== null && ($requires === [] || collect($requires)->contains(fn ($r) => $account->mayAnywhere($r[0], $r[1])));
        })->values();
    }

    /**
     * Issue a key for $owner with the chosen scopes (api-scope notations), cut to what
     * the owner may hold; read when none is left. Returns the key and, once only, its secret.
     *
     * @param  list<string>  $scopes
     * @return array{0: ApiKey, 1: string}
     */
    public function issue(User $owner, string $name, array $scopes, ?CarbonImmutable $expiresAt = null, ?int $dailyLimit = null, ?User $actor = null): array
    {
        $allowed = $this->allowedScopes($owner);
        $chosen = $allowed->filter(fn (Concept $c) => in_array($this->notation($c), $scopes, true));
        if ($chosen->isEmpty()) {
            $chosen = $allowed->filter(fn (Concept $c) => $this->notation($c) === 'read');
        }
        $secret = Str::random(48);

        $key = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($owner, $name, $secret, $expiresAt, $dailyLimit, $chosen, $actor) {
            $key = ApiKey::create([
                'institution_id' => $owner->institution_id,
                'user_id' => $owner->id,
                'name' => trim($name),
                'key_hash' => hash('sha256', $secret),
                'prefix' => substr($secret, 0, 8),
                'hourly_limit' => config('workspace.api_key_hourly_limit'),
                'daily_limit' => $dailyLimit,
                'expires_at' => $expiresAt,
                'created_by_id' => $actor?->id ?? $owner->id,
            ]);
            $key->scopes()->sync($chosen->pluck('id'));
            AuditLog::record(Vocab::action('update'), $key, null, ['scopes' => $chosen->pluck('iri')->values()->all()]);

            return $key;
        }));

        return [$key, $secret];
    }

    /** @return Collection<int, ApiKey> the account's keys, newest first, revoked ones included */
    public function keysFor(User $owner): Collection
    {
        return ApiKey::where('user_id', $owner->id)->with('scopes.labels')->orderByDesc('created_at')->get();
    }

    /** Revoke a key; anything using it is refused at once. The row stays, for the audit log. */
    public function revoke(ApiKey $key, ?User $actor = null): void
    {
        if ($key->isRevoked()) {
            return;
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $key->update(['revoked_at' => now(), 'revoked_by_id' => $actor?->id])));
    }

    /** The usable key with this secret, or null. */
    public function resolve(string $secret): ?ApiKey
    {
        $key = ApiKey::where('key_hash', hash('sha256', $secret))->with('user')->first();

        return $key?->isUsable() ? $key : null;
    }

    /** @return list<string> the scope notations the key may use now: its own, cut to what its account holds */
    public function effectiveScopes(ApiKey $key): array
    {
        $allowed = $this->allowedScopes($key->user)->pluck('id')->all();

        return $key->scopes->filter(fn (Concept $c) => in_array($c->id, $allowed, true))->map(fn (Concept $c) => $this->notation($c))->values()->all();
    }

    public function notation(Concept $scope): string
    {
        return $scope->notation;
    }

    /** @return Collection<int, Concept> */
    private function scopeConcepts(): Collection
    {
        return Concept::choices(Vocab::API_SCOPES)->get();
    }
}
