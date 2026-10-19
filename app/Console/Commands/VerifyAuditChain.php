<?php

/**
 * Heratio - walk ahg_audit_log chained rows and verify hashes + signatures.
 *
 * @copyright Copyright (c) 2026, Plain Sailing Information Systems
 * @author    Johan Pieterse <johan@plainsailingisystems.co.za>
 * @license   AGPL-3.0-or-later
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-audit-trail/src/Console/Commands/VerifyChainCommand.php
 * at commit a060339c592bfb4d903679899c04bd56c5fe5744 (governance/CODE_PROVENANCE.md, ADR 0004).
 * Changed: the Workspace's audit_log and audit_signing_keys tables; the signing view comes from
 * AuditChain::signingView(), shared with the writer; rows are read in pages by seq; the --from,
 * --to, --limit and --quiet-pass options are dropped, so the whole chain is always checked from
 * its first row; it exits with failure on the first break.
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditChain;
use App\Audit\Crypto\JcsEncoder;
use App\Audit\Crypto\Signer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyAuditChain extends Command
{
    protected $signature = 'workspace:audit:verify';

    protected $description = 'Walk the audit chain, recompute every hash and check every signature';

    public function handle(): int
    {
        $keys = DB::table('audit_signing_keys')->pluck('public_key', 'kid')->map(fn ($k) => base64_decode($k, true))->all();
        $unchained = DB::table('audit_log')->whereNull('seq')->count();
        $expectedSeq = 0;
        $prevHash = AuditChain::GENESIS_PREV_HASH;

        foreach (DB::table('audit_log')->whereNotNull('seq')->orderBy('seq')->lazyById(500, 'seq') as $row) {
            $row = (array) $row;
            $seq = (int) $row['seq'];
            $problem = match (true) {
                $seq !== $expectedSeq => "expected row $expectedSeq, found $seq: a row is missing",
                $row['prev_hash'] !== $prevHash => 'it does not point to the row before it',
                hash('sha256', JcsEncoder::encode(AuditChain::signingView($row))) !== $row['entry_hash'] => 'its content does not match its hash: it was changed',
                ! isset($keys[$row['kid']]) => "it is signed with an unknown key {$row['kid']}",
                ! Signer::verifyBase64($row['signature'], hex2bin($row['entry_hash']), $keys[$row['kid']]) => 'its signature is not valid',
                default => null,
            };
            if ($problem !== null) {
                $this->error("The audit chain is broken at row $seq: $problem.");

                return self::FAILURE;
            }
            $expectedSeq = $seq + 1;
            $prevHash = $row['entry_hash'];
        }

        $this->info("The audit chain is intact: $expectedSeq chained rows".($unchained ? ", and $unchained earlier rows written before the chain began." : '.'));

        return self::SUCCESS;
    }
}
