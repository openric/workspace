<?php

// OpenRiC Workspace - 3D models (roadmap phase 21, 3D objects), after Heratio's ahg-3d-model ThreeDThumbnailService and
// its Blender scripts: which files are 3D models (GLB, glTF, OBJ, STL, PLY and USDZ, and a ZIP holding a glTF or an OBJ
// with what it needs; Gaussian splats as .splat, .ksplat or a PLY of splats), the GLB a browser shows made from any of
// them by Blender, a thumbnail rendered by Blender, a turntable video rendered by Blender and joined by FFmpeg, and what
// the model is made of. Each tool runs under its process limits; where Blender is not installed a GLB is still shown
// and the others are kept as downloads.
// Changed from Heratio: the copies are kept like every viewing copy, in the object store beside the master, which is
// never changed; a ZIP is counted before it is unpacked and may not name a file outside itself.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Files;

use App\Support\Archives;
use App\Support\LimitedProcess;
use RuntimeException;
use ZipArchive;

class Models3d
{
    /** The formats Blender turns into a GLB, by extension. */
    public const CONVERTED = ['gltf', 'obj', 'stl', 'ply', 'usdz', 'usd', 'usdc', 'usda'];

    /** Gaussian splats, shown by the splat viewer rather than as a mesh. */
    public const SPLATS = ['splat', 'ksplat'];

    /** What a file is: glb, one of CONVERTED, zip (a ZIP holding a glTF or an OBJ), splat, or null when it is no 3D model. */
    public function format(string $path, ?string $name, string $mime): ?string
    {
        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if ($mime === FileStore::MODEL || $extension === 'glb') {
            return 'glb';
        }
        if (in_array($extension, self::SPLATS, true) || ($extension === 'ply' && $this->plySplat($path))) {
            return 'splat';
        }
        if (in_array($extension, self::CONVERTED, true)) {
            return $extension;
        }
        if ($extension === 'zip' && $this->zipMain($path) !== null) {
            return 'zip';
        }

        return null;
    }

    public function blender(): ?string
    {
        $binary = (string) config('workspace.blender', 'blender');

        return $binary !== '' && (is_executable($binary) || trim((string) shell_exec('command -v '.escapeshellarg($binary))) !== '') ? $binary : null;
    }

    /**
     * The model as a GLB in a temporary file the caller removes, or null where none can be made. A GLB is copied as it
     * is; the others are imported and written again by Blender.
     */
    public function glb(string $path, string $format): ?string
    {
        $out = sys_get_temp_dir().'/wsm-'.bin2hex(random_bytes(6)).'.glb';
        if ($format === 'glb') {
            return copy($path, $out) ? $out : null;
        }
        if ($format === 'splat' || $this->blender() === null) {
            return null;
        }
        [$source, $work] = $this->source($path, $format);
        try {
            return $this->run('glb', $source, $out) ? $out : null;
        } finally {
            $work && exec('rm -rf '.escapeshellarg($work));
        }
    }

    /** A thumbnail, as PNG in a temporary file, rendered from the GLB; null without Blender. */
    public function thumbnail(string $glb, int $size = 400): ?string
    {
        if ($this->blender() === null) {
            return null;
        }
        $out = sys_get_temp_dir().'/wst-'.bin2hex(random_bytes(6)).'.png';

        return $this->run('thumb', $glb, $out, [(string) $size]) ? $out : null;
    }

    /** A turntable, as MP4 in a temporary file: Blender renders the frames around the model and FFmpeg joins them. */
    public function turntable(string $glb, int $frames = 72, int $size = 720): ?string
    {
        if ($this->blender() === null) {
            return null;
        }
        $dir = sys_get_temp_dir().'/wstt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            if (! $this->run('turntable', $glb, $dir.'/frame-', [(string) $size, (string) $frames])) {
                return null;
            }
            $out = sys_get_temp_dir().'/wsv-'.bin2hex(random_bytes(6)).'.mp4';
            $ffmpeg = LimitedProcess::make('ffmpeg', ['ffmpeg', '-nostdin', '-y', '-v', 'error', '-framerate', '24', '-i', $dir.'/frame-%04d.png',
                '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $out]);
            $ffmpeg->setTimeout(900)->run();

            return $ffmpeg->isSuccessful() && is_file($out) && filesize($out) > 0 ? $out : null;
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    /** What the model is made of (Heratio's ModelMetadataExtractor), from the file a format names. */
    public function properties(string $path, string $format): array
    {
        if (in_array($format, ['splat', 'usdz', 'usd', 'usdc', 'usda'], true)) {
            return [];
        }
        [$source, $work] = $this->source($path, $format);
        try {
            return app(ModelMetadata::class)->extract($source, strtolower(pathinfo($source, PATHINFO_EXTENSION)) ?: $format);
        } finally {
            $work && exec('rm -rf '.escapeshellarg($work));
        }
    }

    /** @return array{0: string, 1: ?string} the file Blender reads, and the folder to remove after (a ZIP's, unpacked) */
    private function source(string $path, string $format): array
    {
        if ($format !== 'zip') {
            // Blender and the reader choose by extension, which a file in the store does not have.
            $work = sys_get_temp_dir().'/wsx-'.bin2hex(random_bytes(6));
            mkdir($work, 0700);
            symlink($path, $work.'/model.'.$format);

            return [$work.'/model.'.$format, $work];
        }
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::RDONLY) === true || throw new RuntimeException(__('The package file cannot be opened.'));
        Archives::zip($zip);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            in_array('..', explode('/', (string) $zip->getNameIndex($i)), true) && throw new RuntimeException(__('the ZIP names a file outside itself'));
        }
        $work = sys_get_temp_dir().'/wsz-'.bin2hex(random_bytes(6));
        mkdir($work, 0700);
        $zip->extractTo($work) || throw new RuntimeException(__('The package file cannot be opened.'));
        $main = $this->zipMain($path);
        $zip->close();

        return [$work.'/'.$main, $work];
    }

    /** The glTF, else the OBJ, a ZIP holds (the shortest path, if it holds several). */
    private function zipMain(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }
        $names = array_map(fn ($i) => (string) $zip->getNameIndex($i), range(0, max(0, $zip->numFiles - 1)));
        $zip->close();
        foreach (['gltf', 'obj'] as $extension) {
            $found = array_values(array_filter($names, fn ($n) => strtolower(pathinfo($n, PATHINFO_EXTENSION)) === $extension && ! str_starts_with(basename($n), '.')));
            if ($found !== []) {
                usort($found, fn ($a, $b) => strlen($a) <=> strlen($b));

                return $found[0];
            }
        }

        return null;
    }

    /** A PLY of Gaussian splats names its spherical-harmonic colours in its header. */
    private function plySplat(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4096);

        return str_starts_with($head, 'ply') && str_contains($head, 'f_dc_0');
    }

    private function run(string $mode, string $source, string $out, array $more = []): bool
    {
        $process = LimitedProcess::make('blender', [$this->blender(), '--background', '--factory-startup', '--python', base_path('tools/blender/model3d.py'), '--',
            $mode, $source, $out, ...$more]);
        $process->setTimeout($mode === 'turntable' ? 1800 : 600)->run();

        return $process->isSuccessful() && ($mode === 'turntable' ? glob($out.'*.png') !== [] : (is_file($out) && filesize($out) > 0));
    }
}
