<?php

// OpenRiC Workspace - an Archivematica DIP taken in (roadmap phase 15d, ADR 0022), after Heratio's ahg-archivematica
// DipIngestService and DipMatcher: its METS read, the unit of description it belongs to found, and each of its access
// files kept as a digital instantiation of that unit, through the same upload every file takes (checked, scanned,
// identified, copied for viewing, a PREMIS ingestion). What the AIP's PREMIS says of each file (its original name,
// format and digest) is kept as a PREMIS metadata extraction event. Used for a DIP fetched from the Storage Service and
// for one deposited by SWORD. A DIP already taken in is not taken in twice.
// The unit: the one a SWORD deposit names; else the one sent in the transfer the AIP came from; else the one whose IRI,
// or whose identifier alone in the institution, the DIP's dc:identifier gives (Heratio's identifier strategy).
// Changed from Heratio: the files come in as instantiations of the unit, not as new descriptions; an archive is
// unpacked only where no name in it climbs out of its folder.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Preservation\Archivematica;

use App\Description\InstantiationService;
use App\Models\ArchivematicaJob;
use App\Models\ArchivematicaLink;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\User;
use App\Preservation\PreservationEvents;
use App\Support\Archives;
use App\Support\LimitedProcess;
use App\Support\Vocab;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

class DipIngest
{
    public function __construct(private MetsParser $mets, private InstantiationService $instantiations, private PreservationEvents $events) {}

    /** A DIP in an archive (ZIP, TAR or 7z), unpacked into a folder of its own and taken in. */
    public function fromArchive(string $archive, ?RecordResource $target, ?string $dipUuid, ?User $actor): ArchivematicaLink
    {
        $work = sys_get_temp_dir().'/wsd-'.bin2hex(random_bytes(6));
        mkdir($work, 0700);
        try {
            self::unpack($archive, $work);

            return $this->fromFolder($work, $target, $dipUuid, $actor);
        } finally {
            exec('rm -rf '.escapeshellarg($work));
        }
    }

    public function fromFolder(string $folder, ?RecordResource $target, ?string $dipUuid, ?User $actor): ArchivematicaLink
    {
        $metsPath = self::findMets($folder) ?? throw new InvalidArgumentException(__('There is no METS file in the package.'));
        $parsed = $this->mets->parseFile($metsPath);
        $uuid = strtolower((string) ($dipUuid ?: ($parsed['objid'] ?? ''))) ?: null;
        if ($uuid !== null && ($done = ArchivematicaLink::where('dip_uuid', $uuid)->first()) && $done->status->notation === 'linked') {
            return $done;
        }
        $unit = $target ?? $this->match($parsed);
        $link = ArchivematicaLink::updateOrCreate(['dip_uuid' => $uuid ?? 'unknown-'.bin2hex(random_bytes(8))], ['institution_id' => $unit?->institution_id,
            'record_resource_id' => $unit?->id, 'aip_uuid' => $parsed['objid'] ?? null, 'status_id' => $this->status($unit ? 'pending' : 'unmatched'), 'files_taken' => 0,
            'note' => $unit ? null : __('No unit of description matched the package.')]);
        if ($unit === null) {
            return $link->fresh();
        }
        $taken = 0;
        $missing = [];
        foreach ($parsed['access_files'] ?? [] as $access) {
            $path = self::accessFile($folder, (string) ($access['href'] ?? ''));
            if ($path === null) {
                $missing[] = (string) ($access['href'] ?? '?');

                continue;
            }
            $premis = self::premisFor($access, $parsed['premis'] ?? []);
            $name = basename((string) ($premis['original_name'] ?? '')) ?: preg_replace('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}-/i', '', basename($path));
            $sha = strtolower((string) ($access['checksum_type'] ?? '')) === 'sha256' ? ($access['checksum'] ?? null) : null;
            try {
                $instantiation = $this->instantiations->upload($unit, $path, $name, $unit->originalLanguage('title') ?? 'en', $name, $sha, 'online', $actor);
                $taken++;
                if ($master = $instantiation->file('master')) {
                    $this->events->record($master, 'metadata-extraction', 'success', __('From Archivematica DIP :dip of AIP :aip.', ['dip' => $uuid ?? '?', 'aip' => $parsed['objid'] ?? '?']),
                        collect(['original name' => $premis['original_name'] ?? null, 'PUID' => $premis['puid'] ?? null, 'format' => $premis['format_name'] ?? null,
                            ($premis['message_digest_algorithm'] ?? 'digest') => $premis['message_digest'] ?? null])->filter()->map(fn ($v, $k) => "$k: $v")->join('; ') ?: null, null, 'Archivematica');
                }
            } catch (Throwable $e) {
                Log::warning('an access file of a DIP was not kept', ['dip' => $uuid, 'file' => $name, 'error' => $e->getMessage()]);
                $missing[] = $name.': '.$e->getMessage();
            }
        }
        $link->update(['status_id' => $this->status($taken > 0 ? 'linked' : 'failed'), 'files_taken' => $taken,
            'note' => $missing === [] ? null : mb_substr(__('Not taken in: :files', ['files' => implode('; ', $missing)]), 0, 4000)]);

        return $link->fresh();
    }

