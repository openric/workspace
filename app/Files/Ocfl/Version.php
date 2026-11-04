<?php

// OpenRiC Workspace - one version of an OCFL object (OCFL 1.1, section 3.5.3.1), copied from Heratio's ahg-ocfl Version
// (roadmap phase 15b): when it was made, by whom, why, and its state, the digest of each of its files by logical path.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Files\Ocfl;

use DateTimeImmutable;
use DateTimeInterface;

final class Version
{
    /** @param array<string, list<string>> $state digest => logical paths */
    public function __construct(public readonly string $created, public readonly array $state, public readonly string $message = '',
        public readonly ?string $userName = null, public readonly ?string $userAddress = null) {}

    public static function now(array $state, string $message = '', ?string $userName = null, ?string $userAddress = null): self
    {
        return new self((new DateTimeImmutable('now'))->format(DateTimeInterface::RFC3339), $state, $message, $userName, $userAddress);
    }

    public function toInventoryArray(): array
    {
        $state = $this->state;
        ksort($state, SORT_STRING);
        $out = ['created' => $this->created, 'message' => $this->message, 'state' => $state];
        // OCFL 1.1 3.5.3.1: a user, where given, has a name, and may have an address.
        if ($this->userName !== null && $this->userName !== '') {
            $out['user'] = array_filter(['name' => $this->userName, 'address' => $this->userAddress]);
        }

        return $out;
    }

    public static function fromInventoryArray(array $data): self
    {
        return new self((string) ($data['created'] ?? ''), is_array($data['state'] ?? null) ? $data['state'] : [], (string) ($data['message'] ?? ''),
            isset($data['user']['name']) ? (string) $data['user']['name'] : null, isset($data['user']['address']) ? (string) $data['user']['address'] : null);
    }

    /** @return array<string, string> logical path => digest */
    public function paths(): array
    {
        $out = [];
        foreach ($this->state as $digest => $paths) {
            foreach ((array) $paths as $path) {
                $out[$path] = $digest;
            }
        }

        return $out;
    }
}
