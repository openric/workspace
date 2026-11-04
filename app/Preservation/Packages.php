<?php

// OpenRiC Workspace - OAIS information packages (roadmap phase 15d, ADR 0022), after Heratio's ahg-preservation BagItService
// and OaisLifecycleService: a unit of description, with what it includes where chosen, put together as a BagIt 1.0 bag
// (RFC 8493) and serialised in one ZIP or TAR file, kept in an OCFL object of its own. A SIP holds the masters; an AIP
// the masters and preservation masters; a DIP the access copies, or else the reference copies. Each holds its unit's METS
// and PREMIS (and an AIP its RiC-O) under data/metadata. A SIP is carried into an AIP and an AIP into a DIP, each naming
// the one it came from; every package can be validated against its manifests again, downloaded and removed.
// Changed from Heratio: the bag is built in a folder of its own and only the serialised bag is kept; a file goes in only
// where the account that builds it may receive it; and the metadata is the Workspace's own METS and PREMIS exports.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Preservation;

use App\Api\JsonLd;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Exchange\ExportView;
use App\Exchange\Mets;
use App\Exchange\Premis;
use App\Files\FileStore;
use App\Http\Controllers\Api\DiscoveryController;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Package;
use App\Models\PackageEvent;
use App\Models\PackageFile;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class Packages
{
    public const BAGIT_VERSION = '1.0';

    /** The files each kind of package carries, by use, the first of a list found for each instantiation. */
    private const USES = ['sip' => [['master']], 'aip' => [['master'], ['preservation']], 'dip' => [['access', 'reference']]];

    public function __construct(private FileStore $store, private InstantiationService $instantiations, private RecordService $records) {}

    /**
     * Build a package of the unit.
     *
     * @param  array{name?: string, description?: string, format?: string, algorithm?: string, include_descendants?: bool, originator?: string,
     *     submission_agreement?: string, retention_period?: string}  $options
     */
    public function build(RecordResource $unit, string $type, User $actor, array $options = [], ?Package $parent = null): Package
    {
        $this->assertMay($actor, $unit);
        isset(self::USES[$type]) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $type]));
        $format = $options['format'] ?? 'zip';
        in_array($format, ['zip', 'tar'], true) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $format]));
        $algorithm = $options['algorithm'] ?? 'sha256';
        in_array($algorithm, ['sha256', 'sha512'], true) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $algorithm]));
        $descendants = (bool) ($options['include_descendants'] ?? true);
        $id = (string) Str::uuid7();
        $name = Str::slug(trim((string) ($options['name'] ?? '')) ?: $type.'-'.($unit->referenceCode('-') ?? $unit->id).'-'.now()->format('Ymd-His')) ?: $type.'-'.$id;
        $package = new Package(['institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id, 'name' => $name,
            'description' => trim((string) ($options['description'] ?? '')) ?: null, 'type_id' => $this->concept('oais-package-type', $type),
            'status_id' => $this->concept('package-status', 'building'), 'format_id' => $this->concept('package-format', $format), 'bagit_version' => self::BAGIT_VERSION,
            'manifest_algorithm' => $algorithm, 'include_descendants' => $descendants, 'originator' => trim((string) ($options['originator'] ?? '')) ?: null,
            'submission_agreement' => trim((string) ($options['submission_agreement'] ?? '')) ?: null, 'retention_period' => trim((string) ($options['retention_period'] ?? '')) ?: null,
            'parent_package_id' => $parent?->id, 'created_by_id' => $actor->id]);
        $package->id = $id;
        $package->save();

        $institution = Institution::findOrFail($unit->institution_id);
        $work = sys_get_temp_dir().'/wsp-'.bin2hex(random_bytes(6));
        $bag = $work.'/'.$name;
        mkdir($bag.'/data', 0700, true);
        try {
            $rows = [];
            $add = function (string $relative, string $role, ?DigitalFile $file = null) use ($bag, $algorithm, &$rows) {
                $path = $bag.'/'.$relative;
                $rows[] = ['relative_path' => $relative, 'file_name' => basename($relative), 'file_size' => (int) filesize($path), 'checksum_algorithm' => $algorithm,
                    'checksum_value' => hash_file($algorithm, $path), 'mime_type' => $file?->mime_type, 'puid' => $file?->format?->puid, 'role' => $role, 'digital_file_id' => $file?->id];
            };
            $units = collect([$unit, ...($descendants ? array_column(RecordService::flatten($this->records->hierarchy($unit, $actor), $unit->id), 0) : [])]);
            foreach ($this->payload($units, $type, $actor) as [$file, $relative]) {
                $target = $bag.'/data/'.$relative;
                is_dir(dirname($target)) || mkdir(dirname($target), 0700, true);
                copy($this->store->path($file->storage_path), $target) || throw new RuntimeException(__('The file :name could not be copied into the package.', ['name' => $file->original_name]));
                $add('data/'.$relative, 'payload', $file);
            }
            $payloadCount = count($rows);
            $payloadSize = array_sum(array_column($rows, 'file_size'));
            $payloadCount > 0 || throw new InvalidArgumentException(__('There is no file for this package: the unit has none you may receive of the kind it holds.'));
            foreach ($this->metadata($unit, $type, $actor) as $file => $content) {
                is_dir($bag.'/data/metadata') || mkdir($bag.'/data/metadata', 0700, true);
                file_put_contents($bag.'/data/metadata/'.$file, $content);
                $add('data/metadata/'.$file, 'metadata');
            }
            $manifest = "manifest-$algorithm.txt";
            file_put_contents($bag.'/'.$manifest, implode('', array_map(fn ($r) => $r['checksum_value'].'  '.self::escape($r['relative_path'])."\n", $rows)));
            file_put_contents($bag.'/bagit.txt', 'BagIt-Version: '.self::BAGIT_VERSION."\nTag-File-Character-Encoding: UTF-8\n");
            $all = array_sum(array_column($rows, 'file_size'));
            $info = array_filter(['Source-Organization' => $institution->name, 'Contact-Name' => $actor->name, 'Contact-Email' => $actor->email,
                'External-Identifier' => $unit->iri, 'External-Description' => $package->description, 'Bagging-Date' => now(config('workspace.timezone'))->toDateString(),
                'Bag-Size' => Number::fileSize($all, 1), 'Payload-Oxum' => count($rows) > 0 ? $all.'.'.count($rows) : '0.0',
                'Bag-Group-Identifier' => $parent ? 'urn:uuid:'.$parent->id : null, 'Internal-Sender-Identifier' => 'urn:uuid:'.$package->id,
                'Internal-Sender-Description' => strtoupper($type).': '.__('Payload files').' '.$payloadCount.' ('.Number::fileSize($payloadSize, 1).')',
                'Bag-Software-Agent' => PreservationEvents::workspace()]);
            file_put_contents($bag.'/bag-info.txt', implode('', array_map(fn ($k, $v) => $k.': '.str_replace(["\r", "\n"], ' ', (string) $v)."\n", array_keys($info), $info)));
            $add('bagit.txt', 'tag');
            $add('bag-info.txt', 'tag');
            $add($manifest, 'manifest');
            $tagmanifest = "tagmanifest-$algorithm.txt";
            file_put_contents($bag.'/'.$tagmanifest, implode('', array_map(fn ($f) => hash_file($algorithm, $bag.'/'.$f).'  '.$f."\n", ['bagit.txt', 'bag-info.txt', $manifest])));
            $add($tagmanifest, 'tagmanifest');

            $archive = $work.'/'.$name.'.'.$format;
            $this->serialise($work, $name, $archive, $format);
            $kept = $this->store->into($institution->base_iri.'/package/'.$package->id)->keepMade($unit->institution_id, $archive, $name.'.'.$format);
            DB::transaction(function () use ($package, $rows, $kept, $all) {
                $roles = [];
                foreach ($rows as $i => $row) {
                    $role = $row['role'];
                    unset($row['role']);
                    PackageFile::create([...$row, 'package_id' => $package->id, 'role_id' => $roles[$role] ??= $this->concept('package-object-role', $role),
                        'sequence' => $i + 1, 'created_at' => now()]);
                }
                $package->update(['status_id' => $this->concept('package-status', 'complete'), 'object_count' => count($rows), 'total_size' => $all,
                    'storage_path' => $kept['storage_path'], 'package_checksum' => $kept['sha256'], 'package_size' => $kept['size_bytes'], 'built_at' => now()]);
            });
            $this->event($package, 'creation', 'success', __(':type built as a BagIt :version bag: :count files, :size.', ['type' => strtoupper($type),
                'version' => self::BAGIT_VERSION, 'count' => count($rows), 'size' => Number::fileSize($all, 1)]), $kept['sha256'], $actor);
        } catch (Throwable $e) {
            $package->update(['status_id' => $this->concept('package-status', 'error'), 'metadata' => ['error' => mb_substr($e->getMessage(), 0, 2000)]]);
            $this->event($package, 'creation', 'failure', __('The package could not be built.'), $e->getMessage(), $actor);
            throw $e;
        } finally {
            exec('rm -rf '.escapeshellarg($work));
        }

        return $package->fresh();
    }

    /** An AIP from a SIP, or a DIP from an AIP, of the same unit and with the same choices, naming the package it came from. */
    public function derive(Package $package, User $actor): Package
    {
        $next = ['sip' => 'aip', 'aip' => 'dip'][$package->type->notation] ?? throw new InvalidArgumentException(__('A DIP is the last package: nothing is made from it.'));
        $package->record ?? throw new InvalidArgumentException(__('The unit of description of this package is gone.'));

        return $this->build($package->record, $next, $actor, ['format' => $package->format->notation, 'algorithm' => $package->manifest_algorithm,
            'include_descendants' => $package->include_descendants, 'originator' => $package->originator, 'submission_agreement' => $package->submission_agreement,
            'retention_period' => $package->retention_period, 'description' => $package->description], $package);
    }

    /**
     * Validate the kept package as a BagIt bag again (RFC 8493 section 3): its checksum as kept, bagit.txt, every payload
     * file in the manifest and every manifest line a file with its checksum, the Payload-Oxum, and the tag manifest.
     *
     * @return list<string> what is wrong; none when it is valid
     */
    public function validate(Package $package, ?User $actor = null): array
    {
        $errors = [];
        $archive = $package->storage_path ? $this->store->path($package->storage_path) : null;
        if ($archive === null || ! is_file($archive)) {
            $errors[] = __('The package file is missing.');
        } elseif (hash_file('sha256', $archive) !== $package->package_checksum) {
            $errors[] = __('The package file is not the one kept: its SHA-256 has changed.');
        }
        $work = sys_get_temp_dir().'/wsv-'.bin2hex(random_bytes(6));
        mkdir($work, 0700);
        try {
            if ($errors === []) {
                $this->unpack($archive, $work, $package->format->notation);
                $bag = $work.'/'.$package->name;
                $errors = [...$errors, ...self::checkBag($bag, $package->manifest_algorithm)];
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        } finally {
            exec('rm -rf '.escapeshellarg($work));
        }
        $package->update(['status_id' => $this->concept('package-status', $errors === [] ? 'validated' : 'error'), 'validated_at' => now(),
            'metadata' => [...($package->metadata ?? []), 'validation' => $errors]]);
        $this->event($package, 'validation', $errors === [] ? 'success' : 'failure', __('Validated as a BagIt :version bag.', ['version' => self::BAGIT_VERSION]),
            $errors === [] ? null : implode('; ', array_slice($errors, 0, 20)), $actor);

        return $errors;
    }

    /** @return list<string> what is wrong with the bag in the folder */
    public static function checkBag(string $bag, string $algorithm): array
    {
        $errors = [];
        $declaration = @file_get_contents($bag.'/bagit.txt');
        if ($declaration === false || ! preg_match('/^BagIt-Version: \d+\.\d+$/m', $declaration)) {
            return [__('There is no bagit.txt declaring the BagIt version.')];
        }
        $listed = [];
        foreach (array_filter(explode("\n", (string) @file_get_contents($bag."/manifest-$algorithm.txt"))) as $line) {
            [$sum, $path] = preg_split('/\s+/', $line, 2) + [null, null];
            $path = self::unescape((string) $path);
            $listed[$path] = true;
            if (! is_file($bag.'/'.$path)) {
                $errors[] = __(':path is in the manifest but not in the bag.', ['path' => $path]);
            } elseif (hash_file($algorithm, $bag.'/'.$path) !== $sum) {
                $errors[] = __(':path does not match its checksum.', ['path' => $path]);
            }
        }
        $listed !== [] || $errors[] = __('There is no :file, or it lists nothing.', ['file' => "manifest-$algorithm.txt"]);
        $bytes = $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($bag.'/data', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($bag) + 1);
            isset($listed[$relative]) || $errors[] = __(':path is in the bag but not in the manifest.', ['path' => $relative]);
            $bytes += $file->getSize();
            $count++;
        }
        preg_match('/^Payload-Oxum: (\d+)\.(\d+)$/m', (string) @file_get_contents($bag.'/bag-info.txt'), $oxum);
        ($oxum === [] || ((int) $oxum[1] === $bytes && (int) $oxum[2] === $count)) || $errors[] = __('The Payload-Oxum does not match the payload.');
        foreach (array_filter(explode("\n", (string) @file_get_contents($bag."/tagmanifest-$algorithm.txt"))) as $line) {
            [$sum, $path] = preg_split('/\s+/', $line, 2) + [null, null];
            (is_file($bag.'/'.$path) && hash_file($algorithm, $bag.'/'.$path) === $sum) || $errors[] = __(':path does not match its checksum.', ['path' => $path]);
        }

        return $errors;
    }

    /** The kept package file, its download marking the package exported. */
    public function download(Package $package, User $actor): string
    {
        $this->assertMay($actor, $package->record);
        $path = $package->storage_path ? $this->store->path($package->storage_path) : null;
        ($path !== null && is_file($path)) || throw new InvalidArgumentException(__('The package file is missing.'));
        $package->update(['exported_at' => now(), 'status_id' => $package->status->notation === 'error' ? $package->status_id : $this->concept('package-status', 'exported')]);

        return $path;
    }

    /** Remove the package and its OCFL object, every version of it. */
    public function remove(Package $package, User $actor): void
    {
        $this->assertMay($actor, $package->record);
        $this->purge($package);
    }

    /** Purge a package's object, as a destruction must (DisposalService), and the package with it. */
    public function purge(Package $package): void
    {
        $this->store->ocfl()->purge(DB::table('institutions')->where('id', $package->institution_id)->value('base_iri').'/package/'.$package->id);
        $package->delete();
    }

    /** @return list<array{0: DigitalFile, 1: string}> each file the package carries and its path under data/ */
    private function payload($units, string $type, User $actor): array
    {
        $out = [];
        foreach ($units as $unit) {
            $folder = 'objects/'.(Str::slug((string) $unit->referenceCode('-')) ?: $unit->id);
            foreach (Instantiation::where('record_resource_id', $unit->id)->whereNull('deleted_at')->with('files.use', 'files.format', 'files.instantiation.record', 'files.instantiation.availability')->orderBy('position')->get() as $instantiation) {
                foreach (self::USES[$type] as $uses) {
                    $file = collect($uses)->map(fn ($use) => $instantiation->files->first(fn ($f) => $f->use->notation === $use))->filter()->first();
                    if ($file && $this->instantiations->deliverable($actor, $file)) {
                        $out[] = [$file, $folder.'/'.$instantiation->id.'/'.$file->use->notation.'/'.InstantiationService::logicalName($file->original_name)];
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> the metadata files under data/metadata, by name */
    private function metadata(RecordResource $unit, string $type, User $actor): array
    {
        // A DIP is for a reader, so it carries what a reader sees; a SIP and an AIP what the builder may see.
        $view = new ExportView($actor, $type !== 'dip' && $actor->may('view-draft', 'description', $unit), $unit->originalLanguage('title'));
        $unit->loadMissing(RecordService::DESCRIBED);
        $files = ['mets.xml' => app(Mets::class)->export($unit, $this->records->hierarchy($unit, $actor), $view), 'premis.xml' => app(Premis::class)->export($unit, $view)];
        if ($type === 'aip') {
            $files['ric-o.jsonld'] = json_encode((new JsonLd($view))->dump($unit->loadMissing(DiscoveryController::RECORD_WITH)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $files;
    }

    private function serialise(string $work, string $name, string $archive, string $format): void
    {
        if ($format === 'zip') {
            $zip = new ZipArchive;
            $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || throw new RuntimeException(__('The package file could not be written.'));
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work.'/'.$name, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($work) + 1));
            }
            $zip->close() || throw new RuntimeException(__('The package file could not be written.'));

            return;
        }
        $process = new Process(['tar', '-C', $work, '-cf', $archive, $name]);
        $process->setTimeout(3600)->run();
        $process->isSuccessful() || throw new RuntimeException(__('The package file could not be written.').' '.$process->getErrorOutput());
    }

    private function unpack(string $archive, string $into, string $format): void
    {
        if ($format === 'zip') {
            $zip = new ZipArchive;
            $zip->open($archive) === true || throw new RuntimeException(__('The package file cannot be opened.'));
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // No name may climb out of the folder it is unpacked into.
                ! preg_match('#(^|/)\.\.(/|$)|^/#', (string) $zip->getNameIndex($i)) || throw new RuntimeException(__('The package names a file outside itself.'));
            }
            $zip->extractTo($into) || throw new RuntimeException(__('The package file cannot be opened.'));
            $zip->close();

            return;
        }
        $process = new Process(['tar', '-C', $into, '--no-same-owner', '-xf', $archive]);
        $process->setTimeout(3600)->run();
        $process->isSuccessful() || throw new RuntimeException(__('The package file cannot be opened.').' '.$process->getErrorOutput());
    }

    /** A path in a manifest, as RFC 8493 section 2.1.3 escapes it. */
    private static function escape(string $path): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $path);
    }

    private static function unescape(string $path): string
    {
        return str_replace(['%0D', '%0A', '%25'], ["\r", "\n", '%'], $path);
    }

    private function event(Package $package, string $type, string $outcome, string $detail, ?string $outcomeDetail, ?User $actor): void
    {
        PackageEvent::create(['package_id' => $package->id, 'event_type_id' => $this->concept('premis-event-type', $type), 'outcome_id' => $this->concept('premis-event-outcome', $outcome),
            'detail' => $detail, 'outcome_detail' => $outcomeDetail === null ? null : mb_substr($outcomeDetail, 0, 4000), 'agent_name' => $actor?->name ?? PreservationEvents::workspace(),
            'user_id' => $actor?->id, 'occurred_at' => now()]);
    }

    private function assertMay(User $actor, ?RecordResource $unit): void
    {
        ($unit !== null && $unit->institution_id === $actor->institution_id && $actor->hasPermission(Vocab::permission('manage-institution')))
            || throw new InvalidArgumentException(__('Only who administers the institution builds its information packages.'));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
