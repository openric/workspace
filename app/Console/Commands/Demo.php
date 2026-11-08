<?php

// OpenRiC Workspace - the V0.1 demonstration dataset loaded into an installed Workspace (governance/V0.1-DEMONSTRATION-
// SCENARIO.md; roadmap R1 hardening): the synthetic "Riverside Parish Council" papers, every name invented and every
// title marked synthetic, about forty units in a fonds, two series, files, items and a record part; the council, its
// clerk, a depositing family and two people named in a marriage entry; the creation of the minute books, the marriage
// and the deposit; the parish and a neighbouring town; an Afrikaans title and an item in Afrikaans only; a scan and a
// PDF, both made here; one unit closed until 2030 and the rest published; and two accounts, an archivist (editor) and a
// reader, who sign in with the password given. The browser checks scan it, and an outside installer or archivist may
// rehearse on it. It refuses to run twice.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Accounts\AccountService;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Rights\Embargoes;
use App\Search\SearchIndex;
use App\Support\Vocab;
use Illuminate\Console\Command;
use Imagick;
use ImagickPixel;
use Throwable;

class Demo extends Command
{
    protected $signature = 'workspace:demo
        {--password= : The password the demonstration accounts sign in with (at least 12 characters)}';

    protected $description = 'Load the synthetic V0.1 demonstration dataset (the Riverside Parish Council papers) into the installed Workspace';

    private const LEVEL = Vocab::BASE.'level-of-description/';

