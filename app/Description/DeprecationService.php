<?php

// OpenRiC Workspace - deprecate, don't delete (Governance profile section 4; roadmap phase 11b, ADR 0013).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's RicDeprecationService: a register entry for each entity deleted or merged, so its IRI keeps
// answering, as deprecated, with its successor and the reason. Heratio keeps the entity and marks it; the Workspace
// already keeps the row of a deleted entity and removes it from every view, so the register also keeps what the
// public could see of it at the time: its class and published name. An entity the public never saw stays unknown.

namespace App\Description;

use App\Api\JsonLd;
use App\Api\PublicCatalogue;
use App\Models\Deprecation;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DeprecationService
{
    /** The kinds whose IRIs the public reads (the API's and the public catalogue's); the others have no register entry. */
    public const TABLES = ['record_resources', 'agents', 'repositories', 'activities', 'places'];

    /** Record that an entity is going, before it goes: call it in the same transaction, while the public can still read it. */
    public function record(Model $entity, ?string $reason, ?Model $successor, ?User $actor): ?Deprecation
    {
        if (! in_array($entity->getTable(), self::TABLES, true)) {
            return null;
        }
        $view = (new PublicCatalogue(Institution::findOrFail($entity->getAttribute('institution_id'))))->view;
        $public = $view->readable($entity);
        $type = (new JsonLd($view))->type($entity);

        return Deprecation::updateOrCreate(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()], [
            'institution_id' => $entity->getAttribute('institution_id'), 'iri' => $entity->getAttribute('iri'), 'ric_class' => is_array($type) ? $type[0] : $type,
            'name' => $public ? $view->label($entity) : null, 'was_public' => $public, 'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
            'replaced_by_iri' => $successor?->getAttribute('iri'), 'deprecated_by_id' => $actor?->id, 'deprecated_at' => now(),
        ]);
    }

    /** The register entry the public may know of, for an entity of the institution: one that was public when it went. */
    public function known(Institution $institution, string $table, string $id): ?Deprecation
    {
        return Deprecation::where(['institution_id' => $institution->id, 'subject_table' => $table, 'subject_id' => $id, 'was_public' => true])->first();
    }

    /** The deprecated entity as JSON-LD: its IRI, class and name, owl:deprecated, its successor and the reason. */
    public function jsonLd(Deprecation $deprecation): array
    {
        return array_filter([
            '@context' => [...JsonLd::CONTEXT, 'owl' => 'http://www.w3.org/2002/07/owl#', 'dcterms' => 'http://purl.org/dc/terms/'],
            '@id' => $deprecation->iri,
            '@type' => $deprecation->ric_class,
            $deprecation->subject_table === 'record_resources' ? 'rico:title' : 'rico:name' => $deprecation->name,
            'owl:deprecated' => true,
            'dcterms:isReplacedBy' => $deprecation->replaced_by_iri ? ['@id' => $deprecation->replaced_by_iri] : null,
            'rdfs:comment' => $deprecation->reason,
            'dcterms:modified' => ['@value' => $deprecation->deprecated_at->toAtomString(), '@type' => 'xsd:dateTime'],
        ], fn ($v) => $v !== null);
    }
}
