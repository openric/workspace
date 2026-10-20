<?php

// OpenRiC Workspace - where the files of digital instantiations are kept, and how they come in (WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A file is taken in once: its SHA-256 is computed and, when the uploader
// gives the checksum they expect, compared with it; its type is read from its
// content, not its name; it is scanned for viruses when a scanner is
// configured; and it is kept by its checksum under the institution, outside
// the web root, so the only way to it is through the Workspace. Copies for
// viewing (a reference image and a thumbnail) are made from images and from
// the first page of a PDF, without the master's embedded metadata.

namespace App\Files;

use finfo;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class FileStore
{
    /** Longest side, in pixels, of each copy made from a master. */
    public const COPIES = ['reference' => 1600, 'thumbnail' => 300];

    public function root(): string
    {
        return rtrim(config('workspace.object_store') ?: storage_path('app/objects'), '/');
    }

    /**
     * Take a received file in, as its institution's master.
     *
     * @return array{sha256: string, size_bytes: int, mime_type: string, storage_path: string, width: ?int, height: ?int, scanned_with: ?string, scanned_at: ?\DateTimeInterface}
     */
    public function ingest(string $institutionId, string $path, ?string $expectedSha256 = null): array
    {
        $size = filesize($path);
        $limit = config('workspace.max_upload_megabytes') * 1024 * 1024;
        if ($size === false || $size > $limit) {
            throw new InvalidArgumentException('the file is larger than the '.config('workspace.max_upload_megabytes').' MB a file may be');
        }
        $sha = hash_file('sha256', $path);
        $expected = $expectedSha256 === null ? '' : strtolower(trim($expectedSha256));
        if ($expected !== '' && ! preg_match('/^[0-9a-f]{64}$/', $expected)) {
            throw new InvalidArgumentException('a SHA-256 checksum is 64 hexadecimal digits');
        }
        if ($expected !== '' && $expected !== $sha) {
            throw new InvalidArgumentException("the file's SHA-256 is $sha, not the $expected you gave: it was changed or damaged on the way, so it was not kept");
        }
        $scanner = $this->scan($path);

        return [...$this->keep($institutionId, $path, $sha), 'sha256' => $sha, 'size_bytes' => (int) $size, 'mime_type' => $this->type($path), ...$this->dimensions($path),
            'scanned_with' => $scanner, 'scanned_at' => $scanner ? now() : null];
    }

    /**
     * The reference copy and thumbnail of an image or a PDF, each kept like a master; none for other kinds of file.
     *
     * @return array<string, array> use => what ingest() returns
     */
    public function copies(string $institutionId, string $masterPath, string $mime): array
    {
        if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            return [];
        }
        $page = null;
        try {
            $source = $masterPath;
            if ($mime === 'application/pdf') {
                // The first page, as an image; ImageMagick's own PDF reading is often switched off by its policy.
                $page = tempnam(sys_get_temp_dir(), 'wsp');
                $process = new Process(['pdftoppm', '-png', '-r', '110', '-f', '1', '-l', '1', '-singlefile', $masterPath, $page]);
                $process->setTimeout(60)->run();
                if (! $process->isSuccessful()) {
                    return [];
                }
                $source = $page.'.png';
            }
            $copies = [];
            foreach (self::COPIES as $use => $side) {
                // ImageMagick runs in its own process, held to a memory and time limit, so a file that makes it
                // crash or run away (a damaged TIFF can) costs only the copies, never the request or import.
                $temp = tempnam(sys_get_temp_dir(), 'wsc');
                $process = new Process(['convert', '-limit', 'memory', '512MiB', '-limit', 'map', '1GiB', '-limit', 'time', '120',
                    $source.'[0]', '-auto-orient', '-background', 'white', '-flatten', '-thumbnail', "{$side}x{$side}>", '-strip', '-quality', '85', 'jpeg:'.$temp]);
                $process->setTimeout(150)->run();
                if (! $process->isSuccessful() || filesize($temp) === 0) {
                    @unlink($temp);
                    throw new RuntimeException('ImageMagick could not read it: '.trim(mb_substr($process->getErrorOutput() ?: 'exit '.$process->getExitCode(), 0, 300)));
                }
                $sha = hash_file('sha256', $temp);
                $copies[$use] = [...$this->keep($institutionId, $temp, $sha), 'sha256' => $sha, 'size_bytes' => (int) filesize($temp), 'mime_type' => 'image/jpeg',
                    ...$this->dimensions($temp), 'scanned_with' => null, 'scanned_at' => null];
                unlink($temp);
            }

            return $copies;
        } catch (Throwable $e) {
            // A master the copies cannot be made from is still kept; it is only not shown as an image.
            Log::warning('no copies made of a file', ['mime' => $mime, 'error' => $e->getMessage()]);

            return [];
        } finally {
            if ($page !== null) {
                @unlink($page);
                @unlink($page.'.png');
            }
        }
    }

    public function path(string $storagePath): string
    {
        return $this->root().'/'.$storagePath;
    }

    /** Is the kept file still what was received: its size and SHA-256 unchanged? */
    public function intact(string $storagePath, int $size, string $sha256): bool
    {
        $path = $this->path($storagePath);

        return is_file($path) && filesize($path) === $size && hash_file('sha256', $path) === $sha256;
    }

    /** @return array{storage_path: string} the file kept under its checksum, once, whatever it was called */
    private function keep(string $institutionId, string $path, string $sha): array
    {
        $relative = $institutionId.'/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
        $target = $this->path($relative);
        if (! is_file($target)) {
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0750, true) && ! is_dir(dirname($target))) {
                throw new RuntimeException('the file store cannot be written to');
            }
            if (! copy($path, $target.'.part') || ! rename($target.'.part', $target)) {
                throw new RuntimeException('the file could not be kept');
            }
            chmod($target, 0640);
        }

        return ['storage_path' => $relative];
    }

    private function type(string $path): string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    }

    /**
     * The width and height a file's header gives, or null where it gives none a real image could have:
     * a damaged header can claim billions of pixels.
     *
     * @return array{width: ?int, height: ?int}
     */
    private function dimensions(string $path): array
    {
        $size = @getimagesize($path);
        $plausible = fn ($n) => is_int($n) && $n > 0 && $n <= 1_000_000 ? $n : null;

        return ['width' => $plausible($size[0] ?? null), 'height' => $plausible($size[1] ?? null)];
    }

    /** The scanner that passed the file, or null when none is configured; a virus, or a scanner that cannot answer, refuses it. */
    private function scan(string $path): ?string
    {
        $scanner = (string) config('workspace.virus_scanner');
        if ($scanner === '') {
            return null;
        }
        $process = new Process([$scanner, '--no-summary', '--fdpass', $path]);
        $process->setTimeout(120)->run();
        if ($process->getExitCode() === 1) {
            throw new InvalidArgumentException('the virus scanner found '.trim(preg_replace(['/^.*?: /', '/\s+FOUND$/'], '', trim($process->getOutput()))).', so the file was not kept');
        }
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('the virus scanner could not check the file, so it was not kept; try again, or ask the administrator to look at '.$scanner);
        }

        return $scanner;
    }
}