    public function handle(): int
    {
        $institution = Institution::orderBy('created_at')->first();
        if ($institution === null) {
            $this->error('Install the Workspace first: php artisan workspace:install.');

            return self::FAILURE;
        }
        if (User::where('email', 'archivist@demo.invalid')->exists()) {
            $this->error('The demonstration dataset is loaded already.');

            return self::FAILURE;
        }
        $password = (string) $this->option('password');
        if (strlen($password) < 12) {
            $this->error('Give --password, at least 12 characters, for the demonstration accounts.');

            return self::INVALID;
        }

        $accounts = app(AccountService::class);
        $archivist = $accounts->create($institution, 'Demonstration archivist', 'archivist@demo.invalid', $password);
        $accounts->grantTemplateRole($archivist, 'editor');
        $reader = $accounts->create($institution, 'Demonstration reader', 'reader@demo.invalid', $password);
        auth()->login($archivist);

        $records = app(RecordService::class);
        $agents = app(AgentService::class);
        $relations = app(RelationService::class);
        $repository = Repository::where('institution_id', $institution->id)->orderBy('created_at')->firstOrFail();
        $unit = fn (string $level, string $title, ?RecordResource $parent, string $id, string $language = 'en') => $records->create($institution, self::LEVEL.$level,
            $title, $language, $parent, $repository, $id, $archivist);

        $fonds = $unit('fonds', 'Riverside Parish Council records (synthetic demonstration)', null, 'RPC');
        $records->describe($fonds, ['title' => 'Riverside Gemeenteraad se rekords (sintetiese demonstrasie)'], 'af', $archivist);
        $records->describe($fonds, ['scope-and-content' => 'Synthetic papers made for the OpenRiC Workspace V0.1 demonstration. Every name in them is invented.',
            'conditions-governing-access' => 'Open, except where a unit says otherwise.'], 'en', $archivist);
        $minutes = $unit('series', 'Minutes of the council (synthetic)', $fonds, 'RPC/1');
        $letters = $unit('series', 'Correspondence (synthetic)', $fonds, 'RPC/2');
        $all = [$fonds, $minutes, $letters];
        foreach (range(1950, 1959) as $i => $year) {
            $file = $unit('file', "Minute book, $year (synthetic)", $minutes, 'RPC/1/'.($i + 1));
            $all[] = $file;
            foreach (['January to June', 'July to December'] as $j => $half) {
                $all[] = $unit('item', "Minutes, $half $year (synthetic)", $file, 'RPC/1/'.($i + 1).'/'.($j + 1));
            }
        }
        $page = $unit('part', 'A page of the 1952 minute book (synthetic)', $all[5], 'RPC/1/3/1/1');
        $letterFile = $unit('file', 'Letters received, 1952 (synthetic)', $letters, 'RPC/2/1');
        $marriage = $unit('item', 'Letter about the marriage of Anna Botha and Jacob Visser, 1952 (synthetic)', $letterFile, 'RPC/2/1/1');
        $afrikaans = $unit('item', 'Brief oor die watervoorsiening, 1953 (sinteties)', $letterFile, 'RPC/2/1/2', 'af');
        $closed = $unit('item', 'Letter about a pauper burial, 1954 (synthetic)', $letterFile, 'RPC/2/1/3');
        $box = $unit('item', 'Carved box that held the council seal (synthetic)', $fonds, 'RPC/3');
        array_push($all, $page, $letterFile, $marriage, $afrikaans, $closed, $box);

        $council = $agents->create($institution, 'corporate-body', 'Riverside Parish Council (synthetic)', 'en', 'RPC-AGENT', $archivist);
        $agents->describe($council, ['agent-history' => 'A synthetic council, invented for the demonstration.'], 'en', $archivist);
        $clerk = $agents->create($institution, 'person', 'Naudé, Pieter (synthetic clerk)', 'en', null, $archivist);
        $family = $agents->create($institution, 'family', 'Visser family (synthetic)', 'en', null, $archivist);
        $anna = $agents->create($institution, 'person', 'Botha, Anna (synthetic)', 'en', null, $archivist);
        $jacob = $agents->create($institution, 'person', 'Visser, Jacob (synthetic)', 'en', null, $archivist);
        $parish = app(PlaceService::class)->create($institution, 'Riverside (synthetic parish)', 'en', null, $archivist);
        $town = app(PlaceService::class)->create($institution, 'Hillcrest (synthetic town)', 'en', null, $archivist);
        $activities = app(ActivityService::class);
        $creation = $activities->create($institution, 'Keeping the minutes of the council (synthetic)', 'en', null, $archivist);
        $wedding = $activities->create($institution, 'The marriage of Anna Botha and Jacob Visser, 1952 (synthetic)', 'en', null, $archivist);
        $deposit = $activities->create($institution, 'The Visser family deposit, 2026 (synthetic)', 'en', null, $archivist);

        $relations->create($fonds, 'creation', $council, [], $archivist);
        $relations->create($minutes, 'creation', $clerk, [], $archivist);
        $relations->create($marriage, 'subject', $anna, [], $archivist);
        $relations->create($marriage, 'subject', $jacob, [], $archivist);
        $relations->create($fonds, 'subject', $parish, [], $archivist);
        $relations->create($letters, 'subject', $town, [], $archivist);
        $relations->create($creation, 'performance', $clerk, [], $archivist);
        $relations->create($creation, 'result', $minutes, [], $archivist);
        $relations->create($wedding, 'performance', $anna, [], $archivist);
        $relations->create($deposit, 'performance', $family, [], $archivist);
        $relations->create($wedding, 'performance', $jacob, [], $archivist);
        $relations->create($wedding, 'result', $marriage, [], $archivist);
        $relations->create($deposit, 'result', $fonds, [], $archivist);
        // Each activity is dated, as the Provenance & Event profile asks before it is published (phase 24).
        $dates = app(DateService::class);
        foreach ([[$creation, '1950/1959'], [$wedding, '1952'], [$deposit, '2026']] as [$activity, $when]) {
            $dates->add($activity, ['property' => 'associated', 'normalized' => $when], $archivist);
        }

        // A scan and a PDF, made here, so nothing real is in them.
        $instantiations = app(InstantiationService::class);
        $scan = tempnam(sys_get_temp_dir(), 'demo').'.tif';
        $image = new Imagick;
        $image->newImage(1200, 800, new ImagickPixel('#efe6d2'));
        $image->setImageFormat('tiff');
        $image->writeImage($scan);
        $pdf = tempnam(sys_get_temp_dir(), 'demo').'.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        // A 3D model: one triangle as binary glTF (phase 21), made here.
        $glb = tempnam(sys_get_temp_dir(), 'demo').'.glb';
        $json = json_encode(['asset' => ['version' => '2.0'], 'scenes' => [['nodes' => [0]]], 'nodes' => [['mesh' => 0]], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
            'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3', 'min' => [0, 0, 0], 'max' => [1, 1, 0]]],
            'bufferViews' => [['buffer' => 0, 'byteLength' => 36]], 'buffers' => [['byteLength' => 36]]]);
        $json = str_pad($json, (int) ceil(strlen($json) / 4) * 4, ' ');
        $bin = pack('g9', 0, 0, 0, 1, 0, 0, 1, 1, 0);
        file_put_contents($glb, 'glTF'.pack('VV', 2, 28 + strlen($json) + strlen($bin)).pack('V', strlen($json)).'JSON'.$json.pack('V', strlen($bin))."BIN\0".$bin);
        $made = [];
        try {
            foreach ([[$page, $scan, 'minute-book-page.tif', 'Scan of the page (synthetic)'], [$marriage, $pdf, 'marriage-letter.pdf', 'The letter (synthetic)'],
                [$box, $glb, 'seal-box.glb', 'A 3D model of the box (synthetic)']] as [$on, $path, $name, $title]) {
                $made[] = $instantiation = $instantiations->upload($on, $path, $name, 'en', $title, null, 'online', $archivist);
                // The viewing copies at once, not in the queue, so the dataset is complete when the command ends.
                rescue(fn () => $instantiations->makeCopies($instantiation->fresh(['files.use']), $archivist), report: false);
            }
        } finally {
            @unlink($scan);
            @unlink($pdf);
            @unlink($glb);
        }

        $publication = app(PublicationService::class);
        foreach ([...$all, $council, $clerk, $family, $anna, $jacob, $parish, $town, $creation, $wedding, $deposit, $repository, ...$made] as $entity) {
            $publication->apply($entity, 'publish', null, false, $archivist);
        }
        try {
            app(Embargoes::class)->impose($closed, ['type' => 'full', 'reason' => 'privacy', 'ends_on' => '2030-12-31',
                'public_message' => 'Closed until 2030 under an access restriction (synthetic).'], false, $archivist);
        } catch (Throwable $e) {
            $this->warn('The unit closed until 2030 could not be closed: '.$e->getMessage());
        }
        app(SearchIndex::class)->flush();

        $this->info(sprintf('Loaded the demonstration dataset: %d units, 5 authority records, 2 places, 3 activities and 3 files.', count($all)));
        $this->line('Accounts: archivist@demo.invalid (editor) and reader@demo.invalid, with the password given.');

        return self::SUCCESS;
    }
}
