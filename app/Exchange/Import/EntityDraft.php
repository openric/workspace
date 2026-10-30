<?php

// OpenRiC Workspace - one authority record, repository, activity, subject or place as an import file gives it, before it is matched or written.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Exchange\Import;

final class EntityDraft
{
    /** Where the entry is in the file, for people: "row 4". */
    public string $location = '';

    /** What identifies the entry in its source: an AtoM legacyId or term id. */
    public string $key = '';

    /** The entry above it in the same file: a broader subject, the place a place lies in. */
    public ?string $parentKey = null;

    /** The kind of entity as the file says, such as an AtoM type of entity; null when it does not say. */
    public ?string $type = null;

    /** @var array<string, array<string, string>> language => element notation => text; name elements hold one name per line */
    public array $values = [];

    /** The local identifier (ISDIAH 5.1.1, ISAAR(CPF) 5.1.6). */
    public ?string $identifier = null;

    public ?string $descriptionIdentifier = null;

    public ?string $status = null;

    public ?string $detail = null;

    /** @var array<string, string> contact point field => value (ContactPoint::FIELDS) */
    public array $contact = [];

    /** @var list<array{name: string, note: ?string}> occupations, as access points */
    public array $occupations = [];

    /** @var list<string> places, as access points */
    public array $places = [];

    /** The element that holds the authorised name. */
    public string $nameElement = '';

    /** The entity's persistent IRI, when the file gives it (an EAC-CPF or EAC-F otherRecordId): matched before anything else. */
    public ?string $iri = null;

    /** @var list<array{type: string, value: string}> other identifiers: identifier-type notation and value */
    public array $identifiers = [];

    /** @var list<array{property: string, normalized: ?string, expressed: ?string}> dates (date-property notation) */
    public array $dates = [];

    /**
     * Relations to entities the file names by IRI: the relation type's IRI or notation, the other end's IRI and label,
     * which end this entity is (source or target; null when the file does not say), dates (EDTF) and a note.
     *
     * @var list<array{type: string, iri: ?string, label: string, end: ?string, from: ?string, to: ?string, description: ?string}>
     */
    public array $relations = [];

    /** The activity type's IRI, for an activity. */
    public ?string $typeIri = null;

    public function value(string $language, string $notation, ?string $text): void
    {
        $text = trim((string) $text);
        if ($text !== '') {
            $this->values[$language][$notation] = $text;
        }
    }

    /** The authorised name, in the first language the file gives one. */
    public function title(): ?string
    {
        foreach ($this->values as $values) {
            if (isset($values[$this->nameElement])) {
                return $values[$this->nameElement];
            }
        }

        return null;
    }

    public function language(string $default): string
    {
        return array_key_first($this->values) ?? $default;
    }
}
