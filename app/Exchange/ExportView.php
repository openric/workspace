<?php

// OpenRiC Workspace - what an export of units of description may say, in which language.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Every export format reads units through this, so all of them apply the
// same rules (SPECIFICATION.md, default deny): only published values and
// names, unless the account may see drafts and asked for them; only accepted
// or disputed relations; only entities at the other end the account may
// read, and of those only a published name. A value is given in the language
// asked for, or else in the language the unit was first described in.

namespace App\Exchange;

use App\Access\AccessControl;
use App\Description\Elements;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\RicDate;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ExportView
{
    /** @var array<string, Collection<int, Relation>> relations by unit id */
    private array $relations = [];

    /** With no account, it reads as the public of $institution reads (ADR 0005: the Public role), and never sees drafts. */
    public function __construct(public readonly ?User $user, public readonly bool $drafts, public readonly ?string $language = null, private ?Institution $institution = null)
    {
        if ($user === null && $drafts) {
            throw new \InvalidArgumentException('the public never sees drafts');
        }
    }

    /** May the account, or the public, take the action on the entity? */
    public function may(string $action, string $kind, ?Model $entity = null): bool
    {
        if ($this->user !== null) {
            return $this->user->may($action, $kind, $entity);
        }
        $this->institution ??= Institution::find($entity?->getAttribute('institution_id'));

        return $this->institution !== null && app(AccessControl::class)->allows(null, $action, $kind, $entity, $this->institution);
    }

    /** The language the unit is exported in. */
    public function languageOf(Model $unit): string
    {
        return $this->language ?? $unit->originalLanguage('title');
    }

    /**
     * The element's value and its language: the export language, else the unit's original language.
     *
     * @return array{0: string, 1: string}|null
     */
    public function valueWithLanguage(Model $entity, string $notation, ?string $language = null): ?array
    {
        $element = Elements::nameType($notation) ? null : Vocab::element($notation);
        $values = $element
            ? $entity->descriptionValues->filter(fn ($v) => $v->element->iri === $element)
            : $entity->namesOfType(Elements::nameType($notation));
        $values = $values->filter(fn ($v) => $this->visible($v));
        $language ??= $this->languageOf($entity);
        $chosen = $values->first(fn ($v) => $v->language === $language) ?? $values->first(fn ($v) => $v->is_original) ?? $values->first();

        return $chosen ? [$chosen->value, $chosen->language] : null;
    }

    public function value(Model $entity, string $notation, ?string $language = null): ?string
    {
        return $this->valueWithLanguage($entity, $notation, $language)[0] ?? null;
    }

    /** The element's value in exactly this language, with no fallback: a translation row. */
    public function valueIn(Model $entity, string $notation, string $language): ?string
    {
        $found = $this->valueWithLanguage($entity, $notation, $language);

        return $found && $found[1] === $language ? $found[0] : null;
    }

    /** Every visible value of one element of the unit, by language, the original first. */
    public function values(Model $entity, string $notation): Collection
    {
        $element = Vocab::element($notation);

        return $entity->descriptionValues->filter(fn ($v) => $v->element->iri === $element && $this->visible($v))
            ->sortBy(fn ($v) => [! $v->is_original, $v->language])->values();
    }

    /** Every language the unit has a visible value or name in, its original first. */
    public function languages(Model $entity): array
    {
        $original = $entity->originalLanguage('title');

        return $entity->descriptionValues->filter(fn ($v) => $this->visible($v))->pluck('language')
            ->merge($entity->names->filter(fn ($n) => $this->visible($n))->pluck('language'))
            ->unique()->sortBy(fn ($l) => [$l !== $original, $l])->values()->all();
    }

    public function visible(Model $value): bool
    {
        return $this->drafts || $value->state?->iri === Vocab::valueState('published');
    }

    /** Relations of the unit that stand (accepted or disputed) and whose other end the account may read, by type notation. */
    public function relations(RecordResource $unit, ?string $type = null): Collection
    {
        $this->relations[$unit->id] ??= app(RelationService::class)->of($unit)
            ->filter(fn (Relation $r) => in_array($r->standing(), ['accepted', 'disputed'], true) && $this->readable($r->other))->values();

        return $type === null ? $this->relations[$unit->id] : $this->relations[$unit->id]->filter(fn (Relation $r) => $r->relationType->notation === $type)->values();
    }

    /** Creators and accumulators (ISAD(G) 3.2.1): agents the unit was created or accumulated by. */
    public function creators(RecordResource $unit): Collection
    {
        return $this->relations($unit)->filter(fn (Relation $r) => in_array($r->relationType->notation, ['creation', 'accumulation'], true)
            && in_array(EntityKinds::of($r->other), ['agent', 'repository'], true) && $this->label($r->other) !== null)->values();
    }

    /** Access points: subjects (concepts), places (place relations and places as subjects) and names (agents as subjects). */
    public function accessPoints(RecordResource $unit): array
    {
        $subjects = $this->relations($unit, 'subject');

        return [
            'subjects' => $subjects->filter(fn (Relation $r) => $r->other instanceof Concept)->values(),
            'places' => $subjects->filter(fn (Relation $r) => $r->other instanceof Place)->merge($this->relations($unit, 'place'))->values(),
            'names' => $subjects->filter(fn (Relation $r) => $r->other instanceof Agent || EntityKinds::of($r->other) === 'repository')->values(),
        ];
    }

    /** The entity's name as the export may give it: a concept's label, or a visible authorised name. */
    public function label(Model $entity, ?string $language = null): ?string
    {
        if ($entity instanceof Concept) {
            return $entity->anyLabel([...array_filter([$language ?? $this->language]), 'en', 'und']);
        }
        $notation = EntityKinds::get(EntityKinds::of($entity))['label'];

        return $this->value($entity, $notation, $language ?? $this->language ?? $entity->originalLanguage($notation));
    }

    public function readable(Model $entity): bool
    {
        if ($entity->getAttribute('deleted_at') !== null || ($this->institution && $entity->getAttribute('institution_id') !== $this->institution->id)) {
            return false;
        }
        if ($this->user !== null) {
            return EntityKinds::readable($this->user, $entity);
        }
        // The public: an embargoed authority record is never read, and an entity is read only once its name is published.
        $definition = EntityKinds::get(EntityKinds::of($entity));
        if (($entity instanceof Agent && $entity->isEmbargoed()) || ($definition['label'] !== null && $this->label($entity) === null)) {
            return false;
        }

        return ! $definition['readable'] || $this->may('read', $definition['permission'], $entity);
    }

    /** The EDTF bounds of a date as ISO 8601, a year when it covers whole years, for EAD's normal and the like. */
    public static function isoInterval(RicDate $date): ?string
    {
        if (! $date->earliest || ! $date->latest) {
            return null;
        }
        $from = $date->earliest->format('m-d') === '01-01' ? $date->earliest->format('Y') : $date->earliest->format('Y-m-d');
        $to = $date->latest->format('m-d') === '12-31' ? $date->latest->format('Y') : $date->latest->format('Y-m-d');

        return $from === $to ? $from : "$from/$to";
    }
}
