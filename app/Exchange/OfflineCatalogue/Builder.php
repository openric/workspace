<?php

// OpenRiC Workspace - an offline catalogue built (roadmap phase 11c), after Heratio's ahg-portable-export
// BundleWorkerCommand: the descriptions in scope that the package's reader may read, their authority records,
// repositories and files, written into a folder with a viewer that opens from a USB stick with no server, the RiC-O of
// every entity, the checksum of every file and a page and script to check them, what was withheld and why, and what
// the package leaves out; zipped and kept in the object store.
// Changed from Heratio: the data is the Workspace's own, read through ExportView, so a read-only or editable package
// holds what the public may read (published descriptions, the files the public may receive, never a master) and an
// archive what its maker may; the viewer loads its data as scripts, since a browser refuses fetch() on a page opened
// from disk, and verify.html reads the folder the reader picks for the same reason; an editable package saves the
// reader's work as PSIS's researcher exchange file (format 1.0), which the Workspace imports as a draft submission;
// the README and manual have no divider lines.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Exchange\OfflineCatalogue;

use App\Api\JsonLd;
use App\Description\Elements;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Exchange\ExportLoss;
use App\Exchange\ExportView;
use App\Files\FileStore;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\OfflineCatalogue;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Research\RightsPolicies;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use ZipArchive;

class Builder
{
    public const STATUS = Vocab::BASE.'job-status/';

    public const MODES = Vocab::BASE.'offline-catalogue-mode/';

    /** Files a package carries by default; masters only in an archive that asks for them. */
    private const USES = ['thumbnail' => 'thumbnail', 'reference' => 'reference', 'access' => 'reference'];

    private const MASTER_USES = ['master' => 'master', 'preservation' => 'master'];

    private string $work;

    /** @var list<array{path: string, sha256: string, size: int}> */
    private array $fixity = [];

    /** @var array<string, int> why files and descriptions were left out => how many */
    private array $withheld = [];

    public function __construct(private FileStore $store, private InstantiationService $instantiations, private RightsPolicies $rights, private ExportLoss $loss) {}

    /** The account a package is read as: none (the public) for read-only and editable, its maker for an archive. */
    public static function reader(OfflineCatalogue $catalogue): ?User
    {
        return $catalogue->mode->notation === 'archive' ? $catalogue->user : null;
    }

    public static function view(OfflineCatalogue $catalogue): ExportView
    {
        $institution = Institution::findOrFail($catalogue->institution_id);
        $reader = self::reader($catalogue);

        return new ExportView($reader, $reader?->mayAnywhere('view-draft', 'description') ?? false, $catalogue->language, $institution);
    }

