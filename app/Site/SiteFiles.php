<?php

// OpenRiC Workspace - the files and images of the institution's public site (roadmap phase 20a): an article's
// attachments, cover and pictures, and a landing page's images. Heratio keeps these on its public disk; here each is
// taken in as every file is (held to the upload limit and scanned for viruses), kept in an OCFL object of its own, and
// served only while what it belongs to may be seen: a file of an article only while the article is published.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Site;

use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Models\Article;
use App\Models\Institution;
use App\Models\SiteFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SiteFiles
{
    /** Heratio's ATTACHMENT_EXTENSIONS, with its video formats. */
    public const ATTACHMENTS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'odt', 'ods', 'zip', 'txt', 'mp4', 'webm'];

    public const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Heratio's VIDEO_MAX_KB: 200 MB. Other files are held to the Workspace's upload limit. */
    public const VIDEO_MAX_MEGABYTES = 200;

    public function __construct(private FileStore $store) {}

    /** Take a file in for the site, of the extensions allowed; an article's file is served only while it is published. */
    public function store(Institution $institution, UploadedFile $upload, array $extensions, ?Article $article, User $actor): SiteFile
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        in_array($extension, $extensions, true) || throw new InvalidArgumentException(__('The file must be a file of type: :types.', ['types' => implode(', ', $extensions)]));
        if (in_array($extension, ['mp4', 'webm'], true) && $upload->getSize() > self::VIDEO_MAX_MEGABYTES * 1024 * 1024) {
            throw new InvalidArgumentException(__('Videos may be at most :size MB.', ['size' => self::VIDEO_MAX_MEGABYTES]));
        }
        $id = (string) Str::uuid7();
        $name = Str::limit(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 200, '') ?: 'file.'.$extension;
        $kept = $this->store->into(rtrim($institution->base_iri, '/').'/site-file/'.$id)->ingest($institution->id, $upload->getRealPath(), null, $name);
        $type = $kept['mime_type'];
        if (in_array($extension, self::IMAGES, true) && ! str_starts_with($type, 'image/')) {
            $this->store->ocfl()->purge(rtrim($institution->base_iri, '/').'/site-file/'.$id);
            throw new InvalidArgumentException(__('The file is not an image.'));
        }

        // The row takes the id its OCFL object is named by.
        $file = new SiteFile(['institution_id' => $institution->id, 'article_id' => $article?->id, 'file_name' => $name, 'mime_type' => $type, 'size_bytes' => $kept['size_bytes'],
            'sha256' => $kept['sha256'], 'storage_path' => $kept['storage_path'], 'created_by_id' => $actor->id]);
        $file->id = $id;

        return AuditLog::actingAs($actor, fn () => tap($file)->save());
    }

    /** Remove a file and its OCFL object. */
    public function delete(SiteFile $file, ?User $actor = null): void
    {
        $base = Institution::whereKey($file->institution_id)->value('base_iri');
        $this->store->ocfl()->purge(rtrim((string) $base, '/').'/site-file/'.$file->id);
        $actor ? AuditLog::actingAs($actor, fn () => $file->delete()) : $file->delete();
    }

    /** May it be served: to the public while what it belongs to is public, to who edits the site always. */
    public function servable(SiteFile $file, Institution $institution, ?User $user): bool
    {
        if ($file->institution_id !== $institution->id) {
            return false;
        }
        if ($user && $user->institution_id === $institution->id && $user->mayAnywhere('publish', 'description')) {
            return true;
        }

        return ! $file->article_id || (bool) $file->article?->isPublic();
    }

    public function path(SiteFile $file): string
    {
        return $this->store->path($file->storage_path);
    }
}
