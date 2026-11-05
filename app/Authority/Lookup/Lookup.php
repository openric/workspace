<?php

/**
 * AbstractLookupAdapter - Service for Heratio. An outside authority searched for a name: off unless the institution
 * switches it on, its answers cached for as long as it allows, at most so many calls a minute, and a failure answered
 * with nothing rather than an error.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Lookup/AbstractLookupAdapter.php
 * and LookupAdapterInterface.php at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the
 * settings are the institution's (Settings::sources()); the rate limit is Laravel's RateLimiter, so it holds across
 * requests and workers rather than one PHP process; the cache is authority_lookup_cache, keyed by the source concept.
 */

declare(strict_types=1);

namespace App\Authority\Lookup;

use App\Authority\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

abstract class Lookup
{
    /** As the Workspace introduces itself to an outside authority. */
    protected const USER_AGENT = 'OpenRiC Workspace authority resolution (https://openric.org/application/)';

    protected const HTTP_TIMEOUT = 8;

    /** @var array<string, mixed>|null the institution's settings for this source */
    protected ?array $settings = null;

    abstract public function source(): string;

    /** spaCy's label: PERSON, ORG, GPE. */
    abstract public function supports(string $label): bool;

    /**
     * @return list<array{source: string, external_id: string, external_uri: string, authorized_name: string, dates_of_existence: ?string,
     *   history_snippet: ?string, fields: array<string, mixed>, licence: string, licence_url: string, retrieved_at: string}>
     */
    abstract protected function fetchFromSource(string $query, string $label, int $limit): array;

    public function for(string $institutionId): static
    {
        $this->settings = app(Settings::class)->sources($institutionId)[$this->source()] ?? null;

        return $this;
    }

    public function enabled(): bool
    {
        return (bool) ($this->settings['enabled'] ?? false);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query, string $label, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '' || ! $this->supports($label) || ! $this->enabled()) {
            return [];
        }
        if (($cached = $this->readCache($query, $label)) !== null) {
            return array_slice($cached, 0, $limit);
        }
        $key = 'authority-lookup:'.$this->source();
        if (RateLimiter::tooManyAttempts($key, (int) $this->settings['rate_limit'])) {
            Log::info('[workspace-authority] lookup rate limited', ['source' => $this->source(), 'query' => $query]);

            return [];
        }
        RateLimiter::hit($key, 60);
        try {
            $results = $this->fetchFromSource($query, $label, $limit);
        } catch (Throwable $e) {
            Log::warning('[workspace-authority] lookup failed', ['source' => $this->source(), 'query' => $query, 'error' => $e->getMessage()]);
            $results = [];
        }
        $this->writeCache($query, $label, $results);

        return array_slice($results, 0, $limit);
    }

    /** @return array{0: string, 1: string} licence and its address */
    protected function licence(): array
    {
        return Settings::LICENCES[$this->source()] ?? ['', ''];
    }

    protected function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function readCache(string $query, string $label): ?array
    {
        $row = DB::table('authority_lookup_cache')->where(['source_id' => $this->settings['source_id'], 'kind' => $label, 'query' => mb_substr($query, 0, 500)])->first();
        if ($row === null || now()->diffInSeconds($row->retrieved_at, true) > (int) $row->ttl) {
            return null;
        }
        $decoded = json_decode((string) $row->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeCache(string $query, string $label, array $results): void
    {
        DB::table('authority_lookup_cache')->upsert([['id' => (string) Str::uuid7(), 'source_id' => $this->settings['source_id'], 'kind' => $label, 'query' => mb_substr($query, 0, 500),
            'payload' => json_encode($results, JSON_UNESCAPED_UNICODE), 'retrieved_at' => now(), 'ttl' => (int) $this->settings['cache_ttl']]],
            ['source_id', 'kind', 'query'], ['payload', 'retrieved_at', 'ttl']);
    }
}