    /** The unit the DIP belongs to, or null. */
    public function match(array $parsed): ?RecordResource
    {
        $uuids = collect([$parsed['objid'] ?? null, ...preg_split('/\s+/', (string) ($parsed['dublin_core']['identifier'] ?? ''))])->filter()
            ->map(fn ($v) => strtolower(trim((string) $v)))->filter(fn ($v) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v))->values()->all();
        if ($uuids !== []) {
            $sent = ArchivematicaJob::where(fn ($q) => $q->whereIn('aip_uuid', $uuids)->orWhereIn('sip_uuid', $uuids)->orWhereIn('transfer_uuid', $uuids))
                ->whereNotNull('record_resource_id')->orderByDesc('created_at')->value('record_resource_id')
                ?? ArchivematicaLink::whereIn('aip_uuid', $uuids)->whereNotNull('record_resource_id')->orderByDesc('created_at')->value('record_resource_id');
            if ($sent && ($unit = RecordResource::whereNull('deleted_at')->find($sent))) {
                return $unit;
            }
        }
        $identifier = trim(preg_split('/\r?\n/', (string) ($parsed['dublin_core']['identifier'] ?? ''))[0] ?? '');
        if ($identifier === '') {
            return null;
        }
        if ($unit = RecordResource::whereNull('deleted_at')->where('iri', $identifier)->first()) {
            return $unit;
        }
        $candidates = RecordResource::whereNull('deleted_at')->where('identifier', $identifier)->limit(2)->get();

        // An identifier two units share names neither.
        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /** Unpack an archive into a folder, refusing one that names a file outside it. */
    public static function unpack(string $archive, string $into): void
    {
        $head = (string) file_get_contents($archive, false, null, 0, 6);
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new ZipArchive;
            $zip->open($archive) === true || throw new RuntimeException(__('The package file cannot be opened.'));
            Archives::zip($zip);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                ! preg_match('#(^|/)\.\.(/|$)|^/#', (string) $zip->getNameIndex($i)) || throw new InvalidArgumentException(__('The package names a file outside itself.'));
            }
            $zip->extractTo($into) || throw new RuntimeException(__('The package file cannot be opened.'));
            $zip->close();

            return;
        }
        $seven = str_starts_with($head, "7z\xBC\xAF\x27\x1C");
        Archives::file($archive, $seven ? '7z' : 'tar');
        $list = LimitedProcess::make($seven ? '7z' : 'tar', $seven ? ['7z', 'l', '-slt', $archive] : ['tar', '-tf', $archive]);
        $list->setTimeout(600)->run();
        $list->isSuccessful() || throw new RuntimeException(__('The package file cannot be opened.'));
        $names = $seven ? array_map(fn ($l) => substr($l, 7), preg_grep('/^Path = /', explode("\n", $list->getOutput())) ?: []) : explode("\n", trim($list->getOutput()));
        foreach ($names as $name) {
            ! preg_match('#(^|/)\.\.(/|$)|^/#', trim($name)) || throw new InvalidArgumentException(__('The package names a file outside itself.'));
        }
        $process = LimitedProcess::make($seven ? '7z' : 'tar', $seven ? ['7z', 'x', '-y', '-o'.$into, $archive] : ['tar', '-C', $into, '--no-same-owner', '-xf', $archive]);
        $process->setTimeout(3600)->run();
        $process->isSuccessful() || throw new RuntimeException(__('The package file cannot be opened.').' '.mb_substr($process->getErrorOutput(), 0, 500));
    }

    /** METS.<uuid>.xml, or else METS.xml, anywhere in the folder. */
    public static function findMets(string $folder): ?string
    {
        $fallback = null;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/^METS\..+\.xml$/i', $file->getFilename())) {
                return $file->getPathname();
            }
            strcasecmp($file->getFilename(), 'METS.xml') === 0 && $fallback = $file->getPathname();
        }

        return $fallback;
    }

    /** The file an access file's FLocat names, inside the folder: as named, or Archivematica's <file-uuid>-<name>. */
    private static function accessFile(string $folder, string $href): ?string
    {
        if ($href === '' || preg_match('#(^|/)\.\.(/|$)#', $href)) {
            return null;
        }
        $root = realpath($folder);
        $direct = realpath($folder.'/'.ltrim($href, '/'));
        if ($direct !== false && is_file($direct) && str_starts_with($direct, $root.'/')) {
            return $direct;
        }
        $base = basename($href);
        foreach ([true, false] as $exact) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isLink() && ($exact ? $file->getFilename() === $base : str_ends_with($file->getFilename(), '-'.$base))) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /** The PREMIS object of an access file: by its ADMID, or its original name, or the only one there is. */
    private static function premisFor(array $access, array $premis): array
    {
        $base = basename((string) ($access['href'] ?? ''));
        foreach ($premis as $p) {
            if ((! empty($access['admid']) && ($p['admid'] ?? null) === $access['admid']) || ($base !== '' && basename((string) ($p['original_name'] ?? '')) === $base)) {
                return $p;
            }
        }

        return count($premis) === 1 ? $premis[0] : [];
    }

    private function status(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'archivematica-link-status/'.$notation)->value('id');
    }
}
