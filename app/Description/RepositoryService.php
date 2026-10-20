<?php

// OpenRiC Workspace - create repositories and edit their description.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The field set and the one rule Heratio's ahg-repository-manage enforces (an
// authorised name is required) were read from it; the code is the Workspace's.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RepositoryService
{
    public function __construct(private DescriptionService $descriptions) {}

    public function create(Institution $institution, string $name, string $language, ?string $identifier = null, ?User $actor = null): Repository
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $name, $language, $identifier) {
            $id = (string) Str::uuid7();
            // The id is chosen here because the IRI contains it; id is not fillable,
            // so it is set directly (create() would quietly mint a different one).
            $repository = new Repository([
                'institution_id' => $institution->id,
                'iri' => $institution->base_iri.'/'.Repository::IRI_SEGMENT.'/'.$id,
                'identifier' => $identifier === '' ? null : $identifier,
            ]);
            $repository->id = $id;
            $repository->save();
            $this->describe($repository, ['authorised-name' => $name], $language);

            return $repository;
        }));
    }

    public function updateIdentity(Repository $repository, ?string $identifier, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $repository->update(['identifier' => $identifier === '' ? null : $identifier])));
    }

    /** ISDIAH 5.6.1, 5.6.4 and 5.6.5; status and detail are concept IRIs or null. */
    public function updateControl(Repository $repository, ?string $descriptionIdentifier, ?string $statusIri, ?string $detailIri, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $repository->update([
            'description_identifier' => $descriptionIdentifier === '' ? null : $descriptionIdentifier,
            'description_status_id' => $statusIri ? $this->concept($statusIri)->id : null,
            'description_detail_id' => $detailIri ? $this->concept($detailIri)->id : null,
        ])));
    }

    /**
     * Set the values of ISDIAH elements in one language; the authorised form of
     * name (5.1.2) must keep a value. The rules are DescriptionService's.
     *
     * @param  array<string, ?string>  $values  element notation => text
     */
    public function describe(Repository $repository, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($repository, Elements::ISDIAH, $values, $language, 'authorised-name', $actor);
    }

    private function concept(string $iri): Concept
    {
        return $this->descriptions->concept($iri);
    }
}