    /** @return array<string, int|string> what was built */
    public function build(OfflineCatalogue $catalogue): array
    {
        $catalogue->loadMissing('mode', 'user');
        $catalogue->update(['status_id' => $this->status('running'), 'started_at' => now(), 'error' => null]);
        $this->work = sys_get_temp_dir().'/wso-'.bin2hex(random_bytes(6));
        [$this->fixity, $this->withheld] = [[], []];
        try {
            foreach (['data', 'assets'] as $dir) {
                mkdir($this->work.'/'.$dir, 0700, true);
            }
            $stats = $this->write($catalogue);
            $zip = $this->work.'.zip';
            $this->zip($zip);
            $kept = $this->store->into($catalogue->objectId())->keepMade($catalogue->institution_id, $zip, 'offline-catalogue-'.now()->format('Ymd-His').'.zip');
            $catalogue->update(['status_id' => $this->status('completed'), 'storage_path' => $kept['storage_path'], 'size_bytes' => $kept['size_bytes'],
                'sha256' => $kept['sha256'], 'finished_at' => now()] + $stats);

            return $stats + ['size_bytes' => $kept['size_bytes']];
        } catch (Throwable $e) {
            $catalogue->update(['status_id' => $this->status('failed'), 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            throw $e;
        } finally {
            exec('rm -rf '.escapeshellarg($this->work).' '.escapeshellarg($this->work.'.zip'));
        }
    }

    /** The units in scope, those the reader may not read left out and counted. */
    public function units(OfflineCatalogue $catalogue, ExportView $view): Collection
    {
        $query = RecordResource::where('institution_id', $catalogue->institution_id)->whereNull('deleted_at');
        if ($catalogue->record_resource_id) {
            $top = RecordResource::findOrFail($catalogue->record_resource_id);
            $query->whereKey([$top->id, ...$top->descendantIds()]);
        } elseif ($catalogue->repository_id) {
            $query->where('repository_id', $catalogue->repository_id);
        } elseif (is_array($catalogue->scope_items)) {
            $query->whereKey($catalogue->scope_items);
        }
        $units = collect();
        $query->orderBy('position')->orderBy('id')->chunk(200, function ($chunk) use ($view, &$units) {
            foreach ($chunk as $unit) {
                $view->readable($unit) ? $units->push($unit) : $this->withhold('descriptions not readable as the package is read (unpublished, embargoed or restricted)');
            }
        });

        return $units;
    }

    private function write(OfflineCatalogue $catalogue): array
    {
        $view = self::view($catalogue);
        $reader = self::reader($catalogue);
        $institution = Institution::findOrFail($catalogue->institution_id);
        $units = $this->units($catalogue, $view)->each(fn (RecordResource $u) => $u->loadMissing([...RecordService::DESCRIBED, 'level.labels', 'dates', 'instantiations.files.use']));
        $ids = $units->pluck('id')->flip();
        $lang = array_values(array_filter([$catalogue->language, 'en', 'und']));
        $ld = new JsonLd($view);

        $agents = $repositories = [];
        $records = $units->map(function (RecordResource $unit) use ($view, $ids, $lang, $reader, $catalogue, &$agents, &$repositories) {
            $creators = $view->creators($unit)->map(function ($relation) use (&$agents, &$repositories, $view) {
                $other = $relation->other;
                $other instanceof Agent ? $agents[$other->id] = $other : ($other instanceof Repository ? $repositories[$other->id] = $other : null);

                return ['id' => $other->id, 'name' => $view->label($other)];
            })->values()->all();
            if ($unit->repository && $view->readable($unit->repository)) {
                $repositories[$unit->repository->id] = $unit->repository;
            }

            return [
                'id' => $unit->id, 'iri' => $unit->iri, 'parent' => isset($ids[$unit->parent_id]) ? $unit->parent_id : null,
                'ref' => $unit->referenceCode(), 'title' => $view->label($unit) ?? __('Untitled'), 'level' => $unit->level?->label($lang),
                'dates' => $unit->dates->map(fn ($d) => $d->display())->filter()->values()->all(),
                'fields' => $this->fields($unit, Elements::ISAD_G, $view, $lang, ['title']),
                'creators' => $creators, 'repository' => $unit->repository_id,
                'files' => $catalogue->include_files ? $this->files($unit, $reader, $catalogue) : [],
            ];
        })->values()->all();

        $agentRows = collect($agents)->filter(fn (Agent $a) => $view->readable($a))->map(fn (Agent $a) => ['id' => $a->id, 'iri' => $a->iri, 'name' => $view->label($a),
            'fields' => $this->fields($a, Elements::ISAAR_CPF, $view, $lang, ['agent-authorised-name'])])->values()->all();
        $repositoryRows = collect($repositories)->map(fn (Repository $r) => ['id' => $r->id, 'iri' => $r->iri, 'name' => $view->label($r),
            'fields' => $this->fields($r, Elements::ISDIAH, $view, $lang, ['authorised-name'])])->values()->all();

        // The RiC-O of every entity, as the API gives it to the same reader.
        $graph = [...$units->map(fn ($u) => $ld->record($u, false))->all(), ...collect($agents)->filter(fn ($a) => $view->readable($a))->map(fn ($a) => $ld->agent($a, false))->values()->all(),
            ...collect($repositories)->map(fn ($r) => $ld->repository($r, false))->values()->all()];
        $jsonld = json_encode(['@context' => JsonLd::CONTEXT, '@graph' => $graph], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->put('data/catalogue.jsonld', $jsonld);

        $mode = $catalogue->mode->notation;
        $data = ['title' => $catalogue->title, 'institution' => $institution->name, 'mode' => $mode, 'generated' => now()->toIso8601String(), 'language' => $catalogue->language,
            'records' => $records, 'agents' => $agentRows, 'repositories' => $repositoryRows,
            'sync' => ['package_id' => $catalogue->id, 'title' => $catalogue->title, 'institution' => $institution->name]];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $this->put('data/catalogue.json', $json);
        // The viewer reads its data as a script: a page opened from disk may load scripts beside it, never fetch them.
        $this->put('data/catalogue.js', 'window.CATALOGUE = '.$json.";\n");

        $stats = ['record_count' => count($records), 'agent_count' => count($agentRows), 'file_count' => count($this->fixity)];
        // What the package does not carry of each unit, read from what it holds (phase 23).
        $this->put('left-out.csv', ExportLoss::csv($this->loss->ofEach($json.$jsonld, $units, $view)));
        $this->put('data/disclosure-summary.json', json_encode(['read_as' => $reader ? 'the account that made it' : 'the public',
            'withheld' => $this->withheld], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->put('data/manifest.json', json_encode(['title' => $catalogue->title, 'institution' => $institution->name, 'institution_iri' => $institution->base_iri,
            'mode' => $mode, 'language' => $catalogue->language, 'generated' => now()->toIso8601String(), 'package' => $catalogue->id,
            'scope' => $catalogue->record_resource_id ? ['unit' => $catalogue->record?->iri] : ($catalogue->repository_id ? ['repository' => $catalogue->repository?->iri]
                : (is_array($catalogue->scope_items) ? ['clipboard' => count($catalogue->scope_items)] : 'everything')),
            'counts' => $stats, 'ric_o' => 'data/catalogue.jsonld', 'workspace_version' => trim((string) @file_get_contents(base_path('VERSION')))],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->viewer($catalogue, $stats);
        $this->texts($catalogue, $institution, $stats);
        $this->authenticity();

        return $stats + ['withheld_count' => array_sum($this->withheld)];
    }

    /** @return list<array{0: string, 1: string, 2: string}> label, value and language of each element the view may read, in the standard's order */
    private function fields($entity, string $standard, ExportView $view, array $lang, array $skip): array
    {
        $fields = [];
        foreach (Elements::of($standard) as $element) {
            if (in_array($element->notation, $skip, true)) {
                continue;
            }
            foreach ($view->values($entity, $element->notation) as $value) {
                $fields[] = [(string) $element->label($lang), (string) $value->value, (string) $value->language];
            }
        }

        return $fields;
    }

    /** The unit's files the package's reader may receive and reproduce, copied in; the rest counted as withheld. */
    private function files(RecordResource $unit, ?User $reader, OfflineCatalogue $catalogue): array
    {
        $out = [];
        $uses = self::USES + ($catalogue->include_masters && $reader !== null ? self::MASTER_USES : []);
        foreach ($unit->instantiations->whereNull('deleted_at') as $instantiation) {
            foreach ($instantiation->files as $file) {
                $file->setRelation('instantiation', $instantiation->setRelation('record', $unit));
                $use = $uses[$file->use->notation] ?? null;
                if ($use === null) {
                    $this->withhold(isset(self::MASTER_USES[$file->use->notation]) ? 'masters not included (only an archive may carry them)' : 'files of another use');

                    continue;
                }
                if (! $this->instantiations->deliverable($reader, $file)) {
                    $this->withhold('files the reader may not receive (rights, embargo, clearance or screening)');

                    continue;
                }
                [$permitted] = $this->rights->evaluate($reader, $unit, 'reproduce');
                $source = $this->instantiations->path($file);
                if (! $permitted || ! is_file($source)) {
                    $this->withhold($permitted ? 'files missing from the store' : 'files the rights policies do not let be reproduced');

                    continue;
                }
                $path = 'assets/'.$use.'/'.$file->id.'/'.$this->safeName($file->original_name);
                @mkdir(dirname($this->work.'/'.$path), 0700, true);
                copy($source, $this->work.'/'.$path) || throw new RuntimeException(__('A file could not be copied into the package.'));
                $this->fixity[] = ['path' => $path, 'sha256' => hash_file('sha256', $this->work.'/'.$path), 'size' => (int) filesize($this->work.'/'.$path)];
                $out[] = ['path' => $path, 'name' => $file->original_name, 'use' => $use, 'mime' => $file->mime_type, 'size' => (int) $file->size_bytes];
            }
        }

        return $out;
    }

    private function viewer(OfflineCatalogue $catalogue, array $stats): void
    {
        $dir = resource_path('offline-catalogue');
        // A package that holds a 3D model shows it with model-viewer, written into the page: a browser refuses to load
        // a module script from disk, never one inline (roadmap phase 21).
        $models = collect($this->fixity)->contains(fn ($f) => str_ends_with($f['path'], '.glb'));
        $html = strtr((string) file_get_contents($dir.'/index.html'), [
            '{{model_viewer}}' => $models ? '<script type="module">'."\n".file_get_contents(public_path('vendor/model-viewer/model-viewer.min.js'))."\n</script>" : '',
            '{{title}}' => e($catalogue->title),
            '{{counts}}' => e(__(':records descriptions, :agents authority records, :files files', ['records' => $stats['record_count'], 'agents' => $stats['agent_count'], 'files' => $stats['file_count']])),
        ]);
        $this->put('index.html', $html);
        foreach (['viewer.js', 'viewer.css', 'verify.html', 'verify.sh'] as $asset) {
            $this->put($asset, (string) file_get_contents($dir.'/'.$asset));
        }
        @chmod($this->work.'/verify.sh', 0755);
    }

    private function texts(OfflineCatalogue $catalogue, Institution $institution, array $stats): void
    {
        $mode = $catalogue->mode->notation;
        $withheld = collect($this->withheld)->map(fn ($n, $why) => "  - $n $why")->join("\n") ?: '  Nothing in scope was left out.';
        $readAs = $mode === 'archive'
            ? "It holds what the account that made it may read, drafts included where it may see them,\nand master files where it may download them. It is for the institution's staff only."
            : "It holds what the public may read: published descriptions and the files the public may\nreceive. It never holds a master file.";
        $editable = $mode === 'editable' ? "\nYou may also write notes, sources, suggested corrections and files about any record\nwhile offline, and bring them back. USER-MANUAL.txt explains how.\n" : '';
        $year = now()->year;
        $generated = now(config('workspace.timezone'))->format('j F Y H:i');
        $this->put('README.txt', <<<TXT
{$catalogue->title}
An offline catalogue of {$institution->name}

Made on {$generated}: {$stats['record_count']} descriptions, {$stats['agent_count']} authority records and {$stats['file_count']} files.

HOW TO OPEN IT

Unzip the package, keeping every file and folder together, and double-click index.html.
It opens in any recent browser (Chrome, Edge, Firefox, Safari) with no internet, server
or installation, from a USB stick, a disc or a reading-room computer. Choose a description
on the left to see it, its files and who created it.
{$editable}
WHAT IS INSIDE

  index.html, viewer.js, viewer.css   The viewer.
  data/catalogue.js                   The descriptions, as the viewer reads them.
  data/catalogue.json                 The same, for other programs.
  data/catalogue.jsonld               Every description, authority record and repository in
                                      RiC-O 1.1 (JSON-LD), with its persistent IRI, as the
                                      Workspace's API gives it.
  data/manifest.json                  What the package is: its scope, mode, counts and date.
  data/disclosure-summary.json        What was left out, and why.
  data/fixity.json, SHA256SUMS        The SHA-256 of every file.
  left-out.csv                        What of each description the package does not carry.
  assets/                             The files.
  verify.html, verify.sh              Check that nothing was changed.

WHAT IT HOLDS

{$readAs}

Left out:
{$withheld}

Handle the package as the access conditions of {$institution->name} require. Copyright,
donor agreements and community protocols may limit passing it on.

CHECKING THAT NOTHING WAS CHANGED

Open verify.html and choose this folder, or run  sh verify.sh  here (Linux or macOS).
Both compare every file with its SHA-256 and say which, if any, differ.

Made with the OpenRiC Workspace (https://openric.org), free software under the GNU AGPL.
The descriptions and files belong to {$institution->name} and their rights holders. {$year}.

TXT);
        if ($mode === 'editable') {
            $this->put('USER-MANUAL.txt', <<<TXT
{$catalogue->title}
Working offline: the researcher's manual

1. Opening the package

Double-click index.html. Keep the whole folder together if you copy it to a USB stick.
Choose a record on the left to see its details and files.

2. What you can add

Under each record, "Your work on this record" has four tabs.

  Notes        Your research notes on the record. Click "Save note".
  Sources      References you want to attach: title, author, year and a URL or shelf mark.
  Suggestions  Corrections or additions to the description: name the field (for example
               Title, Dates or Scope and content) and write your text. These are proposals;
               an archivist reviews them before anything changes in the catalogue.
  Files        Photographs or documents you gather. Keep them small: they are kept in your
               browser and written into the file you bring back.

What you add is kept in this browser on this computer. The bar at the bottom counts it.
Work in one browser on one computer for each package, so everything ends up in one file.

3. Saving your work

Click "Save for return" in the bar at the bottom. Your browser downloads
researcher-exchange.json, which holds all your notes, sources, suggestions and files.
"Clear" removes everything you added in this package; it cannot be undone.

4. Bringing it back

Sign in to the Workspace of {$institution->name}, open Research, then Submissions, and
import researcher-exchange.json (up to 50 MB). It becomes a draft submission of yours:
each note, source and suggestion a note on its record, each file a described file. You
check it and send it for review. Nothing changes in the catalogue until an archivist
approves it.

TXT);
        }
    }

    /** SHA256SUMS and data/fixity.json (and fixity.js, which verify.html reads from disk) over every file in assets/. */
    private function authenticity(): void
    {
        $this->put('SHA256SUMS', implode('', array_map(fn ($f) => $f['sha256'].'  '.$f['path']."\n", $this->fixity)));
        $fixity = json_encode(['algorithm' => 'sha256', 'generated' => now()->toIso8601String(), 'file_count' => count($this->fixity), 'files' => $this->fixity],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->put('data/fixity.json', $fixity);
        $this->put('data/fixity.js', 'window.FIXITY = '.$fixity.";\n");
    }

    private function zip(string $to): void
    {
        $zip = new ZipArchive;
        $zip->open($to, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || throw new RuntimeException(__('The package file could not be written.'));
        $base = strlen($this->work) + 1;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), $base));
        }
        $zip->close();
    }

    private function put(string $path, string $content): void
    {
        file_put_contents($this->work.'/'.$path, $content);
    }

    private function withhold(string $why): void
    {
        $this->withheld[$why] = ($this->withheld[$why] ?? 0) + 1;
    }

    private function safeName(string $name): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($name)), '-.') ?: 'file';
    }

    private function status(string $notation): string
    {
        return Concept::where('iri', self::STATUS.$notation)->value('id') ?? throw new RuntimeException("job status $notation is not loaded");
    }
}
