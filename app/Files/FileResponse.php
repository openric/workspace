<?php

// OpenRiC Workspace - how a file is sent, signed in or public (WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// With its own type, never sniffed, sandboxed so a file that is a web page
// cannot run on the site, private so no shared cache keeps it, and with its
// SHA-256, so the receiver can check what arrived.

namespace App\Files;

use App\Models\AccessionAttachment;
use App\Models\AgreementDocument;
use App\Models\DigitalFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class FileResponse
{
    /** Types a browser shows itself; any other file is sent as a download. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'audio/mpeg', 'audio/wav', 'video/mp4'];

    public static function make(DigitalFile|AgreementDocument|AccessionAttachment $file, string $path, bool $download = false): BinaryFileResponse
    {
        $disposition = in_array($file->mime_type, self::INLINE, true) && ! $download ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->original_name) ?: 'file';

        return response()->file($path, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
            'Digest' => 'sha-256='.base64_encode(hex2bin($file->sha256)),
        ])->setPrivate()->setContentDisposition($disposition, $file->original_name, $fallback);
    }
}
