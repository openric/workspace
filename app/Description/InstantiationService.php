<?php

// OpenRiC Workspace - instantiations of units of description and their files (rico:Instantiation; WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An instantiation holds at most one master; what is received is never
// changed, so another version is another instantiation. Who receives a file
// is decided in one place, deliverable(): the file's use needs its read
// permission on the unit (read-master, read-reference, read-thumbnail), an
// instantiation on request is delivered only to those who may update the
// unit, or to a researcher whose request for it is approved and not over
// (roadmap phase 7), a withdrawn one to nobody, and nothing of a deleted
// unit or instantiation is delivered.

namespace App\Description;

use App\Access\AccessControl;
use App\AccessRequests\AccessRequestService;
use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Files\Watermark;
use App\Jobs\ExtractFileText;
use App\Jobs\JobsService;
use App\Jobs\MakeViewingCopies;
use App\Jobs\ReadEmbeddedMetadata;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InstantiationService
{
    public const CARRIER_TYPES = Vocab::BASE.'carrier-type/';

    public const AVAILABILITY = Vocab::BASE.'file-availability/';

    public const USES = Vocab::BASE.'file-use/';

    public function __construct(private DescriptionService $descriptions, private FileStore $store) {}

    public function create(RecordResource $unit, string $title, string $language, ?string $carrierType = null, string $availability = 'online', ?User $actor = null): Instantiation
    {
        if ($unit->deleted_at !== null) {
            throw new InvalidArgumentException(__('a deleted unit of description takes no new instantiations'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($unit, $title, $language, $carrierType, $availability) {
            $id = (string) Str::uuid7();
            $base = DB::table('institutions')->where('id', $unit->institution_id)->value('base_iri');
            $instantiation = new Instantiation([
                'institution_id' => $unit->institution_id, 'iri' => $base.'/'.Instantiation::IRI_SEGMENT.'/'.$id, 'record_resource_id' => $unit->id,
                'carrier_type_id' => $carrierType ? $this->concept(self::CARRIER_TYPES, $carrierType)->id : null,
                'availability_id' => $this->concept(self::AVAILABILITY, $availability)->id,
                'position' => (int) Instantiation::where('record_resource_id', $unit->id)->max('position') + 1,
            ]);
            $instantiation->id = $id;
            $instantiation->save();
            $this->describe($instantiation, ['instantiation-title' => $title], $language);

            return $instantiation;
        }));
    }

    /**
     * A digital instantiation from an uploaded file: the file is checked and kept, then the instantiation is made with it.
     * The title is the file's name unless one is given.
     */
    public function upload(RecordResource $unit, string $path, string $originalName, string $language, ?string $title = null, ?string $expectedSha256 = null,
        string $availability = 'online', ?User $actor = null): Instantiation
    {
        app(RepositoryService::class)->assertRoomFor($unit->repository, (int) filesize($path));
        $master = $this->store->ingest($unit->institution_id, $path, $expectedSha256);

        $instantiation = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($unit, $originalName, $language, $title, $availability, $master) {
            $instantiation = $this->create($unit, trim((string) $title) !== '' ? (string) $title : $originalName, $language, 'digital-file', $availability);
            $this->addFile($instantiation, 'master', $originalName, $master);

            return $instantiation;
        }));
        // The viewing copies, embedded metadata and a PDF's text are read in the background (roadmap phase 5); the file is kept and checked already.
        $job = app(JobsService::class)->create($unit->institution_id, 'viewing-copies', __('Viewing copies of :name', ['name' => $originalName]), $actor, $instantiation);
        MakeViewingCopies::dispatch($job->id, $instantiation->id);
        $job = app(JobsService::class)->create($unit->institution_id, 'embedded-metadata', __('Embedded metadata of :name', ['name' => $originalName]), $actor, $instantiation);
        ReadEmbeddedMetadata::dispatch($job->id, $instantiation->id);
        if ($master['mime_type'] === 'application/pdf') {
            $job = app(JobsService::class)->create($unit->institution_id, 'file-text', __('Text of :name', ['name' => $originalName]), $actor, $instantiation);
            ExtractFileText::dispatch($job->id, $instantiation->id);
        }

        return $instantiation->load('files.use');
    }

    /**
     * Make the copies for viewing of a master that has none, as an upload would now: for a file kept before a kind of copy
     * was made, or whose copies could not be made then. The number of copies added.
     */
    public function makeCopies(Instantiation $instantiation, ?User $actor = null): int
    {
        $master = $instantiation->file('master');
        if ($master === null || $instantiation->files()->where('id', '!=', $master->id)->exists()) {
            return 0;
        }
        $watermark = app(Watermark::class)->configFor(Institution::findOrFail($instantiation->institution_id));
        $copies = $this->store->copies($instantiation->institution_id, $this->store->path($master->storage_path), $master->mime_type, $watermark);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $this->addCopies($instantiation, $master->original_name, $copies)));

        return count($copies);
    }

    /**
     * Make the copies for viewing again, as Heratio's regenerateDerivatives: after the watermark changed, or
     * when a copy is wrong. The old copies' records go; their files stay in the store, kept by checksum, where
     * another record may share them. The number of copies made.
     */
    public function remakeCopies(Instantiation $instantiation, ?User $actor = null): int
    {
        $master = $instantiation->file('master');
        if ($master === null) {
            return 0;
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $instantiation->files()->where('id', '!=', $master->id)->get()->each->delete()));
        $instantiation->unsetRelation('files');

        return $this->makeCopies($instantiation, $actor);
    }

    /**
     * AtoM's own reference or thumbnail representation (repFile_reference, repFile_thumbnail): an image given for one
     * copy instead of the one made from the master, sized and, for a reference copy, watermarked as a made one is.
     * Making the copies again from the master replaces it.
     */
    public function replaceCopy(Instantiation $instantiation, string $use, string $path, ?User $actor = null): DigitalFile
    {
        if (! in_array($use, ['reference', 'thumbnail'], true) || $instantiation->file('master') === null) {
            throw new InvalidArgumentException(__('only the reference copy or thumbnail of a kept file can be given'));
        }
        $mime = (string) mime_content_type($path);
        if (! str_starts_with($mime, 'image/')) {
            throw new InvalidArgumentException(__('give an image for the copy'));
        }
        $watermark = app(Watermark::class)->configFor(Institution::findOrFail($instantiation->institution_id));
        // ponytail: copies() makes and keeps both sizes; the one not used stays in the store unreferenced, as an old copy
        // does after remakeCopies. A copies() that takes the uses wanted would keep only the one.
        $copy = $this->store->copies($instantiation->institution_id, $path, $mime, $watermark)[$use];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($instantiation, $use, $copy) {
            $instantiation->files()->whereHas('use', fn ($q) => $q->where('notation', $use))->get()->each->delete();
            $instantiation->unsetRelation('files');

            return $this->addFile($instantiation, $use, pathinfo($instantiation->file('master')->original_name, PATHINFO_FILENAME).'-'.$use.'.jpg', $copy);
        }));
    }

    private function addCopies(Instantiation $instantiation, string $originalName, array $copies): void
    {
        foreach ($copies as $use => $copy) {
            $extension = $copy['mime_type'] === FileStore::MODEL ? 'glb' : 'jpg';
            $this->addFile($instantiation, $use, pathinfo($originalName, PATHINFO_FILENAME).'-'.$use.'.'.$extension, $copy);
        }
    }

    /** @param  array<string, ?string>  $values  element notation => text */
    public function describe(Instantiation $instantiation, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($instantiation, Elements::INSTANTIATION, $values, $language, 'instantiation-title', $actor);
    }

    /** The carrier type (carrier-type notation or null) and availability (file-availability notation). */
    public function updateFacts(Instantiation $instantiation, ?string $carrierType, string $availability, ?User $actor = null): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $instantiation->update([
            'carrier_type_id' => $carrierType ? $this->concept(self::CARRIER_TYPES, $carrierType)->id : null,
            'availability_id' => $this->concept(self::AVAILABILITY, $availability)->id,
        ])));
    }

    /** Is the kept file still what was received? Records the outcome; a failure is audited. */
    public function checkFixity(DigitalFile $file, ?User $actor = null): bool
    {
        $intact = $this->store->intact($file->storage_path, $file->size_bytes, $file->sha256);
        AuditLog::actingAs($actor, fn () => $file->update($intact ? ['fixity_checked_at' => now(), 'fixity_failed_at' => null] : ['fixity_failed_at' => now()]));

        return $intact;
    }

    /**
     * May the account, or with none the public of the file's institution, receive the file? The one rule for delivery (WS-007).
     * The public receives a file only when its instantiation's title is published too.
     */
    public function deliverable(?User $user, DigitalFile $file): bool
    {
        $instantiation = $file->instantiation;
        $unit = $instantiation->record;
        if ($instantiation->deleted_at !== null || $unit->deleted_at !== null || ($user !== null && $unit->institution_id !== $user->institution_id)) {
            return false;
        }
        $may = fn (string $action) => app(AccessControl::class)->allows($user, $action, 'description', $unit, $user ? null : Institution::find($unit->institution_id));
        // An approved access request opens every file of an instantiation on request, until it ends (roadmap phase 7).
        if ($user !== null && $instantiation->availability->notation === 'on-request' && app(AccessRequestService::class)->grants($user, $instantiation)) {
            return true;
        }
        if (! $may('read-'.$file->use->notation) || ! $may('read')) {
            return false;
        }
        if ($user === null && ! $instantiation->names()->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))
            ->whereHas('state', fn ($q) => $q->where('iri', Vocab::valueState('published')))->exists()) {
            return false;
        }

        return match ($instantiation->availability->notation) {
            'online' => true,
            'on-request' => $user !== null && $may('update'),
            default => false,
        };
    }

    public function path(DigitalFile $file): string
    {
        return $this->store->path($file->storage_path);
    }

    private function addFile(Instantiation $instantiation, string $use, string $name, array $kept): DigitalFile
    {
        return DigitalFile::create([...$kept, 'institution_id' => $instantiation->institution_id, 'instantiation_id' => $instantiation->id,
            'use_id' => $this->concept(self::USES, $use)->id, 'original_name' => mb_substr($name, 0, 255), 'fixity_checked_at' => now()]);
    }

    private function concept(string $vocabulary, string $notation): Concept
    {
        return $this->descriptions->concept($vocabulary.$notation);
    }
}
