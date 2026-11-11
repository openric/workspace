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
use App\Files\Models3d;
use App\Files\Watermark;
use App\Fixity\Fixity;
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
use App\Privacy\Screening;
use App\Rights\Clearances;
use App\Rights\CommunityProtocols;
use App\Rights\Embargoes;
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

    public function create(RecordResource $unit, string $title, string $language, ?string $carrierType = null, string $availability = 'online', ?User $actor = null, ?string $id = null): Instantiation
    {
        if ($unit->deleted_at !== null) {
            throw new InvalidArgumentException(__('a deleted unit of description takes no new instantiations'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($unit, $title, $language, $carrierType, $availability, $id) {
            $id ??= (string) Str::uuid7();
            $instantiation = new Instantiation([
                'institution_id' => $unit->institution_id, 'iri' => $this->iri($unit->institution_id, $id), 'record_resource_id' => $unit->id,
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
        // The master is kept in the instantiation's own OCFL object, named by the instantiation's IRI (roadmap phase 15b).
        $id = (string) Str::uuid7();
        $master = $this->store->into($this->iri($unit->institution_id, $id))->ingest($unit->institution_id, $path, $expectedSha256, 'files/master/'.self::logicalName($originalName));

        $instantiation = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($unit, $originalName, $language, $title, $availability, $master, $id) {
            $instantiation = $this->create($unit, trim((string) $title) !== '' ? (string) $title : $originalName, $language, 'digital-file', $availability, null, $id);
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
        if ($master === null || $instantiation->files()->whereHas('use', fn ($q) => $q->whereIn('notation', ['reference', 'thumbnail']))->exists()) {
            return 0;
        }
        $watermark = app(Watermark::class)->configFor(Institution::findOrFail($instantiation->institution_id), $instantiation->record);
        $copies = $this->store->into($instantiation->iri)->copies($instantiation->institution_id, $this->store->path($master->storage_path), $master->mime_type, $watermark, $master->original_name);
        // What a 3D model is made of, read from its master once (roadmap phase 21).
        if ($format = app(Models3d::class)->format($this->store->path($master->storage_path), $master->original_name, $master->mime_type)) {
            $master->update(['model_properties' => ['format' => $format] + app(Models3d::class)->properties($this->store->path($master->storage_path), $format)]);
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $this->addCopies($instantiation, $master->original_name, $copies)));

        return count($copies);
    }

    /**
     * A turntable video of a 3D model (roadmap phase 21, after Heratio's ahg:3d-multiangle): rendered all round its
     * GLB by Blender, joined by FFmpeg and kept as its access copy, in place of an earlier one. Whether one was made.
     */
    public function makeTurntable(Instantiation $instantiation, ?User $actor = null): bool
    {
        $source = collect([$instantiation->file('reference'), $instantiation->file('master')])->first(fn ($f) => $f?->mime_type === FileStore::MODEL);
        $video = $source ? app(Models3d::class)->turntable($this->store->path($source->storage_path)) : null;
        if ($video === null) {
            return false;
        }
        try {
            $kept = $this->store->into($instantiation->iri)->keepMade($instantiation->institution_id, $video, 'files/access/turntable.mp4');
            DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($instantiation, $kept) {
                $instantiation->files()->whereHas('use', fn ($q) => $q->where('notation', 'access'))->where('mime_type', 'video/mp4')->get()->each->delete();
                $this->addFile($instantiation, 'access', pathinfo((string) $instantiation->file('master')?->original_name, PATHINFO_FILENAME).'-turntable.mp4',
                    ['storage_path' => $kept['storage_path'], 'size_bytes' => $kept['size_bytes'], 'sha256' => $kept['sha256'], 'mime_type' => 'video/mp4']);
            }));

            return true;
        } finally {
            @unlink($video);
        }
    }

    /** Make the copies for viewing again in the background, as a job the account can follow. */
    public function queueRemake(Instantiation $instantiation, ?User $actor = null): void
    {
        $job = app(JobsService::class)->create($instantiation->institution_id, 'viewing-copies', __('Viewing copies of :name, again', ['name' => $instantiation->file('master')->original_name]), $actor, $instantiation);
        MakeViewingCopies::dispatch($job->id, $instantiation->id, true);
    }

    /**
     * Make the copies for viewing again, as Heratio's regenerateDerivatives: after the watermark changed, or
     * when a copy is wrong. The old copies' records go; their files stay in the earlier versions of the
     * instantiation's OCFL object. A preservation master or access copy is left as it is. The number of copies made.
     */
    public function remakeCopies(Instantiation $instantiation, ?User $actor = null): int
    {
        $master = $instantiation->file('master');
        if ($master === null) {
            return 0;
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $instantiation->files()->whereHas('use', fn ($q) => $q->whereIn('notation', ['reference', 'thumbnail']))->get()->each->delete()));
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
        $watermark = app(Watermark::class)->configFor(Institution::findOrFail($instantiation->institution_id), $instantiation->record);
        // ponytail: copies() makes and keeps both sizes; the one not used stays in the store unreferenced, as an old copy
        // does after remakeCopies. A copies() that takes the uses wanted would keep only the one.
        $copy = $this->store->into($instantiation->iri)->copies($instantiation->institution_id, $path, $mime, $watermark)[$use];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($instantiation, $use, $copy) {
            $instantiation->files()->whereHas('use', fn ($q) => $q->where('notation', $use))->get()->each->delete();
            $instantiation->unsetRelation('files');

            return $this->addFile($instantiation, $use, pathinfo($instantiation->file('master')->original_name, PATHINFO_FILENAME).'-'.$use.'.jpg', $copy);
        }));
    }

    /**
     * A file made from one of the instantiation's (a preservation master or access copy, roadmap phase 15c), kept in its
     * OCFL object under files/<use>/<name>, in place of any file it already had of that use; the earlier one stays in
     * the object's earlier versions.
     */
    public function keepDerived(Instantiation $instantiation, string $use, string $name, string $path, ?User $actor = null): DigitalFile
    {
        in_array($use, ['preservation', 'access'], true) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $use]));
        $kept = $this->store->into($instantiation->iri)->ingest($instantiation->institution_id, $path, null, "files/$use/".self::logicalName($name));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($instantiation, $use, $name, $kept) {
            $instantiation->files()->whereHas('use', fn ($q) => $q->where('notation', $use))->get()->each->delete();
            $instantiation->unsetRelation('files');

            return $this->addFile($instantiation, $use, $name, $kept);
        }));
    }

    private function addCopies(Instantiation $instantiation, string $originalName, array $copies): void
    {
        foreach ($copies as $use => $copy) {
            $extension = ['model/gltf-binary' => 'glb', 'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'image/png' => 'png'][$copy['mime_type']] ?? 'jpg';
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
        // Kept in the fixity ledger, with any failure open until it passes again (phase 12a).
        return app(Fixity::class)->check($file, null, $actor) === 'pass';
    }

    /**
     * May the account, or with none the public of the file's institution, receive the file? The one rule for delivery (WS-007).
     * The public receives a file only when its instantiation's title is published too.
     */
    public function deliverable(?User $user, DigitalFile $file): bool
    {
        // A preservation master is the master's, an access copy the reference copy's, to every rule below (phase 15c).
        return $this->mayHave($user, $file->instantiation, ['preservation' => 'master', 'access' => 'reference'][$file->use->notation] ?? $file->use->notation);
    }

    /**
     * May the account, or with none the public, have the text of an instantiation's pages (its transcript)? The text is the
     * page's content, so it goes where the reference copy would: never under an embargo that withholds the files, a
     * protocol, a clearance or screening that withholds them, nor of an instantiation kept on request.
     */
    public function textDeliverable(?User $user, Instantiation $instantiation): bool
    {
        return $this->mayHave($user, $instantiation, 'reference');
    }

    /** The one rule for delivery (WS-007), for a file of the use given (master, reference or thumbnail) of the instantiation. */
    private function mayHave(?User $user, Instantiation $instantiation, string $use): bool
    {
        $unit = $instantiation->record;
        if ($instantiation->deleted_at !== null || $unit->deleted_at !== null || ($user !== null && $unit->institution_id !== $user->institution_id)) {
            return false;
        }
        // A community protocol withholds the files of a record from who may not change it, an approved access request or not.
        if (! app(CommunityProtocols::class)->allows($user, $unit, 'description')) {
            return false;
        }
        // An embargo withholds the files its type says, an approved access request or not (phase 13a, ADR 0015).
        if (! app(Embargoes::class)->allows($user, $unit, $use)) {
            return false;
        }
        // A classified record's files reach only who is cleared to it; its master only where its level allows downloads (phase 18c, ADR 0016).
        if (! app(Clearances::class)->allows($user, $unit, $use === 'master' ? 'download' : 'read')) {
            return false;
        }
        // Where screening is required, the files wait until the record is screened and cleared (phase 18b, ADR 0016).
        if (app(Screening::class)->withholds($user, $unit)) {
            return false;
        }
        $may = fn (string $action) => app(AccessControl::class)->allows($user, $action, 'description', $unit, $user ? null : Institution::find($unit->institution_id));
        // An approved access request opens every file of an instantiation on request, until it ends (roadmap phase 7).
        if ($user !== null && $instantiation->availability->notation === 'on-request' && app(AccessRequestService::class)->grants($user, $instantiation)) {
            return true;
        }
        if (! $may('read-'.$use) || ! $may('read')) {
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

    private function iri(string $institutionId, string $id): string
    {
        return DB::table('institutions')->where('id', $institutionId)->value('base_iri').'/'.Instantiation::IRI_SEGMENT.'/'.$id;
    }

    /** A file's name as the last part of a logical path in its OCFL object: no folders, no control characters. */
    public static function logicalName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $name))));

        return in_array($name, ['', '.', '..'], true) ? 'file' : mb_substr($name, 0, 200);
    }

    private function concept(string $vocabulary, string $notation): Concept
    {
        return $this->descriptions->concept($vocabulary.$notation);
    }
}
