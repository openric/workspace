<?php

// OpenRiC Workspace - the files of research services: checked, scanned and kept by checksum in the file store, as
// agreement documents are (roadmap phase 19, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Research;

use App\Files\FileResponse;
use App\Files\FileStore;
use App\Models\ResearchFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ResearchFiles
{
    public const MAX_KILOBYTES = 20480;

    public function __construct(private FileStore $store) {}

    public function keep(UploadedFile $upload, string $institutionId, User $actor): ResearchFile
    {
        $file = $this->store->ingest($institutionId, $upload->getRealPath());

        return ResearchFile::create(['institution_id' => $institutionId, 'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'storage_path' => $file['storage_path'], 'mime_type' => $file['mime_type'], 'size_bytes' => $file['size_bytes'], 'sha256' => $file['sha256'],
            'scanned_with' => $file['scanned_with'], 'scanned_at' => $file['scanned_at'], 'uploaded_by_id' => $actor->id]);
    }

    public function respond(ResearchFile $file, bool $download = false): BinaryFileResponse
    {
        $path = $this->store->path($file->storage_path);
        abort_unless(is_file($path), 404);

        return FileResponse::make($file, $path, $download);
    }
}
