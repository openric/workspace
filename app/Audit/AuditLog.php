<?php

// OpenRiC Workspace - write one row to the append-only audit log.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The audit log is append-only in the database (migration 2026_10_19_000200),
// and every row is chained and signed (AuditChain, ADR 0004). A row is written
// in the same transaction as the change it records, so the two commit or roll
// back together; if the row cannot be signed, the change does not happen.

namespace App\Audit;

use App\Models\Concept;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

class AuditLog
{
    /** Fields whose values are never written to the log; the log records only that they changed. */
    private const REDACTED = ['password', 'remember_token'];

    /** @var array<string, string> action IRI => concept id */
    private static array $actions = [];

    /** The account to record as actor inside actingAs(), when it is not the signed-in one. */
    private static ?User $actingAs = null;

    /**
     * Run $work with $actor recorded as the actor of every audit row it writes,
     * including the rows written automatically by the Audited trait.
     */
    public static function actingAs(?User $actor, callable $work): mixed
    {
        $previous = self::$actingAs;
        // A nested call without an actor keeps the actor of the call around it.
        self::$actingAs = $actor ?? $previous;
        try {
            return $work();
        } finally {
            self::$actingAs = $previous;
        }
    }

    public static function record(
        string $actionIri,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
        ?string $institutionId = null,
    ): void {
        $actor ??= self::$actingAs ?? auth()->user();
        $institutionId ??= match (true) {
            $subject instanceof Institution => $subject->getKey(),
            $subject !== null && $subject->getAttribute('institution_id') !== null => $subject->getAttribute('institution_id'),
            default => $actor?->institution_id,
        };
        $request = app()->runningInConsole() ? null : request();

        AuditChain::append([
            'id' => (string) Str::uuid7(),
            'institution_id' => $institutionId,
            'actor_id' => $actor?->getKey(),
            'action_id' => self::action($actionIri),
            'subject_table' => $subject?->getTable(),
            'subject_id' => $subject?->getKey(),
            'before' => self::encode($before),
            'after' => self::encode($after),
            'request_id' => $request?->header('X-Request-Id') ?? ($request ? null : 'console'),
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
        ]);
    }

    private static function action(string $iri): string
    {
        return self::$actions[$iri] ??= Concept::where('iri', $iri)->value('id')
            ?? throw new RuntimeException("audit action $iri is not loaded; run workspace:install or import the audit-action vocabulary");
    }

    private static function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        foreach (array_intersect_key($values, array_flip(self::REDACTED)) as $field => $value) {
            $values[$field] = '[redacted]';
        }

        return json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Clear the action cache; for tests that rebuild the database. */
    public static function flush(): void
    {
        self::$actions = [];
    }
}
