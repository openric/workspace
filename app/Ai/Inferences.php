<?php

// OpenRiC Workspace - the log of inferences (roadmap phase 17a), after Heratio's ahg-provenance-ai InferenceService and
// ahg-inference-receipts ReceiptChain: every call a model answered through the AI gateway, kept as a chain of receipts
// that each hold the hash of the one before and are signed with the installation's Ed25519 key, the audit log's key, so
// that a row changed, removed or put in out of order is found by verify(). The hash covers every column but the
// chain's own, canonicalised by RFC 8785 (JCS) as the audit chain's are.
// Changed from Heratio: the inference record and its receipt are one row; the chain is written inside the database
// under an advisory lock, as the audit chain is, rather than in a separate store.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Ai;

use App\Audit\AuditChain;
use App\Audit\Crypto\JcsEncoder;
use App\Audit\Crypto\Signer;
use App\Models\AiInference;
use App\Models\Concept;
use App\Support\Vocab;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Inferences
{
    /** Columns that are the chain itself, or signed separately (occurred_at is the signed ts). */
    private const UNSIGNED = ['seq', 'prev_hash', 'entry_hash', 'signature', 'kid', 'occurred_at'];

    /** An arbitrary constant, not the audit chain's: the advisory lock on this chain's head. */
    private const LOCK_KEY = 7_316_524_917;

    /**
     * Record one call. $call: institution_id, service (a notation of ai-service), model, model_version, endpoint,
     * input (what was sent, hashed), output (what came back, hashed), confidence, subject (a model), field,
     * elapsed_ms, succeeded, error, details, user_id.
     */
    public function record(array $call): AiInference
    {
        $signer = AuditChain::signer();
        $input = (string) ($call['input'] ?? '');
        $output = $call['output'] ?? null;
        $subject = $call['subject'] ?? null;
        $row = [
            'id' => (string) Str::uuid7(), 'institution_id' => $call['institution_id'],
            'service_id' => Concept::where('iri', Vocab::BASE.'ai-service/'.$call['service'])->valueOrFail('id'),
            'model' => Str::limit((string) ($call['model'] ?? 'unknown'), 255, ''), 'model_version' => Str::limit((string) ($call['model_version'] ?? 'unknown') ?: 'unknown', 64, ''),
            'endpoint' => Str::limit((string) $call['endpoint'], 500, ''), 'input_hash' => hash('sha256', $input), 'input_excerpt' => self::excerpt($call['input_excerpt'] ?? $input),
            'output_hash' => $output === null ? null : hash('sha256', (string) $output), 'output_excerpt' => $output === null ? null : self::excerpt((string) $output),
            'confidence' => isset($call['confidence']) ? number_format(max(0.0, min(1.0, (float) $call['confidence'])), 5, '.', '') : null,
            'subject_table' => $subject?->getTable(), 'subject_id' => $subject?->getKey(), 'field' => $call['field'] ?? null,
            'elapsed_ms' => isset($call['elapsed_ms']) ? (int) $call['elapsed_ms'] : null, 'succeeded' => (bool) $call['succeeded'],
            'error' => isset($call['error']) ? Str::limit((string) $call['error'], 2000, '') : null,
            'details' => json_encode((object) ($call['details'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'user_id' => $call['user_id'] ?? null,
        ];

        DB::transaction(function () use (&$row, $signer) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            $head = DB::table('ai_inferences')->orderByDesc('seq')->first(['seq', 'entry_hash']);
            $row['occurred_at'] = AuditChain::timestamp(new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $row['seq'] = $head === null ? 0 : ((int) $head->seq) + 1;
            $row['prev_hash'] = $head === null ? AuditChain::GENESIS_PREV_HASH : (string) $head->entry_hash;
            $row['kid'] = $signer->keyPair()->kid();
            $row['entry_hash'] = hash('sha256', JcsEncoder::encode(self::signingView($row)));
            $row['signature'] = $signer->signBase64(hex2bin($row['entry_hash']));
            DB::table('ai_inferences')->insert($row);
        });

        return AiInference::findOrFail($row['id']);
    }

    /** The view that is hashed and signed, by record() and by verify(), so the two cannot drift apart. */
    public static function signingView(array $row): array
    {
        $payload = [];
        foreach ($row as $column => $value) {
            if ($value === null || in_array($column, self::UNSIGNED, true)) {
                continue;
            }
            $payload[$column] = $column === 'details' && is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        }
        ksort($payload);

        return ['v' => AuditChain::VERSION, 'seq' => (int) $row['seq'], 'ts' => AuditChain::timestamp(new DateTimeImmutable((string) $row['occurred_at'])),
            'prev_hash' => (string) $row['prev_hash'], 'payload' => $payload, 'kid' => (string) $row['kid'], 'alg' => AuditChain::ALG];
    }

    /**
     * Walk the whole chain, recomputing every hash and checking every signature.
     *
     * @return array{intact: bool, rows: int, problem: ?string}
     */
    public function verify(): array
    {
        $keys = DB::table('audit_signing_keys')->pluck('public_key', 'kid')->map(fn ($k) => base64_decode($k, true))->all();
        [$expected, $prev] = [0, AuditChain::GENESIS_PREV_HASH];
        foreach (DB::table('ai_inferences')->orderBy('seq')->lazyById(500, 'seq') as $row) {
            $row = (array) $row;
            $seq = (int) $row['seq'];
            $problem = match (true) {
                $seq !== $expected => "expected receipt $expected, found $seq: a receipt is missing",
                $row['prev_hash'] !== $prev => "receipt $seq does not point to the one before it",
                hash('sha256', JcsEncoder::encode(self::signingView($row))) !== $row['entry_hash'] => "receipt $seq does not match its hash: it was changed",
                ! isset($keys[$row['kid']]) => "receipt $seq is signed with an unknown key {$row['kid']}",
                ! Signer::verifyBase64($row['signature'], hex2bin($row['entry_hash']), $keys[$row['kid']]) => "the signature of receipt $seq is not valid",
                default => null,
            };
            if ($problem !== null) {
                return ['intact' => false, 'rows' => $seq, 'problem' => $problem];
            }
            [$expected, $prev] = [$seq + 1, $row['entry_hash']];
        }

        return ['intact' => true, 'rows' => $expected, 'problem' => null];
    }

    /** Calls the institution's models answered this calendar month. */
    public function thisMonth(string $institutionId): int
    {
        return AiInference::where('institution_id', $institutionId)->where('occurred_at', '>=', now()->startOfMonth())->count();
    }

    private static function excerpt(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
