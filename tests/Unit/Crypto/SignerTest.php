<?php

// OpenRiC Workspace - copied from Heratio, packages/ahg-inference-receipts/tests/Unit/SignerTest.php
// at commit a060339c592bfb4d903679899c04bd56c5fe5744 (governance/CODE_PROVENANCE.md).
// Copyright 2026 Plain Sailing Information Systems; author Johan Pieterse.
// Licensed under the Apache License, Version 2.0 (governance/licences/ahg-inference-receipts.LICENSE,
// NOTICE in governance/licences/ahg-inference-receipts.NOTICE). Changed: namespace only.
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use App\Audit\Crypto\KeyPair;
use App\Audit\Crypto\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function testSignAndVerifyRoundtrip(): void
    {
        $kp = KeyPair::generate();
        $signer = new Signer($kp);
        $msg = 'test message';

        $sig = $signer->sign($msg);
        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($sig));
        $this->assertTrue(Signer::verify($sig, $msg, $kp->publicKey()));
    }

    public function testSignBase64Roundtrip(): void
    {
        $kp = KeyPair::generate();
        $signer = new Signer($kp);
        $msg = 'test message';

        $sigB64 = $signer->signBase64($msg);
        $this->assertTrue(Signer::verifyBase64($sigB64, $msg, $kp->publicKey()));
    }

    public function testSignHexRoundtrip(): void
    {
        $kp = KeyPair::generate();
        $signer = new Signer($kp);
        $msg = 'test message';

        $sigHex = $signer->signHex($msg);
        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES * 2, strlen($sigHex));
        $this->assertTrue(Signer::verifyHex($sigHex, $msg, $kp->publicKey()));
    }

    public function testVerifyFailsOnTamperedMessage(): void
    {
        $kp = KeyPair::generate();
        $signer = new Signer($kp);
        $sig = $signer->sign('original');

        $this->assertFalse(Signer::verify($sig, 'tampered', $kp->publicKey()));
    }

    public function testVerifyFailsOnTamperedSignature(): void
    {
        $kp = KeyPair::generate();
        $signer = new Signer($kp);
        $sig = $signer->sign('msg');

        $tampered = $sig;
        $tampered[0] = chr(ord($tampered[0]) ^ 0x01);
        $this->assertFalse(Signer::verify($tampered, 'msg', $kp->publicKey()));
    }

    public function testVerifyFailsOnWrongPublicKey(): void
    {
        $kp1 = KeyPair::generate();
        $kp2 = KeyPair::generate();
        $signer = new Signer($kp1);
        $sig = $signer->sign('msg');

        $this->assertFalse(Signer::verify($sig, 'msg', $kp2->publicKey()));
    }

    public function testVerifyRejectsMalformedSignature(): void
    {
        $kp = KeyPair::generate();
        $this->assertFalse(Signer::verify('short', 'msg', $kp->publicKey()));
        $this->assertFalse(Signer::verifyBase64('not valid base64!!!@@@', 'msg', $kp->publicKey()));
        $this->assertFalse(Signer::verifyHex('zzz', 'msg', $kp->publicKey()));
    }
}
