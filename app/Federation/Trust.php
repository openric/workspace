<?php

// OpenRiC Workspace - federation trust (roadmap phase 16a), after Heratio's ahg-federation FederationSigner and
// FederationVerifier: every answer to a peer is signed with the installation's one Ed25519 key, the audit signing key,
// as Heratio signs with its one key; and a peer's answer is verified against its published key, pinned on first use, so
// a peer that later signs with another key is not trusted until an operator clears the pin. The scheme is Heratio's,
// so a Workspace and a Heratio verify each other: a detached Ed25519 signature over the SHA-256 of the exact bytes sent,
// hex, in X-Federation-Signature, with X-Federation-Key-Id and X-Federation-Sig-Alg ed25519-sha256-hex; the key
// published as {"keys": [{"kid", "public_key": {"hex", "base64"}}]}, its address named by /open-data/protocol.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Audit\AuditChain;
use App\Audit\Crypto\KeyPair;
use App\Models\FederationPeer;
use App\Webhooks\Webhooks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Trust
{
    public const SIGNATURE = 'X-Federation-Signature';

    public const KEY_ID = 'X-Federation-Key-Id';

    public const ALGORITHM = 'X-Federation-Sig-Alg';

    public const SCHEME = 'ed25519-sha256-hex';

    private ?KeyPair $key = null;

    /** The response, signed over its exact body; unsigned where the key cannot be read, which a peer treats as unverified. */
    public function sign(Response $response): Response
    {
        try {
            $key = $this->key();
            $signature = sodium_crypto_sign_detached(hash('sha256', (string) $response->getContent(), true), $key->secretKey());
            $response->headers->set(self::SIGNATURE, bin2hex($signature));
            $response->headers->set(self::KEY_ID, $key->kid());
            $response->headers->set(self::ALGORITHM, self::SCHEME);
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', [self::SIGNATURE, self::KEY_ID, self::ALGORITHM]));
        } catch (Throwable) {
            // Signing never breaks an answer.
        }

        return $response;
    }

    /** @return array{keys: list<array{kid: string, alg: string, public_key: array{hex: string, base64: string}}>} */
    public function keyDocument(): array
    {
        $key = $this->key();

        return ['keys' => [['kid' => $key->kid(), 'alg' => 'ed25519', 'use' => 'federation', 'public_key' => ['hex' => $key->publicKeyHex(), 'base64' => $key->publicKeyBase64()]]]];
    }

    /**
     * Whether a peer's answer is signed by its key: verified, pinned (verified, its key pinned now), unsigned,
     * mismatch (another key than the one pinned) or invalid. A key is pinned only from a signature that verifies.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function verify(FederationPeer $peer, string $body, array $headers): string
    {
        $header = fn (string $name) => strtolower(trim((string) (collect($headers)->first(fn ($v, $k) => strcasecmp($k, $name) === 0) ?? [''])[0] ?? ''));
        [$signature, $kid] = [$header(self::SIGNATURE), $header(self::KEY_ID)];
        if ($signature === '' || $kid === '') {
            return 'unsigned';
        }
        if ($peer->pinned_key_id !== null && $peer->pinned_key_id !== $kid) {
            return 'mismatch';
        }
        $public = $this->peerKey((string) $peer->base_url, $kid);
        $raw = strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES * 2 ? @hex2bin($signature) : false;
        if ($public === null || $raw === false || ! sodium_crypto_sign_verify_detached($raw, hash('sha256', $body, true), $public)) {
            return 'invalid';
        }
        if ($peer->pinned_key_id === null) {
            $peer->forceFill(['pinned_key_id' => $kid, 'key_pinned_at' => now()])->save();

            return 'pinned';
        }

        return 'verified';
    }

    /** The peer's public key with that id: from the address its /open-data/protocol names, else the Workspace's and Heratio's own. */
    private function peerKey(string $base, string $kid): ?string
    {
        $base = rtrim($base, '/');
        if ($base === '' || ! Webhooks::publicUrl($base)) {
            return null;
        }
        $documents = Cache::remember('federation:key:'.sha1($base), 3600, function () use ($base) {
            $get = fn (string $url) => rescue(fn () => Http::timeout(5)->acceptJson()->get($url)->json(), null, false);
            $named = $get($base.'/open-data/protocol')['federation']['public_key_url'] ?? null;
            $urls = array_values(array_filter([is_string($named) && Webhooks::publicUrl($named) ? $named : null, $base.'/.well-known/openric-federation-key', $base.'/.well-known/ai-inference-pubkey']));

            return array_values(array_filter(array_map($get, array_unique($urls)), 'is_array'));
        });
        foreach ($documents as $document) {
            foreach ((array) ($document['keys'] ?? []) as $key) {
                if ((string) ($key['kid'] ?? '') !== $kid) {
                    continue;
                }
                $bin = ! empty($key['public_key']['hex']) ? @hex2bin((string) $key['public_key']['hex']) : base64_decode((string) ($key['public_key']['base64'] ?? ''), true);
                if (is_string($bin) && strlen($bin) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    return $bin;
                }
            }
        }

        return null;
    }

    private function key(): KeyPair
    {
        return $this->key ??= KeyPair::loadFrom(AuditChain::keyPath());
    }
}
