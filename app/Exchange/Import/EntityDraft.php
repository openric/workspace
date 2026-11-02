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
     * From RiC-O (roadmap phase 23b) the type is the relation types its property may be ($types, the import choosing the one
     * whose pair fits), with the relation's certainty, evidence (units by IRI) and agent role when the file gives them.
     *
     * @var list<array{type?: string, types?: list<string>, iri: ?string, label: string, end: ?string, from: ?string, to: ?string, description: ?string, certainty?: ?string, evidence?: list<string>, role?: ?string}>
     */
    public array $relations = [];

    /** The dates its names were used (rico:usedFromDate, rico:usedToDate), by the name's text and language. */
    public array $nameDates = [];

    /** A rule as an authority elsewhere publishes it (owl:sameAs). */
    public ?string $authorityIri = null;

    /** A place's latitude and longitude, as written. */
    public array $coordinates = [];

    /** The place a place lies in, by IRI (rico:isOrWasPartOf). */
    public ?string $parentIri = null;

    /** The activity type's IRI, for an activity; the rule type's, for a rule. */
    public ?string $typeIri = null;

    /** The table the entry becomes, when a format holds more than one kind (RiC-O): agents or repositories. */
    public ?string $table = null;

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
