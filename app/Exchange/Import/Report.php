<?php

// OpenRiC Workspace - what an import did or would do, unit by unit, and every field it could not take as given (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Exchange\Import;

final class Report
{
    /** Kinds of issue, for people. */
    public const KINDS = [
        'rejected' => 'Not imported', 'left-out' => 'Left out', 'ambiguous' => 'Ambiguous, not linked', 'created' => 'Created', 'note' => 'Note',
    ];

    /** @var list<array{location: string, key: string, title: ?string, action: string, unit_id: ?string, message: ?string}> */
    public array $units = [];

    /** @var list<array{location: string, field: string, value: ?string, problem: string, kind: string}> */
    public array $issues = [];

    /** @var array<string, int> */
    public array $counts = ['relations' => 0, 'agents' => 0, 'places' => 0, 'concepts' => 0, 'values' => 0, 'files' => 0, 'containers' => 0];

    /** @var array<string, bool> issues reported once per file, such as a column the import does not read */
    private array $once = [];

    public function issue(string $location, string $field, ?string $value, string $problem, string $kind): void
    {
        $this->issues[] = ['location' => $location, 'field' => $field, 'value' => $value === null ? null : mb_substr($value, 0, 300), 'problem' => $problem, 'kind' => $kind];
    }

    public function once(string $field, string $problem, string $kind = 'left-out'): void
    {
        if (! isset($this->once[$field.$problem])) {
            $this->once[$field.$problem] = true;
            $this->issue('whole file', $field, null, $problem, $kind);
        }
    }

    /** What became of an entry of the file: a unit of description, an authority record, a repository, a subject or a place. */
    public function unit(UnitDraft|EntityDraft $draft, string $action, ?string $unitId, ?string $message = null): void
    {
        $this->units[] = ['location' => $draft->location, 'key' => $draft->key, 'title' => $draft->title(), 'action' => $action, 'unit_id' => $unitId, 'message' => $message,
            'table' => $draft instanceof UnitDraft ? 'record_resources' : $draft->table];
    }

    /** @return array<string, int> units by what became of them */
    public function actions(): array
    {
        $actions = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0];
        foreach ($this->units as $unit) {
            $actions[$unit['action']]++;
        }

        return $actions;
    }

    public function toArray(): array
    {
        return ['units' => $this->units, 'issues' => $this->issues, 'counts' => $this->counts, 'actions' => $this->actions()];
    }
}
