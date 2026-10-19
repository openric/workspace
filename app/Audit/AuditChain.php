<?php

/**
 * Heratio - hash-chain writer for ahg_audit_log rows (issue #676 Phase 5).
 *
 * @copyright Copyright (c) 2026, Plain Sailing Information Systems
 * @author    Johan Pieterse <johan@plainsailingisystems.co.za>
 * @license   AGPL-3.0-or-later
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-audit-trail/src/Services/ChainedAuditWriter.php
 * at commit a060339c592bfb4d903679899c04bd56c5fe5744 (governance/CODE_PROVENANCE.md, ADR 0004).
 * Changed: the Workspace's audit_log (UUID ids, jsonb, PostgreSQL); one payload function shared
 * with the verifier instead of two copies kept in step; microsecond timestamps; an advisory lock
 * instead of SELECT ... FOR UPDATE on the chain head; the Workspace's own signing key and key
 * table; and it fails closed: with no key, or on any error, nothing is written and the change
 * being audited rolls back with it, where Heratio fell back to an unsigned row.
 */

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Crypto\JcsEncoder;
use App\Audit\Crypto\KeyPair;
use App\Audit\Crypto\Signer;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AuditChain
{
    public const VERSION = 1;

    public const ALG = 'ed25519';

    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Columns that are the chain itself, or signed separately (occurred_at is the signed ts). */
    private const UNSIGNED_COLUMNS = ['seq', 'prev_hash', 'entry_hash', 'signature', 'kid', 'occurred_at'];

    /** An arbitrary constant: the PostgreSQL advisory lock that serialises writers on the chain head. */
    private const LOCK_KEY = 7_316_524_901;

    private static ?Signer $signer = null;

    private static ?string $signerPath = null;

    /**
     * Insert one audit row, chained and signed, inside the current transaction
     * (or a new one). Throws if the signing key is missing or anything fails.
     *
     * @param  array<string, mixed>  $row  audit_log columns other than the chain columns
     */
    public static function append(array $row): void
    {
        $signer = self::signer();

        DB::transaction(function () use ($row, $signer) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            $head = DB::table('audit_log')->whereNotNull('seq')->orderByDesc('seq')->first(['seq', 'entry_hash']);

            $row['occurred_at'] = self::timestamp(new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $row['seq'] = $head === null ? 0 : ((int) $head->seq) + 1;
            $row['prev_hash'] = $head === null ? self::GENESIS_PREV_HASH : (string) $head->entry_hash;
            $row['kid'] = $signer->keyPair()->kid();
            $row['entry_hash'] = hash('sha256', JcsEncoder::encode(self::signingView($row)));
            $row['signature'] = $signer->signBase64(hex2bin($row['entry_hash']));

            DB::table('audit_log')->insert($row);
        });
    }

    /**
     * The view that is hashed and signed. Used by append() and by the verifier,
     * so the two cannot drift apart.
     *
     * @param  array<string, mixed>  $row  a row as written, or as read back from the database
     * @return array<string, mixed>
     */
    public static function signingView(array $row): array
    {
        $payload = [];
        foreach ($row as $column => $value) {
            if ($value === null || in_array($column, self::UNSIGNED_COLUMNS, true)) {
                continue;
            }
            // jsonb columns come back as JSON text; sign the structure, not the text.
            if (in_array($column, ['before', 'after'], true) && is_string($value)) {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($column === 'ip') {
                $value = inet_ntop(inet_pton((string) $value)) ?: (string) $value;
            }
            $payload[$column] = $value;
        }
        ksort($payload);

        return [
            'v' => self::VERSION,
            'seq' => (int) $row['seq'],
            'ts' => self::timestamp(new DateTimeImmutable((string) $row['occurred_at'])),
            'prev_hash' => (string) $row['prev_hash'],
            'payload' => $payload,
            'kid' => (string) $row['kid'],
            'alg' => self::ALG,
        ];
    }

    /** ISO 8601 UTC with microseconds, as PostgreSQL stores a timestamptz. */
    public static function timestamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** Create the signing key and register its public half. Refuses to replace an existing key. */
    public static function createKey(): string
    {
        $path = self::keyPath();
        if (file_exists($path)) {
            throw new RuntimeException("a signing key already exists at $path");
        }
        $keyPair = KeyPair::generate();
        $keyPair->saveTo($path, $path.'.pub');
        DB::table('audit_signing_keys')->insert(['kid' => $keyPair->kid(), 'public_key' => $keyPair->publicKeyBase64()]);
        self::$signer = null;

        return $keyPair->kid();
    }

    public static function keyPath(): string
    {
        return (string) config('workspace.audit_signing_key');
    }

    private static function signer(): Signer
    {
        $path = self::keyPath();
        if (self::$signer === null || self::$signerPath !== $path) {
            if (! is_readable($path)) {
                throw new RuntimeException("the audit signing key is not readable at $path; run `php artisan workspace:audit:create-key`");
            }
            self::$signer = new Signer(KeyPair::loadFrom($path));
            self::$signerPath = $path;
        }

        return self::$signer;
    }

    /** Forget the loaded key; for tests that change the key path. */
    public static function flush(): void
    {
        self::$signer = null;
        self::$signerPath = null;
    }
}
