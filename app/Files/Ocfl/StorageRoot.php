<?php

// OpenRiC Workspace - the OCFL 1.1 storage root that is the Workspace's one object store (roadmap phase 15b, ADR 0022).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-ocfl StorageRoot. Changed:
// - Objects are laid out by OCFL's own extension 0004, hashed n-tuple (sha256 of the id, three tuples of three, then the
//   whole digest), described in ocfl_layout.json and the extension's config.json, so any OCFL tool finds them. Heratio's
//   "hashed-n-tuple" ended in the url-encoded id and named no published extension.
// - A new version's state is the whole object: the head version's files, less those removed, with those added or
//   replaced. Heratio's held only the files written in that version, so each new version dropped the earlier files.
// - Writes to one object wait for each other (a lock), and a version is put in place only once all of it is written.
// - An object can be purged, as a disposal must: OCFL keeps every version, so erasing a file is removing its object.

namespace App\Files\Ocfl;

use InvalidArgumentException;
use RuntimeException;

final class StorageRoot
{
    public const EXTENSION = '0004-hashed-n-tuple-storage-layout';

    public const ALGORITHM = 'sha512';

    public function __construct(public readonly string $root) {}

    public function initialized(): bool
    {
        return is_file($this->root.'/0=ocfl_1.1');
    }

