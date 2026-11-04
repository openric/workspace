<?php

// OpenRiC Workspace - the Workspace's RiC-O checked against the RiC-O converter of the Archives nationales de France and
// Sparna (ArchivesNationalesFR/rico-converter, CeCILL-B), the reference conversion of EAD to RiC-O (roadmap step 12c).
// The Workspace exports a fonds as EAD 2002, the converter turns that EAD into RiC-O, and each unit's two graphs are
// compared by the properties they use. Every difference is either fixed or written down as a choice, with its reason,
// in tests/Fixtures/rico-converter-choices.txt; one that is neither fails the test.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A development check: it runs only where RICO_CONVERTER_DIR names an unpacked converter release (3.0.0 or later) and
// Java is installed, so CI, which has neither, skips it.

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Api\Rdf;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\Ead;
use App\Exchange\ExportView;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Vocabulary\SkosImporter;
use EasyRdf\Graph;
use EasyRdf\RdfNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RicoConverterComparisonTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private const RICO = 'https://www.ica.org/standards/RiC/ontology#';

    public function test_the_workspace_and_the_converter_say_the_same_of_each_unit_or_the_difference_is_a_recorded_choice(): void
    {
        $converter = (string) getenv('RICO_CONVERTER_DIR');
        if ($converter === '' || ! glob($converter.'/ricoconverter-cli-*-onejar.jar')) {
            $this->markTestSkipped('Set RICO_CONVERTER_DIR to an unpacked RiC-O converter release to run this check.');
        }
        [$fonds, $units, $editor] = $this->fonds();
        $work = sys_get_temp_dir().'/rico-compare-'.bin2hex(random_bytes(4));
        mkdir("$work/in", 0777, true);
        // The converter reads EAD 2002 as the DTD writes it, with no namespace, as the Archives nationales' finding aids are;
        // the Workspace writes the schema's namespace, which is taken off here and changes nothing else.
        $ead = app(Ead::class)->export($fonds->fresh(), new ExportView($editor, false, null, Institution::sole()));
        file_put_contents("$work/in/fonds.xml", preg_replace(['/\sxmlns="urn:isbn:1-931666-22-9"/', '/\sxsi:schemaLocation="[^"]*"/'], '', $ead));
        file_put_contents("$work/run.properties", "input=$work/in\noutput=$work/out\nerror=$work/err\nxslt=$converter/xslt_ead/main.xslt\n");
        $jar = basename(glob($converter.'/ricoconverter-cli-*-onejar.jar')[0]);
        // The converter reads its stylesheets relative to its own folder, so it runs from there.
        (new Process(['java', '-jar', $jar, 'convert_ead', "@$work/run.properties"], $converter, null, null, 300))->mustRun();
        RdfNamespace::set('rico', self::RICO);
        $converted = new Graph;
        foreach (glob("$work/out/*.rdf") as $file) {
            $converted->parse(file_get_contents($file), 'rdfxml');
        }

        if (getenv('RICO_COMPARE_KEEP')) {
            fwrite(STDERR, "kept in $work\n");
        }
        $differences = [];
        foreach ($units as $unit) {
            $title = (string) $unit->described('title', ['en']);
            $theirs = $this->byTitle($converted, $title) ?? $this->fail("The converter gave no unit titled \"$title\". It gave: ".implode('; ', array_map(fn ($r) => (string) $r->get('rico:title'), $converted->resourcesMatching('rico:title'))));
            $ours = new Graph;
            $ours->parse(Rdf::serialise($this->get(route('api.record', $unit->id))->assertOk()->json(), 'turtle'), 'turtle');
            $mine = $ours->resource($unit->iri);
            [$a, $b] = [$this->properties($theirs), $this->properties($mine)];
            foreach (array_diff($a, $b) as $p) {
                $differences["only-converter $p"][] = $title;
            }
            foreach (array_diff($b, $a) as $p) {
                $differences["only-workspace $p"][] = $title;
            }
        }
        getenv('RICO_COMPARE_KEEP') || exec('rm -rf '.escapeshellarg($work));
        $choices = collect(file(base_path('tests/Fixtures/rico-converter-choices.txt')))->map(fn ($l) => trim($l))
            ->reject(fn ($l) => $l === '' || str_starts_with($l, '#'))->map(fn ($l) => implode(' ', array_slice(preg_split('/\s+/', $l), 0, 2)))->all();
        $unexplained = array_diff_key($differences, array_flip($choices));
        ksort($unexplained);
        $this->assertSame([], array_map(fn ($titles) => implode('; ', $titles), $unexplained),
            'Differences from the RiC-O converter that are neither fixed nor recorded in tests/Fixtures/rico-converter-choices.txt');
    }

    /** A fonds of two series, the first with two items in order, one with a digital and one with a paper instantiation. */
    private function fonds(): array
    {
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($institution);
        $editor = app(AccountService::class)->create($institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($editor, 'editor');
        $records = app(RecordService::class);
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $fonds = $records->create($institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $records->describe($fonds, ['scope-and-content' => 'Minutes and correspondence of the council.', 'extent' => '3 boxes'], 'en');
        app(DateService::class)->add($fonds, ['property' => 'creation', 'normalized' => '1900/1950', 'expressed' => '1900-1950', 'language' => 'en']);
        $minutes = $records->create($institution, self::LEVEL.'series', 'Minutes', 'en', $fonds, null, 'A');
        $letters = $records->create($institution, self::LEVEL.'series', 'Correspondence', 'en', $fonds, null, 'B');
        $first = $records->create($institution, self::LEVEL.'item', 'Minute book, 1900 to 1910', 'en', $minutes, null, '1');
        $second = $records->create($institution, self::LEVEL.'item', 'Minute book, 1911 to 1920', 'en', $minutes, null, '2');
        app(DateService::class)->add($first, ['property' => 'creation', 'normalized' => '1900/1910', 'expressed' => '1900-1910', 'language' => 'en']);
        $scan = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($scan, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        app(InstantiationService::class)->upload($first, $scan, 'book.png', 'en', 'Scan of the minute book', null, 'online', $editor);
        app(InstantiationService::class)->create($second, 'The bound volume', 'en', 'paper', 'online');
        $units = [$fonds, $minutes, $letters, $first, $second];
        foreach ([$holder, ...$units, ...RecordResource::find($first->id)->instantiations, ...RecordResource::find($second->id)->instantiations] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $editor);
        }

        return [$fonds, array_map(fn ($u) => $u->fresh(), $units), $editor];
    }

    private function byTitle(Graph $graph, string $title): ?\EasyRdf\Resource
    {
        foreach ($graph->resourcesMatching('rico:title') as $resource) {
            foreach ($resource->all('rico:title') as $literal) {
                // The unit, not the finding aid the converter also makes of the same title (it describes the unit).
                if ((string) $literal === $title && ! $resource->hasProperty('rico:describesOrDescribed')
                    && array_intersect(array_map('strval', $resource->types()), ['rico:RecordSet', 'rico:Record', 'rico:RecordResource', 'rico:RecordPart'])) {
                    return $resource;
                }
            }
        }

        return null;
    }

    /** The RiC-O properties a unit uses, prefixed. @return list<string> */
    private function properties(\EasyRdf\Resource $resource): array
    {
        return array_values(array_filter(array_map(fn ($p) => str_replace(self::RICO, 'rico:', $p), $resource->propertyUris()), fn ($p) => str_starts_with($p, 'rico:')));
    }
}
