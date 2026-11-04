<?php

// OpenRiC Workspace - an OCFL object's inventory (OCFL 1.1, section 3.5), copied from Heratio's ahg-ocfl Inventory (roadmap
// phase 15b): the manifest of every content file by its digest, every version, and, added here, a fixity block of the
// SHA-256 the Workspace keeps for each file, so the object says by itself what the Workspace checks.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Files\Ocfl;

use InvalidArgumentException;

final class Inventory
{
    public const TYPE_URI = 'https://ocfl.io/1.1/spec/#inventory';

    /**
     * @param  array<string, list<string>>  $manifest  digest => content paths
     * @param  array<string, Version>  $versions
     * @param  array<string, array<string, list<string>>>  $fixity  algorithm => digest => content paths
     */
    public function __construct(public readonly string $id, public readonly string $head, public readonly array $manifest, public readonly array $versions,
        public readonly string $digestAlgorithm = 'sha512', public readonly array $fixity = [])
    {
        $id !== '' || throw new InvalidArgumentException('Inventory: id cannot be empty');
        preg_match('/^v\d+$/', $head) || throw new InvalidArgumentException("Inventory: head '$head' is not vN");
        isset($versions[$head]) || throw new InvalidArgumentException("Inventory: head '$head' missing from versions");
    }

    public function nextVersionId(): string
    {
        return 'v'.(max(array_map(fn ($k) => (int) substr((string) $k, 1), array_keys($this->versions))) + 1);
    }

    public function headVersion(): Version
    {
        return $this->versions[$this->head];
    }

    public function toJson(): string
    {
        $manifest = $this->manifest;
        ksort($manifest, SORT_STRING);
        $versions = [];
        foreach ($this->sortedVersionKeys() as $k) {
            $versions[$k] = $this->versions[$k]->toInventoryArray();
        }
        $payload = ['digestAlgorithm' => $this->digestAlgorithm];
        if ($this->fixity !== []) {
            $fixity = $this->fixity;
            ksort($fixity, SORT_STRING);
            $payload['fixity'] = array_map(function ($d) {
                ksort($d, SORT_STRING);

                return $d;
            }, $fixity);
        }
        $payload += ['head' => $this->head, 'id' => $this->id, 'manifest' => $manifest, 'type' => self::TYPE_URI, 'versions' => $versions];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public static function fromJson(string $bytes): self
    {
        $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        is_array($data) || throw new InvalidArgumentException('Inventory: JSON did not decode to an object');
        $versions = [];
        foreach ((array) ($data['versions'] ?? []) as $k => $v) {
            $versions[(string) $k] = Version::fromInventoryArray((array) $v);
        }

        return new self((string) ($data['id'] ?? ''), (string) ($data['head'] ?? ''), (array) ($data['manifest'] ?? []), $versions,
            (string) ($data['digestAlgorithm'] ?? 'sha512'), (array) ($data['fixity'] ?? []));
    }

    /** @return list<string> */
    public function sortedVersionKeys(): array
    {
        $keys = array_keys($this->versions);
        usort($keys, fn ($a, $b) => ((int) substr((string) $a, 1)) <=> ((int) substr((string) $b, 1)));

        return $keys;
    }
}
