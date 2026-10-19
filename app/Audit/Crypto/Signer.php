<?php

// OpenRiC Workspace - copied from Heratio, packages/ahg-inference-receipts/src/Signer.php
// at commit a060339c592bfb4d903679899c04bd56c5fe5744 (governance/CODE_PROVENANCE.md).
// Copyright 2026 Plain Sailing Information Systems; author Johan Pieterse.
// Licensed under the Apache License, Version 2.0 (governance/licences/ahg-inference-receipts.LICENSE,
// NOTICE in governance/licences/ahg-inference-receipts.NOTICE). Changed: namespace only.
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Audit\Crypto;

use RuntimeException;
use SodiumException;

/**
 * Ed25519 detached signatures over arbitrary byte strings via ext-sodium.
 *
 * Stateless wrapper. The library hashes the canonical form of a receipt
 * first (so signatures are over fixed-length 32-byte digests, not raw
 * payloads), but this class signs whatever you hand it - the chain
 * decides what "the right thing" is.
 */
final class Signer
{
    public function __construct(private KeyPair $keyPair)
    {
    }

    public function keyPair(): KeyPair
    {
        return $this->keyPair;
    }

    public function sign(string $message): string
    {
        try {
            return sodium_crypto_sign_detached($message, $this->keyPair->secretKey());
        } catch (SodiumException $e) {
            throw new RuntimeException('Signer: sodium signing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function signBase64(string $message): string
    {
        return base64_encode($this->sign($message));
    }

    public function signHex(string $message): string
    {
        return bin2hex($this->sign($message));
    }

    public static function verify(string $signature, string $message, string $publicKey): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }

    public static function verifyBase64(string $signatureBase64, string $message, string $publicKey): bool
    {
        $sig = base64_decode($signatureBase64, true);
        if ($sig === false) {
            return false;
        }
        return self::verify($sig, $message, $publicKey);
    }

    public static function verifyHex(string $signatureHex, string $message, string $publicKey): bool
    {
        if (!ctype_xdigit($signatureHex) || strlen($signatureHex) !== SODIUM_CRYPTO_SIGN_BYTES * 2) {
            return false;
        }
        return self::verify(hex2bin($signatureHex), $message, $publicKey);
    }
}
