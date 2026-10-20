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
use App\Models\DescriptionValue;
use App\Models\Institution;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RepositoryService
{
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
     * Set the values of elements in one language. An empty value removes it;
     * a changed value goes back to draft. The first language an element is
     * written in is its original; later ones are translations.
     *
     * @param  array<string, ?string>  $values  element notation => text
     */
    public function describe(Repository $repository, array $values, string $language, ?User $actor = null): void
    {
        if (! Bcp47::valid($language)) {
            throw new InvalidArgumentException("$language is not a BCP 47 language tag");
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($repository, $values, $language) {
            $draft = $this->concept(Vocab::valueState('draft'));
            foreach ($values as $notation => $text) {
                $element = $this->concept(Vocab::element($notation));
                $text = trim((string) $text);
                $where = ['subject_table' => $repository->getTable(), 'subject_id' => $repository->id, 'element_id' => $element->id];
                $existing = DescriptionValue::where($where)->where('language', $language)->first();
                if ($text === '') {
                    $existing?->delete();

                    continue;
                }
                if ($existing === null) {
                    DescriptionValue::create([...$where, 'institution_id' => $repository->institution_id, 'language' => $language,
                        'value' => $text, 'is_original' => ! DescriptionValue::where($where)->exists(), 'state_id' => $draft->id]);
                } elseif ($existing->value !== $text) {
                    $existing->update(['value' => $text, 'state_id' => $draft->id]);
                }
            }
            // ISDIAH 5.1.2 is mandatory: at least one language must keep an authorised name.
            $name = $this->concept(Vocab::element('authorised-name'));
            if (! DescriptionValue::where(['subject_table' => $repository->getTable(), 'subject_id' => $repository->id, 'element_id' => $name->id])->exists()) {
                throw new InvalidArgumentException('a repository needs an authorised form of name (ISDIAH 5.1.2)');
            }
            $repository->unsetRelation('descriptionValues');
        }));
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
