<?php

// OpenRiC Workspace - add, change and remove the identifiers of any entity (rico:Identifier; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An entity has at most one local identifier, which the identity part of its
// description form sets; any number of others (ISIL, previous, other). A
// repository's identifiers are unique in its institution. The database
// enforces both; this turns its refusal into words.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class IdentifierService
{
    /** @param  array{type: string, value: string}  $fields */
    public function add(Model $subject, array $fields, ?User $actor = null): Identifier
    {
        $type = $this->type($fields['type'] ?? '');
        $value = $this->value($fields['value'] ?? '');

        return $this->guarded(fn () => AuditLog::actingAs($actor, fn () => $this->create($subject, $type, $value)));
    }

    /** @param  array{type: string, value: string}  $fields */
    public function update(Identifier $identifier, array $fields, ?User $actor = null): void
    {
        $type = $this->type($fields['type'] ?? '');
        $value = $this->value($fields['value'] ?? '');
        $this->guarded(fn () => AuditLog::actingAs($actor, fn () => $identifier->update(['identifier_type_id' => $type->id, 'value' => $value])));
    }

    public function remove(Identifier $identifier, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $identifier->delete()));
    }

    /** Set, change or (with null or '') remove the local identifier. */
    public function setLocal(Model $subject, ?string $value, ?User $actor = null): void
    {
        $value = trim((string) $value);
        $type = $this->type('local');
        $this->guarded(fn () => AuditLog::actingAs($actor, function () use ($subject, $value, $type) {
            $current = Identifier::where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'identifier_type_id' => $type->id])->first();
            if ($value === '') {
                $current?->delete();
            } elseif ($current === null) {
                $this->create($subject, $type, $this->value($value));
            } elseif ($current->value !== $value) {
                $current->update(['value' => $this->value($value)]);
            }
        }));
        $subject->unsetRelation('identifiers');
    }

    private function create(Model $subject, Concept $type, string $value): Identifier
    {
        $institution = Institution::findOrFail($subject->getAttribute('institution_id'));
        $id = (string) Str::uuid7();
        $identifier = new Identifier(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Identifier::IRI_SEGMENT.'/'.$id,
            'subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'identifier_type_id' => $type->id, 'value' => $value]);
        $identifier->id = $id;
        $identifier->save();

        return $identifier;
    }

    private function guarded(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('that identifier is already used: a repository identifier must be unique in the institution, and an entity has one local identifier');
        }
    }

    private function type(string $notation): Concept
    {
        return Concept::where('iri', Vocab::IDENTIFIER_TYPES.$notation)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$notation is not a current identifier type");
    }

    private function value(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\R/', $value) || mb_strlen($value) > 255) {
            throw new InvalidArgumentException('an identifier is one line of at most 255 characters');
        }

        return $value;
    }
}
