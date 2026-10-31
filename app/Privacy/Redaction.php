<?php

// OpenRiC Workspace - the fields of a unit of description redacted for the public (roadmap phase 18b, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Copied from Heratio's PrivacyRedactionService: the placeholders, the four patterns and the pseudonym (Subject- and
// six characters of a SHA-256). Applied where every public surface reads a unit's values (ExportView), so the
// catalogue, the API, JSON-LD, OAI-PMH, IIIF and the public search index all say the same. Who sees a value
// unredacted: an account that may change the record (it sees the raw value on its form) or manage privacy. Heratio
// lets administrators only.

namespace App\Privacy;

use App\Models\Concept;
use App\Models\RecordRedaction;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Redaction
{
    public const FULL_PLACEHOLDER = '[REDACTED - personal data removed]';

    public const PARTIAL_PLACEHOLDER = '[PARTIALLY REDACTED]';

    public const TYPES = Vocab::BASE.'redaction-type/';

    public const PATTERNS = Vocab::BASE.'redaction-pattern/';

    /** A record's own names, by the element notation they are described under. */
    public const NAME_FIELDS = ['authorised' => 'title', 'parallel' => 'parallel-name', 'other' => 'alternate-title'];

    /** @var array<string, Collection<string, RecordRedaction>> by record id, keyed by field */
    private array $fields = [];

    /** @return Collection<string, RecordRedaction> */
    public function of(RecordResource $unit): Collection
    {
        return $this->fields[$unit->id] ??= RecordRedaction::where('record_resource_id', $unit->id)->with('type', 'pattern')->get()->keyBy('field');
    }

    public function seesUnredacted(?User $user, RecordResource $unit): bool
    {
        return $user !== null && $user->institution_id === $unit->institution_id
            && ($user->may('update', 'description', $unit) || $user->hasPermission(Vocab::permission('manage-privacy')));
    }

    /** The value of the field as the account, or with none the public, may read it. */
    public function shown(?User $user, RecordResource $unit, string $field, string $value): string
    {
        $redaction = $this->of($unit)->get($field);
        if ($redaction === null || $value === '' || $this->seesUnredacted($user, $unit)) {
            return $value;
        }

        return $this->redactValue($value, $redaction->type->notation, $redaction->pattern?->notation);
    }

    public function redactValue(string $value, string $type, ?string $pattern = null): string
    {
        return match ($type) {
            'partial' => $pattern ? $this->applyPattern($value, $pattern) : self::PARTIAL_PLACEHOLDER,
            'pseudonymised' => $this->pseudonymise($value),
            default => self::FULL_PLACEHOLDER,
        };
    }

    public function applyPattern(string $value, string $pattern): string
    {
        return match ($pattern) {
            'email-partial' => preg_replace('/^(.).*@.*$/s', '$1***@***', $value) ?: self::PARTIAL_PLACEHOLDER,
            'phone-partial' => preg_replace('/\d(?=\d{4})/', '*', $value) ?: self::PARTIAL_PLACEHOLDER,
            'id-last4' => mb_strlen($value) > 4 ? str_repeat('*', mb_strlen($value) - 4).mb_substr($value, -4) : str_repeat('*', mb_strlen($value)),
            'year-only' => preg_match('/(\d{4})/', $value, $m) ? $m[1] : self::PARTIAL_PLACEHOLDER,
            default => self::PARTIAL_PLACEHOLDER,
        };
    }

    /** Stable and not reversible, such as Subject-4f9a2c. */
    public function pseudonymise(string $value): string
    {
        return 'Subject-'.substr(hash('sha256', $value), 0, 6);
    }

    /** Redact a field, or change how it is redacted. */
    public function set(RecordResource $unit, array $data, User $actor): RecordRedaction
    {
        $field = (string) ($data['field'] ?? '');
        if (! in_array($field, $this->fieldsOf($unit), true)) {
            throw new InvalidArgumentException(__('Choose a field the description has.'));
        }
        $type = Concept::where('iri', self::TYPES.($data['type'] ?? ''))->first() ?? throw new InvalidArgumentException(__('Choose how the field is redacted.'));
        $pattern = ($data['pattern'] ?? '') !== '' ? (Concept::where('iri', self::PATTERNS.$data['pattern'])->value('id') ?? throw new InvalidArgumentException(__('Choose a pattern from the list.'))) : null;
        if ($type->notation === 'partial' && $pattern === null) {
            throw new InvalidArgumentException(__('A partial redaction needs a pattern.'));
        }
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the reason for the redaction.'));
        }
        unset($this->fields[$unit->id]);

        return RecordRedaction::updateOrCreate(['record_resource_id' => $unit->id, 'field' => $field], ['institution_id' => $unit->institution_id, 'type_id' => $type->id,
            'pattern_id' => $type->notation === 'partial' ? $pattern : null, 'reason' => trim($data['reason']),
            'legal_basis' => trim((string) ($data['legal_basis'] ?? '')) ?: null, 'is_sensitive' => (bool) ($data['is_sensitive'] ?? false), 'created_by_id' => $actor->id]);
    }

    public function remove(RecordRedaction $redaction): void
    {
        unset($this->fields[$redaction->record_resource_id]);
        $redaction->delete();
    }

    /**
     * The fields of the unit that have a value, by element notation: its names and its description values.
     *
     * @return list<string>
     */
    public function fieldsOf(RecordResource $unit): array
    {
        return $unit->names()->with('nameType')->get()->map(fn ($n) => self::NAME_FIELDS[$n->nameType->notation] ?? null)
            ->merge($unit->descriptionValues()->with('element')->get()->map(fn ($v) => $v->element->notation))
            ->filter()->unique()->values()->all();
    }
}
