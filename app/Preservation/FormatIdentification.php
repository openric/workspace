<?php

// OpenRiC Workspace - each file's format identified against PRONOM (roadmap phase 15a), after Heratio's ahg-preservation
// PronomIdentificationService. Changed: identified by Siegfried, which reads PRONOM's own signature files (DROID and
// container signatures), where Heratio matched a short list of magic bytes it kept itself; the format and the basis of
// the match are kept on the file, and the identification is a PREMIS event.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Preservation;

use App\Files\FileStore;
use App\Models\DigitalFile;
use App\Models\FileFormat;
use App\Support\LimitedProcess;

class FormatIdentification
{
    public function __construct(private FileStore $store, private PreservationEvents $events) {}

    /** Identify one file: the format it was found to be, or null where Siegfried is missing, failed or found none. */
    public function identify(DigitalFile $file): ?FileFormat
    {
        $path = $this->store->path($file->storage_path);
        $binary = (string) config('workspace.siegfried', 'sf');
        if (! is_file($path) || $binary === '') {
            return null;
        }
        $process = LimitedProcess::make('siegfried', [$binary, '-json', $path]);
        $process->setTimeout(120)->run();
        $answer = json_decode($process->getOutput(), true);
        $match = collect($answer['files'][0]['matches'] ?? [])->first(fn ($m) => ($m['ns'] ?? '') === 'pronom');
        $software = trim('siegfried '.($answer['siegfried'] ?? '').' ('.($answer['identifiers'][0]['details'] ?? 'PRONOM').')');
        if (! $process->isSuccessful() || $match === null) {
            $this->events->record($file, 'format-identification', 'failure', null, trim($process->getErrorOutput()) ?: __('Siegfried gave no answer.'), null, $software);

            return null;
        }
        if (($match['id'] ?? 'UNKNOWN') === 'UNKNOWN') {
            $file->update(['format_id' => null, 'format_basis' => null, 'format_warning' => $match['warning'] ?? null, 'format_identified_at' => now()]);
            $this->events->record($file, 'format-identification', 'warning', null, __('Not identified: :why', ['why' => $match['warning'] ?: __('no PRONOM signature matched')]), null, $software);

            return null;
        }
        $format = FileFormat::firstOrCreate(['puid' => $match['id']], ['name' => $match['format'] ?: $match['id'], 'version' => ($match['version'] ?? '') ?: null,
            'mime_type' => ($match['mime'] ?? '') ?: null, 'format_class' => ($match['class'] ?? '') ?: null,
            // The extension of the first file found in it, as Heratio's preservation_format.extension (phase 15c).
            'extension' => mb_strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)) ?: null]);
        $file->update(['format_id' => $format->id, 'format_basis' => ($match['basis'] ?? '') ?: null, 'format_warning' => ($match['warning'] ?? '') ?: null, 'format_identified_at' => now()]);
        $this->events->record($file, 'format-identification', ($match['warning'] ?? '') === '' ? 'success' : 'warning',
            $format->puid.' '.$format->name, ($match['basis'] ?? '').(($match['warning'] ?? '') !== '' ? '; '.$match['warning'] : ''), null, $software);

        return $format;
    }
}
