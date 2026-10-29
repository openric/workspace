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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RepositoryService
{
    public function __construct(private DescriptionService $descriptions, private IdentifierService $identifiers) {}

    public function create(Institution $institution, string $name, string $language, ?string $identifier = null, ?User $actor = null): Repository
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $name, $language, $identifier) {
            $id = (string) Str::uuid7();
            // The id is chosen here because the IRI contains it; id is not fillable,
            // so it is set directly (create() would quietly mint a different one).
            $repository = new Repository([
                'institution_id' => $institution->id,
                'iri' => $institution->base_iri.'/'.Repository::IRI_SEGMENT.'/'.$id,
            ]);
            $repository->id = $id;
            $repository->save();
            $this->describe($repository, ['authorised-name' => $name], $language);
            $this->identifiers->setLocal($repository, $identifier);

            return $repository;
        }));
    }

    /** ISDIAH 5.1.1: the local identifier, a rico:Identifier unique in the institution. */
    public function updateIdentity(Repository $repository, ?string $identifier, ?User $actor = null): void
    {
        $this->identifiers->setLocal($repository, $identifier, $actor);
    }

    /** Do the repository's holdings carry complete reference codes, so that none is built from the units above it? */
    public function setCompleteReferenceCodes(Repository $repository, bool $complete, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $repository->update(['complete_reference_codes' => $complete])));
    }

    /** ISDIAH 5.6.1, 5.6.4 and 5.6.5; status and detail are concept IRIs or null. */
    /** Set its type (ISDIAH 5.1.5), or none. */
    public function setType(Repository $repository, ?string $typeIri, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $repository->update(['repository_type_id' => $typeIri ? $this->concept($typeIri)->id : null])));
    }

    /**
     * AtoM's edit-theme: the background colour (#rrggbb, or none), the page content (Markdown), and a banner or logo
     * image given, or removed. Images are kept under the local disk, repository-theme/{id}/.
     *
     * @param  array{background_colour?: ?string, content?: ?string, remove_banner?: bool, remove_logo?: bool}  $data
     */
    public function updateTheme(Repository $repository, array $data, ?UploadedFile $banner = null, ?UploadedFile $logo = null, ?User $actor = null): void
    {
        $fields = ['theme_background_colour' => ($c = strtolower(trim((string) ($data['background_colour'] ?? '')))) === '' ? null : $c,
            'theme_content' => ($t = trim((string) ($data['content'] ?? ''))) === '' ? null : $t];
        $gone = [];
        foreach (['banner' => $banner, 'logo' => $logo] as $part => $file) {
            $column = "theme_{$part}_path";
            if ($file !== null || ! empty($data["remove_$part"])) {
                $gone[] = $repository->$column;
                $fields[$column] = $file?->storeAs("repository-theme/{$repository->id}", $part.'-'.Str::random(8).'.'.$file->extension(), 'local');
            }
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $repository->update($fields)));
        Storage::disk('local')->delete(array_filter($gone));
    }

    /** AtoM's upload limit, in gigabytes: none for no limit, 0 to switch uploads off. */
    public function setUploadLimit(Repository $repository, ?float $gigabytes, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $repository->update(['upload_limit_gb' => $gigabytes])));
    }

    /** Bytes of the files kept for the units the repository holds (Heratio's diskUsageBytes), copies included. */
    public function usedBytes(Repository $repository): int
    {
        return (int) DB::table('digital_files')->join('instantiations', 'instantiations.id', '=', 'digital_files.instantiation_id')
            ->join('record_resources', 'record_resources.id', '=', 'instantiations.record_resource_id')
            ->where('record_resources.repository_id', $repository->id)->sum('digital_files.size_bytes');
    }

    /** Refuse a file that would take the repository past its upload limit, or any file when uploads are off. */
    public function assertRoomFor(Repository $repository, int $bytes): void
    {
        if ($repository->upload_limit_gb === null) {
            return;
        }
        $limit = (float) $repository->upload_limit_gb * 1_000_000_000;
        if ($limit == 0) {
            throw new InvalidArgumentException(__('uploads to :repository are switched off', ['repository' => $repository->described('authorised-name', [app()->getLocale(), 'en'])]));
        }
        $used = $this->usedBytes($repository);
        if ($used + $bytes > $limit) {
            throw new InvalidArgumentException(__('the file would take :repository past its upload limit of :limit GB (:used GB used)', [
                'repository' => $repository->described('authorised-name', [app()->getLocale(), 'en']), 'limit' => (float) $repository->upload_limit_gb,
                'used' => number_format($used / 1_000_000_000, 2, '.', '')]));
        }
    }

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
