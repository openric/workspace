<?php

// OpenRiC Workspace - one unit of description as an import file gives it, before it is matched or written.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Exchange\Import;

final class UnitDraft
{
    /** Where the unit is in the file, for people: "row 4", "c Minutes". */
    public string $location = '';

    /** What identifies the unit in its source: an AtoM legacyId, a persistent IRI, a component's id or path. */
    public string $key = '';

    /** Whether the key stays the same when the file is read again. */
    public bool $stableKey = true;

    public ?string $parentKey = null;

    /** The persistent IRI of the unit above, when the file names one already in the Workspace. */
    public ?string $parentIri = null;

    /** The unit's own persistent IRI, when the file was exported from the Workspace. */
    public ?string $iri = null;

    public ?string $identifier = null;

    /** The level as the file writes it: an EAD level, an AtoM term, a notation or a label. */
    public ?string $level = null;

    /** @var array<string, array<string, string>> language => ISAD(G) element notation (title included) => text */
    public array $values = [];

    /** @var list<array{property: string, normalized: ?string, expressed: ?string, language: string}> */
    public array $dates = [];

    /** @var list<array{name: string, type: ?string, iri: ?string, relation: string, history: ?string}> agent type notation or null when the file does not say */
    public array $creators = [];

    /** @var list<array{name: string, iri: ?string}> */
    public array $subjects = [];

    /** @var list<array{name: string, iri: ?string}> */
    public array $places = [];

    /** @var list<array{name: string, iri: ?string}> documentary form types (genre access points) */
    public array $genres = [];

    /** @var list<array{name: string, type: ?string, iri: ?string}> agents as subjects */
    public array $names = [];

    /** @var list<string> persistent IRIs of associated units */
    public array $associated = [];

    /** @var list<array{type: string, value: string}> identifiers other than the local one, by type label or notation */
    public array $identifiers = [];

    public ?string $descriptionIdentifier = null;

    public ?string $status = null;

    public ?string $detail = null;

    public ?string $repositoryName = null;

    /** The persistent IRI of the repository that holds the unit, when the file names it (a RiC-O rico:hasOrHadHolder). */
    public ?string $repositoryIri = null;

    /** @var list<array{name: string, type: ?string, location: ?string}> containers the unit is kept in, as the file names them (AtoM physical objects, EAD container) */
    public array $containers = [];

    /** @var list<array{uri: string, sha256: ?string}> files AtoM kept for the unit, to fetch on import */
    public array $files = [];

    public function value(string $language, string $notation, ?string $text): void
    {
        $text = trim((string) $text);
        if ($text === '') {
            return;
        }
        $current = $this->values[$language][$notation] ?? null;
        $this->values[$language][$notation] = $current === null ? $text : $current."\n\n".$text;
    }

    /** The title, in the first language the file gives one. */
    public function title(): ?string
    {
        foreach ($this->values as $values) {
            if (isset($values['title'])) {
                return $values['title'];
            }
        }

        return null;
    }

    /** The language the unit is first described in: the first one the file gives. */
    public function language(string $default): string
    {
        return array_key_first($this->values) ?? $default;
    }
}