    public function initialize(): void
    {
        if ($this->initialized()) {
            return;
        }
        is_dir($this->root.'/extensions/'.self::EXTENSION) || mkdir($this->root.'/extensions/'.self::EXTENSION, 0750, true);
        file_put_contents($this->root.'/0=ocfl_1.1', "ocfl_1.1\n");
        file_put_contents($this->root.'/ocfl_layout.json', json_encode(['extension' => self::EXTENSION,
            'description' => 'Hashed n-tuple: the sha256 of the object id, three tuples of three characters, then the whole digest.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($this->root.'/extensions/'.self::EXTENSION.'/config.json', json_encode(['extensionName' => self::EXTENSION, 'digestAlgorithm' => 'sha256',
            'tupleSize' => 3, 'numberOfTuples' => 3, 'shortObjectRoot' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** The object's root, relative to the storage root (extension 0004). */
    public static function objectRoot(string $id): string
    {
        $h = hash('sha256', $id);

        return substr($h, 0, 3).'/'.substr($h, 3, 3).'/'.substr($h, 6, 3).'/'.$h;
    }

    public function exists(string $id): bool
    {
        return is_file($this->root.'/'.self::objectRoot($id).'/inventory.json');
    }

    public function inventory(string $id): ?Inventory
    {
        $path = $this->root.'/'.self::objectRoot($id).'/inventory.json';

        return is_file($path) ? Inventory::fromJson((string) file_get_contents($path)) : null;
    }

    /**
     * A new version of the object: the head's files, less $remove, with $add (logical path => local file) added or replaced.
     *
     * @param  array<string, string>  $add
     * @param  list<string>  $remove
     * @return array<string, string> each added logical path => its content file, relative to the storage root
     */
    public function commit(string $id, array $add, array $remove, string $message, ?string $user = null, ?string $address = null): array
    {
        $this->initialize();
        $objectRoot = self::objectRoot($id);
        $lock = fopen(sys_get_temp_dir().'/ocfl-'.hash('sha256', $this->root.'|'.$id).'.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $existing = $this->inventory($id);
            $version = $existing ? $existing->nextVersionId() : 'v1';
            $paths = $existing ? $existing->headVersion()->paths() : [];
            foreach ($remove as $logical) {
                unset($paths[self::clean($logical)]);
            }
            $manifest = $existing?->manifest ?? [];
            $fixity = $existing?->fixity ?? [];
            $staging = $this->root.'/'.$objectRoot.'/.'.$version.'.partial';
            $written = [];
            foreach ($add as $logical => $local) {
                $logical = self::clean($logical);
                is_file($local) || throw new InvalidArgumentException("OCFL: no file to add at $local");
                $digest = hash_file(self::ALGORITHM, $local);
                $paths[$logical] = $digest;
                if (! isset($manifest[$digest])) {
                    $content = $version.'/content/'.$logical;
                    $target = $staging.'/content/'.$logical;
                    is_dir(dirname($target)) || mkdir(dirname($target), 0750, true);
                    copy($local, $target) || throw new RuntimeException("OCFL: could not write $content");
                    chmod($target, 0640);
                    $manifest[$digest] = [$content];
                    $fixity['sha256'][hash_file('sha256', $local)] = [$content];
                }
                $written[$logical] = $objectRoot.'/'.$manifest[$digest][0];
            }
            $state = [];
            foreach ($paths as $logical => $digest) {
                $state[$digest][] = $logical;
            }
            foreach ($state as &$list) {
                sort($list, SORT_STRING);
            }
            unset($list);
            $versions = $existing?->versions ?? [];
            $versions[$version] = Version::now($state, $message, $user, $address);
            $inventory = new Inventory($id, $version, $manifest, $versions, self::ALGORITHM, $fixity);
            $json = $inventory->toJson();
            $sidecar = hash(self::ALGORITHM, $json)." inventory.json\n";
            is_dir($staging) || mkdir($staging, 0750, true);
            file_put_contents($staging.'/inventory.json', $json);
            file_put_contents($staging.'/inventory.json.'.self::ALGORITHM, $sidecar);
            // The version is put in place whole, then the object's own inventory points to it.
            rename($staging, $this->root.'/'.$objectRoot.'/'.$version) || throw new RuntimeException("OCFL: could not put $version in place");
            file_put_contents($this->root.'/'.$objectRoot.'/0=ocfl_object_1.1', "ocfl_object_1.1\n");
            file_put_contents($this->root.'/'.$objectRoot.'/inventory.json.tmp', $json);
            rename($this->root.'/'.$objectRoot.'/inventory.json.tmp', $this->root.'/'.$objectRoot.'/inventory.json');
            file_put_contents($this->root.'/'.$objectRoot.'/inventory.json.'.self::ALGORITHM, $sidecar);

            return $written;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Remove the object and all its versions, as a disposal must; the folders above it go too where they are left empty. */
    public function purge(string $id): void
    {
        $this->purgeRoot(self::objectRoot($id));
    }

    /** The object root, relative to the storage root, that a content path relative to it lies in. */
    public static function objectRootOf(string $contentPath): string
    {
        return implode('/', array_slice(explode('/', $contentPath), 0, 4));
    }

    /** purge() by the object's root, relative to the storage root. */
    public function purgeRoot(string $objectRoot): void
    {
        preg_match('#^[0-9a-f]{3}/[0-9a-f]{3}/[0-9a-f]{3}/[0-9a-f]{64}$#', $objectRoot) || throw new InvalidArgumentException("OCFL: '$objectRoot' is not an object root");
        $path = $this->root.'/'.$objectRoot;
        if (is_dir($path)) {
            exec('rm -rf '.escapeshellarg($path));
        }
        for ($dir = dirname($path), $i = 0; $i < 3 && is_dir($dir) && count(scandir($dir)) === 2; $i++, $dir = dirname($dir)) {
            rmdir($dir);
        }
    }

    /** What is wrong with the object (OCFL 1.1, section 3): its declaration, its inventory and sidecar, every version's, and every content file's digest. @return list<string> */
    public function verify(string $id): array
    {
        $root = $this->root.'/'.self::objectRoot($id);
        $errors = [];
        is_file($root.'/0=ocfl_object_1.1') || $errors[] = "$id: no 0=ocfl_object_1.1";
        if (! is_file($root.'/inventory.json')) {
            return [...$errors, "$id: no inventory.json"];
        }
        $bytes = (string) file_get_contents($root.'/inventory.json');
        try {
            $inventory = Inventory::fromJson($bytes);
        } catch (\Throwable $e) {
            return [...$errors, "$id: inventory.json unreadable: ".$e->getMessage()];
        }
        $inventory->id === $id || $errors[] = "$id: inventory names another id, {$inventory->id}";
        $sidecar = trim(explode(' ', (string) @file_get_contents($root.'/inventory.json.'.$inventory->digestAlgorithm))[0] ?? '');
        $sidecar === hash($inventory->digestAlgorithm, $bytes) || $errors[] = "$id: inventory.json does not match its sidecar";
        foreach ($inventory->sortedVersionKeys() as $v) {
            is_file($root.'/'.$v.'/inventory.json') || $errors[] = "$id: $v has no inventory.json";
            foreach ($inventory->versions[$v]->state as $digest => $paths) {
                isset($inventory->manifest[$digest]) || $errors[] = "$id: $v names a digest the manifest does not hold";
            }
        }
        is_file($root.'/'.$inventory->head.'/inventory.json') && (string) file_get_contents($root.'/'.$inventory->head.'/inventory.json') !== $bytes
            && $errors[] = "$id: the head version's inventory is not the object's";
        foreach ($inventory->manifest as $digest => $contentPaths) {
            foreach ((array) $contentPaths as $content) {
                if (! is_file($root.'/'.$content)) {
                    $errors[] = "$id: missing $content";
                } elseif (hash_file($inventory->digestAlgorithm, $root.'/'.$content) !== $digest) {
                    $errors[] = "$id: $content does not match its digest";
                }
            }
        }

        return $errors;
    }

    /** Every object's id, from the inventories at object roots (three tuples deep, then the digest). @return list<string> */
    public function ids(): array
    {
        $ids = [];
        foreach (glob($this->root.'/???/???/???/*/inventory.json') ?: [] as $file) {
            try {
                $ids[] = Inventory::fromJson((string) file_get_contents($file))->id;
            } catch (\Throwable) {
                // verify() names an unreadable inventory; the list goes on without it.
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    private static function clean(string $logical): string
    {
        $logical = ltrim(str_replace('\\', '/', $logical), '/');
        ($logical !== '' && ! preg_match('#(^|/)\.\.?(/|$)#', $logical)) || throw new InvalidArgumentException("OCFL: '$logical' is not a logical path");

        return $logical;
    }
}
