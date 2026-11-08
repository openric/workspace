<?php

// OpenRiC Workspace - normalisation and migration of files (roadmap phase 15c, ADR 0022), after Heratio's ahg-preservation
// NormalizationService: a master normalised by the rule that matches its format (PRONOM before MIME type, then the
// rule's priority) into a preservation master or an access copy; any file migrated by a pathway into a preservation
// master. The result is kept in the instantiation's OCFL object beside the master, recorded as a conversion, and is a
// PREMIS normalisation or migration event from its source, by the tool and its version; a failure is an event too.
// Changed from Heratio: each tool runs from an argument list, never a shell string, in its own process with a time
// limit; its output is made in a folder of its own and only then kept; and no master is ever replaced.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Preservation;

use App\Description\InstantiationService;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\FormatConversion;
use App\Models\MigrationPathway;
use App\Models\MigrationPlan;
use App\Models\NormalisationRule;
use App\Models\User;
use App\Support\LimitedProcess;
use App\Support\Vocab;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class Conversions
{
    /** Shell-safe option lists, as Heratio's. */
    private const PRESETS = ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow'];

    private const PDF_SETTINGS = ['/default', '/screen', '/ebook', '/printer', '/prepress'];

    private const COMPRESS = ['lzw', 'zip', 'jpeg', 'none', 'rle', 'group4', 'fax'];

    private const BINARIES = ['imagemagick' => 'convert', 'ffmpeg' => 'ffmpeg', 'ghostscript' => 'gs', 'libreoffice' => 'soffice'];

    /** @var array<string, ?string> each tool's version, once read */
    private array $versions = [];

    public function __construct(private FileStore $store, private InstantiationService $instantiations, private PreservationEvents $events) {}

    /** The active rule for a master and purpose: one naming its PRONOM format first, then one naming its MIME type, by priority. */
    public function rule(DigitalFile $file, string $purpose): ?NormalisationRule
    {
        $file->loadMissing('format');
        $puid = $file->format?->puid;

        return NormalisationRule::where('is_active', true)->whereHas('purpose', fn ($q) => $q->where('notation', $purpose))
            ->where(fn ($q) => $q->where('source_mime', $file->mime_type)->when($puid, fn ($q) => $q->orWhere('source_puid', $puid)))
            ->orderByRaw('source_puid IS NULL')->orderBy('priority')->orderBy('id')->first();
    }

    /**
     * Normalise a master by the rule for it. Null when there is nothing to do: no rule, a copy of that purpose made
     * already, the master in the rule's target type already, or the tool not installed.
     */
    public function normalise(DigitalFile $master, string $purpose = 'preservation', ?User $actor = null): ?FormatConversion
    {
        $master->loadMissing('use', 'instantiation.files.use');
        if ($master->use?->notation !== 'master' || ! in_array($purpose, ['preservation', 'access'], true)) {
            return null;
        }
        $rule = $this->rule($master, $purpose);
        if ($rule === null || $master->mime_type === $rule->target_mime || $master->instantiation->files->contains(fn ($f) => $f->use?->notation === $purpose)) {
            return null;
        }

        return $this->convert($master, $purpose, $rule->tool->notation, $rule->target_format, $rule->target_ext, $rule->target_mime, (array) $rule->options,
            'normalization', ['rule_id' => $rule->id], $actor);
    }

    /** Migrate a file by a pathway into a preservation master, in place of any the instantiation had. */
    public function migrate(DigitalFile $file, MigrationPathway $pathway, ?MigrationPlan $plan = null, ?User $actor = null): FormatConversion
    {
        $file->loadMissing('use', 'instantiation');

        return $this->convert($file, 'preservation', $pathway->tool->notation, $pathway->target_puid, $pathway->target_ext, $pathway->target_mime, (array) $pathway->options,
            'migration', ['pathway_id' => $pathway->id, 'migration_plan_id' => $plan?->id], $actor)
            ?? throw new InvalidArgumentException(__('The tool :tool is not installed on this server.', ['tool' => $pathway->tool->notation]));
    }

    /** Is the program a tool runs installed here? */
    public static function installed(string $tool): bool
    {
        $binary = self::BINARIES[$tool] ?? null;

        return $binary !== null && trim((string) shell_exec('command -v '.escapeshellarg($binary))) !== '';
    }

    private function convert(DigitalFile $source, string $purpose, string $tool, string $targetFormat, string $ext, string $targetMime, array $options, string $event, array $links, ?User $actor): ?FormatConversion
    {
        if (! self::installed($tool)) {
            Log::warning('a conversion tool is not installed', ['tool' => $tool]);

            return null;
        }
        $version = $this->version($tool);
        $conversion = FormatConversion::create([...$links, 'institution_id' => $source->institution_id, 'instantiation_id' => $source->instantiation_id, 'source_file_id' => $source->id,
            'purpose_id' => $this->concept('normalisation-purpose', $purpose), 'source_format' => $source->format?->puid ?? $source->format?->name, 'source_mime_type' => $source->mime_type,
            'target_format' => $targetFormat, 'target_mime_type' => $targetMime, 'tool_id' => $this->concept('conversion-tool', $tool), 'tool_version' => $version,
            'status_id' => $this->concept('conversion-status', 'processing'), 'source_size' => $source->size_bytes, 'source_checksum' => $source->sha256,
            'options' => $options ?: null, 'started_at' => now(), 'created_by_id' => $actor?->id]);
        $agent = trim(['imagemagick' => 'ImageMagick', 'ffmpeg' => 'FFmpeg', 'ghostscript' => 'Ghostscript', 'libreoffice' => 'LibreOffice'][$tool].' '.$version);
        $work = sys_get_temp_dir().'/wsn-'.bin2hex(random_bytes(6));
        mkdir($work, 0700);
        $started = microtime(true);
        try {
            $output = $this->run($tool, $this->store->path($source->storage_path), $work, $ext, $options);
            $name = pathinfo($source->original_name, PATHINFO_FILENAME).($purpose === 'access' ? '-access' : '-preservation').'.'.$ext;
            $kept = $this->instantiations->keepDerived($source->instantiation, $purpose, $name, $output, $actor);
            $conversion->update(['status_id' => $this->concept('conversion-status', 'completed'), 'output_file_id' => $kept->id, 'output_size' => $kept->size_bytes,
                'output_checksum' => $kept->sha256, 'completed_at' => now(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            $this->events->record($kept, $event, 'success', __(':use made as :format by :tool from :name.', ['use' => $purpose === 'access' ? __('An access copy') : __('A preservation master'),
                'format' => $targetFormat, 'tool' => $agent, 'name' => $source->original_name]), null, null, $agent, $source->id);
        } catch (Throwable $e) {
            $conversion->update(['status_id' => $this->concept('conversion-status', 'failed'), 'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'completed_at' => now(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            $this->events->record($source, $event, 'failure', __(':format by :tool', ['format' => $targetFormat, 'tool' => $agent]), $e->getMessage(), null, $agent);
        } finally {
            exec('rm -rf '.escapeshellarg($work));
        }

        return $conversion->fresh();
    }

    /** Run the tool on the file, in the work folder; the file made, or an exception saying why not. */
    private function run(string $tool, string $input, string $work, string $ext, array $options): string
    {
        $output = $work.'/output.'.$ext;
        $pick = fn (string $key, array $allowed, string $default) => in_array($options[$key] ?? $default, $allowed, true) ? ($options[$key] ?? $default) : $default;
        $limits = ['-limit', 'memory', '512MiB', '-limit', 'map', '1GiB', '-limit', 'time', '600'];
        [$command, $timeout] = match ($tool) {
            'imagemagick' => [in_array($ext, ['jpg', 'jpeg'], true)
                ? ['convert', ...$limits, $input.'[0]', '-quality', (string) (int) ($options['quality'] ?? 85), '-flatten', $output]
                : ['convert', ...$limits, $input, '-quality', (string) (int) ($options['quality'] ?? 95), '-compress', $pick('compress', self::COMPRESS, 'lzw'), $output], 900],
            'ffmpeg' => [match (true) {
                in_array($ext, ['wav', 'flac', 'mp3', 'ogg', 'm4a', 'aac'], true) => ['ffmpeg', '-nostdin', '-y', '-v', 'error', '-i', $input, $output],
                $ext === 'mkv' => ['ffmpeg', '-nostdin', '-y', '-v', 'error', '-i', $input, '-c:v', 'ffv1', '-level', '3', '-c:a', 'flac', $output],
                default => ['ffmpeg', '-nostdin', '-y', '-v', 'error', '-i', $input, '-preset', $pick('preset', self::PRESETS, 'medium'), '-crf', (string) (int) ($options['crf'] ?? 23), $output],
            }, 7200],
            // PDF/A-2b, as Heratio makes it.
            'ghostscript' => [['gs', '-dPDFA=2', '-dBATCH', '-dNOPAUSE', '-dQUIET', '-dSAFER', '-sColorConversionStrategy=UseDeviceIndependentColor', '-sDEVICE=pdfwrite',
                '-dPDFACompatibilityPolicy=1', '-dPDFSETTINGS='.$pick('pdf_settings', self::PDF_SETTINGS, '/printer'), '-sOutputFile='.$output, $input], 900],
            // A profile of its own each run: LibreOffice runs one at a time on a shared profile, and a second waits or fails.
            'libreoffice' => [['soffice', '--headless', '-env:UserInstallation=file://'.$work.'/profile', '--convert-to', $ext, '--outdir', $work.'/out', $input], 900],
        };
        $process = LimitedProcess::make($tool, $command, $work);
        $process->setTimeout($timeout)->run();
        if ($tool === 'libreoffice') {
            // LibreOffice names its output after the input.
            $made = glob($work.'/out/*.'.$ext) ?: [];
            $made === [] || rename($made[0], $output);
        }
        if (! $process->isSuccessful() || ! is_file($output) || filesize($output) === 0) {
            throw new \RuntimeException(LimitedProcess::reason($process) ?? (trim(mb_substr($process->getErrorOutput() ?: $process->getOutput() ?: 'exit '.$process->getExitCode(), 0, 1000)) ?: __('the tool made nothing')));
        }
        $ceiling = (int) ($options['max_output_mb'] ?? 0);
        if ($ceiling > 0 && filesize($output) > $ceiling * 1024 * 1024) {
            throw new \RuntimeException(__('the result was larger than the :mb MB the rule allows', ['mb' => $ceiling]));
        }

        return $output;
    }

    private function version(string $tool): ?string
    {
        if (! array_key_exists($tool, $this->versions)) {
            $process = LimitedProcess::make($tool, match ($tool) {
                'imagemagick' => ['convert', '-version'], 'ffmpeg' => ['ffmpeg', '-version'], 'ghostscript' => ['gs', '--version'], default => ['soffice', '--version'],
            });
            $process->setTimeout(60)->run();
            preg_match('/(\d+(?:\.\d+)+(?:-\d+)?)/', strtok($process->getOutput(), "\n") ?: '', $m);
            $this->versions[$tool] = $m[1] ?? null;
        }

        return $this->versions[$tool];
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
