<?php

// OpenRiC Workspace - read-only GraphQL over what the public may read (roadmap phase 22c), after Heratio's ahg-graphql
// GraphqlController: no library, a fixed schema, paging clamped to 100. Changed: the query is parsed, not matched by
// pattern, so each field asked for is answered and an unknown one is an error, as GraphQL says; and every entity comes
// from the public catalogue, so the same clearance, publication and embargo hold as for the rest of the API (ADR 0007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// ponytail: the subset of GraphQL a catalogue client needs - one query, top-level fields with arguments (literals or
// variables), aliases and scalar selections; no fragments, directives or mutations. A full engine
// (webonyx/graphql-php) is the upgrade if nested selections are ever wanted.

namespace App\Api;

use App\Models\Place;
use App\Models\RecordResource;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class GraphQl
{
    public const MAX_LIMIT = 100;

    /** Root field => [kind, one or many]. */
    private const ROOTS = ['record' => ['records', true], 'records' => ['records', false], 'agent' => ['agents', true], 'agents' => ['agents', false],
        'repository' => ['repositories', true], 'repositories' => ['repositories', false], 'place' => ['places', true], 'places' => ['places', false],
        'activity' => ['activities', true], 'activities' => ['activities', false], 'rule' => ['rules', true], 'rules' => ['rules', false]];

    /** The fields of each kind, beyond id, iri, type, name, identifier, url and api. */
    private const EXTRA = ['records' => ['referenceCode', 'level', 'parent'], 'places' => ['latitude', 'longitude', 'altitude', 'parent']];

    /** One entity of each kind, as its public page and its API address are named. */
    private const PAGE = ['records' => 'record', 'agents' => 'agent', 'repositories' => 'repository', 'places' => 'place', 'activities' => 'activity', 'rules' => 'rule'];

    private string $query;

    private int $at = 0;

    private array $variables = [];

    public function __construct(private PublicCatalogue $catalogue) {}

    /** The GraphQL response: data, or errors and no data. */
    public function execute(string $query, array $variables = []): array
    {
        try {
            mb_strlen($query) <= 10000 || throw new InvalidArgumentException('The query is longer than 10,000 characters.');
            [$this->query, $this->at, $this->variables] = [$query, 0, $variables];
            $data = [];
            foreach ($this->operation() as [$alias, $field, $args, $selection]) {
                $data[$alias] = $this->resolve($field, $args, $selection);
            }

            return ['data' => $data];
        } catch (InvalidArgumentException $e) {
            return ['errors' => [['message' => $e->getMessage()]]];
        }
    }

    /** The fields each root field may select, for clients that ask (__schema). */
    public function schema(): array
    {
        $types = [];
        foreach (array_unique(array_column(self::ROOTS, 0)) as $kind) {
            $types[$kind] = [...['id', 'iri', 'type', 'name', 'identifier', 'url', 'api'], ...self::EXTRA[$kind] ?? []];
        }

        return ['queryType' => array_map(fn ($r) => ['kind' => $r[0], 'arguments' => $r[1] ? ['id'] : ['q', 'limit', 'offset']], self::ROOTS), 'types' => $types, 'mutationType' => null];
    }

    private function resolve(string $field, array $args, array $selection): mixed
    {
        if ($field === '__schema') {
            return $this->schema();
        }
        [$kind, $one] = self::ROOTS[$field] ?? throw new InvalidArgumentException("Cannot query field \"$field\" on type \"Query\". Available: ".implode(', ', array_keys(self::ROOTS)).'.');
        $selection = $selection ?: ['id', 'name'];
        $allowed = [...['id', 'iri', 'type', 'name', 'identifier', 'url', 'api'], ...self::EXTRA[$kind] ?? []];
        foreach ($selection as $name) {
            in_array($name, $allowed, true) || throw new InvalidArgumentException("Cannot query field \"$name\" on \"$field\".");
        }
        if ($one) {
            $id = (string) ($args['id'] ?? throw new InvalidArgumentException("\"$field\" needs an id."));
            $entity = $this->catalogue->find($kind, $id);

            return $entity ? $this->shape($kind, $entity, $selection) : null;
        }
        $limit = (int) ($args['limit'] ?? 20);
        $offset = (int) ($args['offset'] ?? 0);
        ($limit >= 1 && $limit <= self::MAX_LIMIT && $offset >= 0) || throw new InvalidArgumentException('limit is from 1 to '.self::MAX_LIMIT.', and offset 0 or more.');

        // Paged in the database, in name order as before (v0.0.146).
        return $this->catalogue->byName($this->catalogue->query($kind, trim((string) ($args['q'] ?? ''))), $kind)->with(PublicCatalogue::WITH)->offset($offset)->limit($limit)->get()
            ->filter(fn ($e) => $this->catalogue->view->readable($e))->map(fn ($e) => $this->shape($kind, $e, $selection))->values()->all();
    }

    private function shape(string $kind, Model $entity, array $selection): array
    {
        $view = $this->catalogue->view;
        $parent = fn () => ($p = $entity->parent) && $view->readable($p) ? $p->id : null;
        $value = fn (string $name) => match ($name) {
            'id' => $entity->getKey(),
            'iri' => $entity->getAttribute('iri'),
            'type' => (new JsonLd($view))->type($entity),
            'name' => (string) $view->label($entity),
            'identifier' => $entity instanceof RecordResource ? $entity->identifier : $entity->getAttribute('identifier'),
            'url' => route('discover.'.self::PAGE[$kind], $entity->getKey()),
            'api' => route($entity->getAttribute('is_function') ? 'api.function' : 'api.'.self::PAGE[$kind], $entity->getKey()),
            'referenceCode' => $entity->referenceCode(),
            'level' => $entity->level?->notation,
            'parent' => $parent(),
            'latitude', 'longitude', 'altitude' => $entity instanceof Place && $entity->{$name} !== null ? (float) $entity->{$name} : null,
        };
        $out = [];
        foreach ($selection as $alias => $name) {
            $out[is_string($alias) ? $alias : $name] = $value($name);
        }

        return $out;
    }

    /** @return list<array{string, string, array, array}> alias, field, arguments, selection */
    private function operation(): array
    {
        foreach (['mutation', 'subscription'] as $kind) {
            $this->accept($kind) && throw new InvalidArgumentException("This API is read only: no {$kind}s (ADR 0007).");
        }
        if ($this->accept('query')) {
            $this->name(false);
            if ($this->accept('(')) {
                // Variable definitions: the types are not checked, the values come from "variables".
                while (! $this->accept(')')) {
                    $this->at < strlen($this->query) || throw new InvalidArgumentException('Unclosed variable definitions.');
                    $this->at++;
                }
            }
        }
        $this->expect('{');
        $fields = [];
        while (! $this->accept('}')) {
            count($fields) < 10 || throw new InvalidArgumentException('At most 10 fields in one query.');
            [$alias, $field] = $this->aliased();
            $args = $this->accept('(') ? $this->arguments() : [];
            $selection = [];
            if ($this->accept('{')) {
                while (! $this->accept('}')) {
                    [$a, $f] = $this->aliased();
                    $this->peek('{') && throw new InvalidArgumentException("\"$f\" is a scalar; it takes no selection.");
                    $a === $f ? $selection[] = $f : $selection[$a] = $f;
                }
            }
            $fields[] = [$alias, $field, $args, $selection];
        }

        return $fields;
    }

    private function aliased(): array
    {
        $name = $this->name();

        return $this->accept(':') ? [$name, $this->name()] : [$name, $name];
    }

    private function arguments(): array
    {
        $args = [];
        while (! $this->accept(')')) {
            $name = $this->name();
            $this->expect(':');
            $this->skip();
            if ($this->accept('$')) {
                $variable = $this->name();
                $args[$name] = $this->variables[$variable] ?? null;
            } elseif (preg_match('/\G"((?:[^"\\\\]|\\\\.)*)"/A', $this->query, $m, 0, $this->at)) {
                $args[$name] = json_decode('"'.$m[1].'"') ?? $m[1];
                $this->at += strlen($m[0]);
            } elseif (preg_match('/\G-?\d+/A', $this->query, $m, 0, $this->at)) {
                $args[$name] = (int) $m[0];
                $this->at += strlen($m[0]);
            } else {
                throw new InvalidArgumentException("The value of \"$name\" is not a string, a whole number or a variable.");
            }
            $this->accept(',');
        }

        return $args;
    }

    private function name(bool $required = true): ?string
    {
        $this->skip();
        if (preg_match('/\G[_A-Za-z][_0-9A-Za-z]*/A', $this->query, $m, 0, $this->at)) {
            $this->at += strlen($m[0]);

            return $m[0];
        }
        $required && throw new InvalidArgumentException('Expected a name at character '.($this->at + 1).'.');

        return null;
    }

    private function accept(string $token): ?bool
    {
        $this->skip();
        if (substr($this->query, $this->at, strlen($token)) !== $token
            || (ctype_alpha($token) && preg_match('/\G[_0-9A-Za-z]/A', $this->query, $m, 0, $this->at + strlen($token)))) {
            return null;
        }
        $this->at += strlen($token);

        return true;
    }

    private function peek(string $token): bool
    {
        $this->skip();

        return substr($this->query, $this->at, strlen($token)) === $token;
    }

    private function expect(string $token): void
    {
        $this->accept($token) || throw new InvalidArgumentException("Expected \"$token\" at character ".($this->at + 1).'.');
    }

    /** Whitespace, commas and comments are ignored, as GraphQL says. */
    private function skip(): void
    {
        while (preg_match('/\G(?:[\s,]+|#[^\n]*)/A', $this->query, $m, 0, $this->at) && $m[0] !== '') {
            $this->at += strlen($m[0]);
        }
    }
}
