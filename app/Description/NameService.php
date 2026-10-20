<?php

// OpenRiC Workspace - add, change and remove the names of any entity (rico:Name; ADR 0006).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The rules of description values apply to names too: a new or changed name
// is a draft; the first name of a type is the original, later ones in other
// languages are translations. An entity keeps at least one authorised name
// (its title, for a unit of description), and has at most one in each
// language. The dates a name was used are rico:Date entities of the name.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Name;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NameService
{
    public function __construct(private DateService $dates) {}

    /** @param  array{type: string, value: string, language: string, used_from?: ?string, used_to?: ?string}  $fields */
    public function add(Model $subject, array $fields, ?User $actor = null): Name
    {
        return $this->guarded(fn () => AuditLog::actingAs($actor, function () use ($subject, $fields) {
            $name = $this->create($subject, $this->type($fields['type'] ?? ''), $this->value($fields['value'] ?? ''), $this->language($fields['language'] ?? ''));
            $this->setUsed($name, $fields);

            return $name;
        }));
    }

    /** @param  array{type: string, value: string, language: string, used_from?: ?string, used_to?: ?string}  $fields */
    public function update(Name $name, array $fields, ?User $actor = null): void
    {
        $type = $this->type($fields['type'] ?? '');
        $value = $this->value($fields['value'] ?? '');
        $language = $this->language($fields['language'] ?? '');
        $this->guarded(fn () => AuditLog::actingAs($actor, function () use ($name, $type, $value, $language, $fields) {
            if ($type->id !== $name->name_type_id) {
                $this->keepsAnAuthorisedName($name);
            }
            $name->fill(['name_type_id' => $type->id, 'value' => $value, 'language' => $language]);
            if ($name->isDirty()) {
                $name->state_id = $this->concept(Vocab::valueState('draft'))->id;
                $name->save();
            }
            $this->setUsed($name, $fields);
        }));
    }

    public function remove(Name $name, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($name) {
            $this->keepsAnAuthorisedName($name);
            $this->delete($name);
        }));
    }

    /**
     * What the description form wrote for a name element in one language: an
     * authorised name is one line, and empty removes it; the other types take
     * one name per line, and the names no longer listed are removed. Runs inside
     * DescriptionService's transaction and audit actor; it checks the required
     * authorised name itself.
     */
    public function writeElement(Model $subject, string $type, string $language, string $text): void
    {
        $existing = Name::where(['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'name_type_id' => $this->type($type)->id, 'language' => $language])->get();
        $lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $text)), fn ($l) => $l !== '')));
        if ($type === 'authorised') {
            if (count($lines) > 1) {
                throw new InvalidArgumentException('an authorised form of name or title is one line; put other names under the other forms of name');
            }
            $current = $existing->first();
            if ($lines === []) {
                $current && $this->delete($current);
            } elseif ($current === null) {
                $this->create($subject, $this->type($type), $this->value($lines[0]), $language);
            } elseif ($current->value !== $lines[0]) {
                $current->update(['value' => $this->value($lines[0]), 'state_id' => $this->concept(Vocab::valueState('draft'))->id]);
            }

            return;
        }
        foreach ($existing as $name) {
            if (! in_array($name->value, $lines, true)) {
                $this->delete($name);
            }
        }
        foreach (array_diff($lines, $existing->pluck('value')->all()) as $line) {
            $this->create($subject, $this->type($type), $this->value($line), $language);
        }
    }

    private function create(Model $subject, Concept $type, string $value, string $language): Name
    {
        $institution = Institution::findOrFail($subject->getAttribute('institution_id'));
        $where = ['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'name_type_id' => $type->id];
        $id = (string) Str::uuid7();
        $name = new Name([...$where, 'institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Name::IRI_SEGMENT.'/'.$id,
            'value' => $value, 'language' => $language, 'is_original' => ! Name::where($where)->exists(),
            'state_id' => $this->concept(Vocab::valueState('draft'))->id]);
        $name->id = $id;
        $name->save();

        return $name;
    }

    private function delete(Name $name): void
    {
        foreach ($name->dates()->get() as $date) {
            $date->delete();
        }
        $name->delete();
    }

    /** used_from and used_to are EDTF; an empty one removes that date. */
    private function setUsed(Name $name, array $fields): void
    {
        foreach (['used_from' => 'used-from', 'used_to' => 'used-to'] as $field => $property) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }
            $value = trim((string) $fields[$field]);
            $current = $name->dates()->whereHas('property', fn ($q) => $q->where('iri', 'https://workspace.openric.org/vocab/date-property/'.$property))->first();
            if ($value === '') {
                $current?->delete();
            } elseif ($current === null) {
                $this->dates->add($name, ['property' => $property, 'normalized' => $value]);
            } elseif ($current->normalized_value !== $value) {
                $this->dates->update($current, ['property' => $property, 'normalized' => $value, 'expressed' => $current->expressed, 'language' => $current->expressed_language]);
            }
        }
        $name->unsetRelation('dates');
    }

    private function keepsAnAuthorisedName(Name $name): void
    {
        $authorised = $this->type('authorised')->id;
        if ($name->getOriginal('name_type_id') === $authorised
            && ! Name::where(['subject_table' => $name->subject_table, 'subject_id' => $name->subject_id, 'name_type_id' => $authorised])->whereKeyNot($name->id)->exists()) {
            throw new InvalidArgumentException('this is the only authorised form of name (or title); add another before removing or retyping it');
        }
    }

    /** Run in a transaction; a second authorised name in a language, or a repeated name, is refused in words. */
    private function guarded(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('the entity already has this name, or already has an authorised form of name in that language');
        }
    }

    private function type(string $notation): Concept
    {
        return $this->concept(Vocab::NAME_TYPES.$notation);
    }

    private function value(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\R/', $value) || mb_strlen($value) > 2000) {
            throw new InvalidArgumentException('a name is one line of at most 2000 characters');
        }

        return $value;
    }

    private function language(string $language): string
    {
        return Bcp47::valid($language) ? $language : throw new InvalidArgumentException('give the language of the name as a BCP 47 tag, such as en or af');
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
