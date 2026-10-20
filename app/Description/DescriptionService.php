<?php

// OpenRiC Workspace - write the language values of any described entity.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// One set of rules for every kind of entity (logical model, section 5): an
// empty value removes it (audited with its text); a changed value goes back
// to draft; the first language an element is written in is its original,
// later ones are translations; a required element keeps at least one value.
// Name elements (config workspace.name_elements) are written as rico:Name
// entities by NameService, under the same rules.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\DescriptionValue;
use App\Models\Name;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DescriptionService
{
    public function __construct(private NameService $names) {}

    /**
     * @param  string  $standard  Elements::ISDIAH or Elements::ISAD_G
     * @param  array<string, ?string>  $values  element notation => text
     * @param  string  $required  the element that must keep a value in some language
     */
    public function describe(Model $subject, string $standard, array $values, string $language, string $required, ?User $actor = null): void
    {
        if (! Bcp47::valid($language)) {
            throw new InvalidArgumentException("$language is not a BCP 47 language tag");
        }
        $unknown = array_diff(array_keys($values), Elements::notations($standard));
        if ($unknown !== []) {
            throw new InvalidArgumentException("not $standard elements: ".implode(', ', $unknown));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($subject, $values, $language, $required) {
            $draft = $this->concept(Vocab::valueState('draft'));
            foreach ($values as $notation => $text) {
                if ($type = Elements::nameType($notation)) {
                    $this->names->writeElement($subject, $type, $language, (string) $text);

                    continue;
                }
                $element = $this->concept(Vocab::element($notation));
                $text = trim((string) $text);
                $where = ['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey(), 'element_id' => $element->id];
                $existing = DescriptionValue::where($where)->where('language', $language)->first();
                if ($text === '') {
                    $existing?->delete();

                    continue;
                }
                if ($existing === null) {
                    DescriptionValue::create([...$where, 'institution_id' => $subject->getAttribute('institution_id'), 'language' => $language,
                        'value' => $text, 'is_original' => ! DescriptionValue::where($where)->exists(), 'state_id' => $draft->id]);
                } elseif ($existing->value !== $text) {
                    $existing->update(['value' => $text, 'state_id' => $draft->id]);
                }
            }
            $requiredElement = $this->concept(Vocab::element($required));
            $where = ['subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()];
            $kept = ($type = Elements::nameType($required))
                ? Name::where([...$where, 'name_type_id' => $this->concept(Vocab::NAME_TYPES.$type)->id])->exists()
                : DescriptionValue::where([...$where, 'element_id' => $requiredElement->id])->exists();
            if (! $kept) {
                $label = $requiredElement->labels()->where('language', 'en')->value('pref_label') ?? $required;
                throw new InvalidArgumentException("a description needs a value for $label");
            }
            $subject->unsetRelation('descriptionValues');
            $subject->unsetRelation('names');
        }));
    }

    public function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
