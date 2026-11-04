<?php

// OpenRiC Workspace - caption tracks of a sound or video instantiation (roadmap phase 22c, ADR 0020), after Heratio's
// ahg-media-streaming caption_track: WebVTT subtitles, captions, audio descriptions and chapters, one of each kind for
// each language, one of them shown by default. Changed: a track is checked as WebVTT before it is kept, kept in the
// instantiation's own OCFL object as captions/<language>-<kind>.vtt (roadmap phase 15b), and reaches a reader only with the sound or video it belongs to.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Media;

use App\Description\Bcp47;
use App\Description\InstantiationService;
use App\Files\FileStore;
use App\Models\CaptionTrack;
use App\Models\Instantiation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Captions
{
    public const KINDS = ['subtitles', 'captions', 'descriptions', 'chapters'];

    /** Largest track, in bytes. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private FileStore $store, private InstantiationService $instantiations) {}

    /** The number of cues in a WebVTT file (W3C WebVTT 4.1), or why it is not one. */
    public static function cues(string $text): int
    {
        $text = preg_replace('/^\x{FEFF}/u', '', str_replace(["\r\n", "\r"], "\n", $text));
        mb_check_encoding($text, 'UTF-8') || throw new InvalidArgumentException(__('A WebVTT file is UTF-8 text.'));
        preg_match('/^WEBVTT(?:[ \t][^\n]*)?(?:\n|$)/', $text) || throw new InvalidArgumentException(__('A WebVTT file starts with the line WEBVTT.'));
        $time = '(?:\d{2,}:)?[0-5]\d:[0-5]\d\.\d{3}';
        $count = 0;
        foreach (preg_split('/\n{2,}/', trim($text)) as $n => $block) {
            if ($n === 0 || preg_match('/^(NOTE|STYLE|REGION)(\s|$)/', $block)) {
                continue;
            }
            $lines = explode("\n", $block);
            $timing = str_contains($lines[0], '-->') ? $lines[0] : ($lines[1] ?? '');
            preg_match("/^($time)[ \t]+-->[ \t]+($time)(?:[ \t].*)?$/", $timing, $m)
                || throw new InvalidArgumentException(__('Cue :n has no timing line such as 00:00:01.000 --> 00:00:04.000.', ['n' => $count + 1]));
            self::seconds($m[2]) >= self::seconds($m[1]) || throw new InvalidArgumentException(__('Cue :n ends before it starts.', ['n' => $count + 1]));
            $count++;
        }
        $count > 0 || throw new InvalidArgumentException(__('The file has no cues.'));

        return $count;
    }

    public function add(Instantiation $instantiation, string $path, string $language, string $kind, string $label, bool $default, ?User $by): CaptionTrack
    {
        $instantiation->files->contains(fn ($f) => str_starts_with($f->mime_type, 'audio/') || str_starts_with($f->mime_type, 'video/'))
            || throw new InvalidArgumentException(__('Captions go with a sound or video file, and this instantiation has none.'));
        Bcp47::valid($language) || throw new InvalidArgumentException(__('Give the language as a language tag, such as en or af.'));
        in_array($kind, self::KINDS, true) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $kind]));
        (filesize($path) ?: 0) <= self::MAX_BYTES || throw new InvalidArgumentException(__('A caption file may be at most 2 MB.'));
        $cues = self::cues((string) file_get_contents($path));
        $kept = $this->store->into($instantiation->iri)->ingest($instantiation->institution_id, $path, null, "captions/$language-$kind.vtt");

        return DB::transaction(function () use ($instantiation, $language, $kind, $label, $default, $by, $cues, $kept) {
            if ($default) {
                CaptionTrack::where('instantiation_id', $instantiation->id)->where('is_default', true)->get()->each->update(['is_default' => false]);
            }

            return CaptionTrack::updateOrCreate(['instantiation_id' => $instantiation->id, 'language' => $language, 'kind' => $kind],
                ['institution_id' => $instantiation->institution_id, 'label' => trim($label) ?: $language, 'storage_path' => $kept['storage_path'], 'sha256' => $kept['sha256'],
                    'cue_count' => $cues, 'is_default' => $default, 'created_by_id' => $by?->id]);
        });
    }

    /** A track reaches who may receive a sound or video file of its instantiation: it says what the recording says. */
    public function deliverable(?User $user, CaptionTrack $track): bool
    {
        $instantiation = $track->instantiation->load('files.use', 'files.instantiation.record', 'files.instantiation.availability');

        return $instantiation->files->contains(fn ($f) => (str_starts_with($f->mime_type, 'audio/') || str_starts_with($f->mime_type, 'video/'))
            && $this->instantiations->deliverable($user, $f));
    }

    public function path(CaptionTrack $track): string
    {
        return $this->store->path($track->storage_path);
    }

    private static function seconds(string $time): float
    {
        $parts = array_reverse(explode(':', $time));

        return (float) $parts[0] + 60 * (int) $parts[1] + 3600 * (int) ($parts[2] ?? 0);
    }
}
