<?php

// OpenRiC Workspace - what one vocabulary import changed.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Vocabulary;

use App\Models\Vocabulary;

class ImportResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    /** IRIs of concepts already stored that the source no longer lists. */
    public array $missing = [];

    public function __construct(public readonly Vocabulary $vocabulary) {}

    public function count(string $outcome): void
    {
        $this->{$outcome}++;
    }

    public function summary(): string
    {
        return sprintf(
            '%s: %d created, %d updated, %d unchanged%s',
            $this->vocabulary->iri,
            $this->created,
            $this->updated,
            $this->unchanged,
            $this->missing ? ', '.count($this->missing).' no longer in the source (kept)' : '',
        );
    }
}
