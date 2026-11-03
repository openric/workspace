<?php

// OpenRiC Workspace - what an export leaves out of a unit of description (roadmap phase 23b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The RiC standards gap assessment (scenario S5) and the design review (section 7.3) of 1 and 2 November 2026 ask
// that an exchange say what it could not carry. Each format carries part of a description, and what part is in its
// exporter. Rather than a second list of each format's reach, which would drift from the exporters, this reads the
// export itself: every value, name, identifier, date and relation of the unit that the export's reader may see is
// looked for in what was exported, and what is not there is listed. A short text may be found by chance elsewhere in
// the output, so the list can miss something, never invent it.

namespace App\Exchange;

use App\CustomFields\CustomFields;
use App\Models\RecordResource;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Relations\RelationTypes;

class ExportLoss
{
    public function __construct(private RelationService $relations) {}

    /** @return list<array{kind: string, what: string, value: string}> what of the unit the output does not hold */
    public function of(string $output, RecordResource $unit, ExportView $view): array
    {
        $haystack = $this->plain($output);
        $missing = [];
        $absent = fn (?string $text) => $text !== null && trim($text) !== '' && ! str_contains($haystack, $this->plain($text));
        $unit->loadMissing('descriptionValues.element.labels', 'names.nameType.labels', 'identifiers.identifierType', 'dates.property.labels');

        foreach ($unit->descriptionValues->pluck('element.notation')->unique()->filter() as $notation) {
            foreach ($view->values($unit, (string) $notation) as $value) {
                if ($absent($value->value)) {
                    $missing[] = ['kind' => 'value', 'what' => (string) ($value->element?->anyLabel(['en']) ?? $notation)." ($value->language)", 'value' => $value->value];
                }
            }
        }
        foreach ($unit->names->filter(fn ($n) => $view->visible($n)) as $name) {
            if ($absent($name->value)) {
                $missing[] = ['kind' => 'name', 'what' => (string) $name->nameType?->anyLabel(['en'])." ($name->language)", 'value' => $name->value];
            }
        }
        foreach ($unit->identifiers as $identifier) {
            if ($absent($identifier->value)) {
                $missing[] = ['kind' => 'identifier', 'what' => (string) ($identifier->identifierType?->notation ?? 'identifier'), 'value' => $identifier->value];
            }
        }
        foreach ($unit->dates as $date) {
            if ($absent($date->expressed) && $absent($date->normalized_value)) {
                $missing[] = ['kind' => 'date', 'what' => (string) $date->property?->anyLabel(['en']), 'value' => (string) ($date->expressed ?? $date->normalized_value)];
            }
        }
        // The institution's own fields (phase 22b): RiC-O has no term for them; the CSV carries those marked for export.
        foreach (app(CustomFields::class)->values($unit)->flatten() as $value) {
            if ($value->definition->is_active && ($view->user !== null || $value->definition->is_visible_public) && $absent($text = CustomFields::text($value))) {
                $missing[] = ['kind' => 'own field', 'what' => $value->definition->label, 'value' => $text];
            }
        }
        foreach ($this->relations->of($unit) as $relation) {
            if (! in_array($relation->standing(), ['accepted', 'disputed'], true) || ! $view->readable($relation->other)) {
                continue;
            }
            $other = (string) ($view->label($relation->other) ?? EntityKinds::label($relation->other));
            if ($absent($other) && $absent($relation->other->getAttribute('iri'))) {
                $type = RelationTypes::of($relation);
                $missing[] = ['kind' => 'relation', 'what' => (string) ($relation->fromSource ? $type['forward'] ?? $relation->kind() : $type['inverse'] ?? $relation->kind()), 'value' => $other];
            }
        }

        // Its place in the file plan travels only as the function or activity it documents (roadmap phase 13c).
        if (($node = $unit->filePlanPlacement?->node) && $absent($node->code)) {
            $missing[] = ['kind' => 'file plan', 'what' => 'Place in the file plan', 'value' => $node->code.' '.$node->title];
        }

        return $missing;
    }

    /** Text as a reader sees it: entities and JSON escapes undone, white space made single, case ignored. */
    private function plain(string $text): string
    {
        // Markup between paragraphs (EAD's <p>) is not part of the text.
        $text = (string) preg_replace('/<[^>]+>/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');
        $text = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $text);
        $text = str_replace(['\\/', '\\n', '\\"'], ['/', ' ', '"'], $text);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
