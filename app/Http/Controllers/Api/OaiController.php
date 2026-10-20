<?php

// OpenRiC Workspace - OAI-PMH 2.0 harvesting of the published units of description (Export-Only 0.9.0).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The six verbs, by GET or POST, with the protocol's own error envelope. A
// record is a unit of description the public may read (PublicCatalogue); its
// datestamp is the latest change to it or its parts, to the second (UTC). It
// is given as oai_dc (App\Exchange\DublinCore) or rico_ld, the RiC-O JSON-LD
// of the export endpoint in a CDATA section, inside the same wrapper element
// the OpenRiC reference service uses so the XML stays one element. Sets are
// hierarchical: "records" holds every unit, "records:{UUID}" a fonds and the
// units inside it. Lists come 100 at a time with a resumption token.
// ponytail: deletedRecord is "no" (Export-Only 9 Q3); deleted units are kept
// with deleted_at and could be served as tombstones ("persistent") later.

namespace App\Http\Controllers\Api;

use App\Api\JsonLd;
use App\Api\PublicCatalogue;
use App\Description\RecordService;
use App\Exchange\DublinCore;
use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\RecordResource;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use XMLWriter;

class OaiController extends Controller
{
    private const PAGE = 100;

    public const FORMATS = [
        'oai_dc' => ['http://www.openarchives.org/OAI/2.0/oai_dc.xsd', 'http://www.openarchives.org/OAI/2.0/oai_dc/'],
        'rico_ld' => ['https://www.ica.org/standards/RiC/ontology', 'https://www.ica.org/standards/RiC/ontology#'],
    ];

    /** verb => [required arguments, optional arguments]; a resumption token stands alone. */
    private const VERBS = [
        'Identify' => [[], []],
        'ListMetadataFormats' => [[], ['identifier']],
        'ListSets' => [[], ['resumptionToken']],
        'ListIdentifiers' => [['metadataPrefix'], ['from', 'until', 'set', 'resumptionToken']],
        'ListRecords' => [['metadataPrefix'], ['from', 'until', 'set', 'resumptionToken']],
        'GetRecord' => [['identifier', 'metadataPrefix'], []],
    ];

    private PublicCatalogue $catalogue;

    private XMLWriter $x;

    private array $args = [];

    public function handle(Request $request): Response
    {
        $this->catalogue = new PublicCatalogue($request->attributes->get('institution'));
        // Read the arguments as sent, so a repeated one can be refused (OAI-PMH 3.6, badArgument).
        $raw = $request->isMethod('post') ? $request->getContent() : (string) $request->server('QUERY_STRING');
        $pairs = [];
        foreach (array_filter(explode('&', $raw), fn ($p) => $p !== '') as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [urldecode($name), urldecode($value)];
        }
        $names = array_column($pairs, 0);
        $this->args = array_column($pairs, 1, 0);
        $verb = $this->args['verb'] ?? null;
        if ($verb === null || ! isset(self::VERBS[$verb]) || count(array_keys($names, 'verb', true)) > 1) {
            return $this->error('badVerb', $verb === null ? 'No verb was given.' : (isset(self::VERBS[$verb]) ? 'The verb was given more than once.' : "Unknown verb: $verb"));
        }
        [$required, $optional] = self::VERBS[$verb];
        $given = array_values(array_diff($names, ['verb']));
        if (count($given) !== count(array_unique($given))) {
            return $this->error('badArgument', 'An argument was given more than once.');
        }
        if (isset($this->args['resumptionToken'])) {
            if ($given !== ['resumptionToken']) {
                return $this->error('badArgument', 'A resumptionToken is the only argument it may come with.');
            }
        } elseif ($missing = array_diff($required, $given)) {
            return $this->error('badArgument', 'Missing: '.implode(', ', $missing).'.');
        }
        if ($unknown = array_diff($given, [...$required, ...$optional])) {
            return $this->error('badArgument', 'Not an argument of '.$verb.': '.implode(', ', $unknown).'.');
        }

        return $this->{lcfirst($verb)}();
    }

    private function identify(): Response
    {
        $institution = $this->catalogue->institution;
        $records = $this->catalogue->visible('records');
        $earliest = collect($this->catalogue->datestamps($records->pluck('id')->all()))->min() ?? CarbonImmutable::parse($institution->created_at)->utc();
        $this->open(true);
        $this->x->startElement('Identify');
        $this->x->writeElement('repositoryName', $institution->name);
        $this->x->writeElement('baseURL', route('api.oai'));
        $this->x->writeElement('protocolVersion', '2.0');
        $this->x->writeElement('adminEmail', $this->adminEmail($institution));
        $this->x->writeElement('earliestDatestamp', $this->stamp($earliest));
        $this->x->writeElement('deletedRecord', 'no');
        $this->x->writeElement('granularity', 'YYYY-MM-DDThh:mm:ssZ');
        $this->x->startElement('description');
        $this->x->startElement('oai-identifier');
        $this->x->writeAttribute('xmlns', 'http://www.openarchives.org/OAI/2.0/oai-identifier');
        $this->x->writeAttribute('xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/oai-identifier http://www.openarchives.org/OAI/2.0/oai-identifier.xsd');
        $this->x->writeElement('scheme', 'oai');
        $this->x->writeElement('repositoryIdentifier', $this->host());
        $this->x->writeElement('delimiter', ':');
        $this->x->writeElement('sampleIdentifier', 'oai:'.$this->host().':01a15943-208d-7173-8f10-c48f559f3f37');
        $this->x->endElement();
        $this->x->endElement();
        $this->x->endElement();

        return $this->close();
    }

    private function listMetadataFormats(): Response
    {
        if (isset($this->args['identifier']) && $this->unit($this->args['identifier']) === null) {
            return $this->error('idDoesNotExist', 'No published record has that identifier.');
        }
        $this->open(true);
        $this->x->startElement('ListMetadataFormats');
        foreach (self::FORMATS as $prefix => [$schema, $namespace]) {
            $this->x->startElement('metadataFormat');
            $this->x->writeElement('metadataPrefix', $prefix);
            $this->x->writeElement('schema', $schema);
            $this->x->writeElement('metadataNamespace', $namespace);
            $this->x->endElement();
        }
        $this->x->endElement();

        return $this->close();
    }

    private function listSets(): Response
    {
        if (isset($this->args['resumptionToken'])) {
            return $this->error('badResumptionToken', 'Sets come in one list, so there is no token to resume.');
        }
        $tops = $this->catalogue->visible('records')->whereNull('parent_id');
        $this->open(true);
        $this->x->startElement('ListSets');
        $this->set('records', 'Every published unit of description');
        foreach ($tops as $top) {
            $this->set('records:'.$top->id, (string) $this->catalogue->view->label($top));
        }
        $this->x->endElement();

        return $this->close();
    }

    private function listIdentifiers(): Response
    {
        return $this->list(false);
    }

    private function listRecords(): Response
    {
        return $this->list(true);
    }

    private function getRecord(): Response
    {
        if (! isset(self::FORMATS[$this->args['metadataPrefix']])) {
            return $this->error('cannotDisseminateFormat', 'The formats are oai_dc and rico_ld.');
        }
        $unit = $this->unit($this->args['identifier']);
        if ($unit === null) {
            return $this->error('idDoesNotExist', 'No published record has that identifier.');
        }
        $this->open(true);
        $this->x->startElement('GetRecord');
        $this->record($unit, $this->catalogue->datestamps([$unit->id])[$unit->id], $this->tops([$unit])[$unit->id], $this->args['metadataPrefix'], true);
        $this->x->endElement();

        return $this->close();
    }

    /** ListIdentifiers or ListRecords: the selection, a page of it, and a token for the rest. */
    private function list(bool $withMetadata): Response
    {
        $state = isset($this->args['resumptionToken']) ? $this->token($this->args['resumptionToken'])
            : ['p' => $this->args['metadataPrefix'], 'f' => $this->args['from'] ?? null, 'u' => $this->args['until'] ?? null, 's' => $this->args['set'] ?? null, 'o' => 0];
        if ($state === null) {
            return $this->error('badResumptionToken', 'The resumption token is not one this repository gave.');
        }
        if (! isset(self::FORMATS[$state['p']])) {
            return $this->error('cannotDisseminateFormat', 'The formats are oai_dc and rico_ld.');
        }
        [$from, $until, $problem] = $this->range($state['f'], $state['u']);
        if ($problem !== null) {
            return $this->error('badArgument', $problem);
        }
        $units = $this->catalogue->visible('records');
        $stamps = $this->catalogue->datestamps($units->pluck('id')->all());
        $tops = $this->tops($units->all());
        if ($state['s'] !== null && $state['s'] !== 'records' && ! str_starts_with($state['s'], 'records:')) {
            return $this->error('badArgument', 'The sets are records and records:{UUID}.');
        }
        $selected = $units->filter(fn ($u) => ($from === null || $stamps[$u->id] >= $from) && ($until === null || $stamps[$u->id] <= $until)
            && ($state['s'] === null || $state['s'] === 'records' || $state['s'] === 'records:'.$tops[$u->id]))
            ->sortBy(fn ($u) => [$stamps[$u->id]->getTimestamp(), $u->id])->values();
        if ($selected->isEmpty()) {
            return $this->error('noRecordsMatch', 'No published record matches.');
        }
        if ($state['o'] >= $selected->count()) {
            return $this->error('badResumptionToken', 'The resumption token is past the end of the list.');
        }
        $page = $selected->slice($state['o'], self::PAGE);
        $this->open(true);
        $this->x->startElement($withMetadata ? 'ListRecords' : 'ListIdentifiers');
        foreach ($page as $unit) {
            $this->record($unit, $stamps[$unit->id], $tops[$unit->id], $state['p'], $withMetadata);
        }
        $next = $state['o'] + self::PAGE;
        if ($next < $selected->count() || $state['o'] > 0) {
            $this->x->startElement('resumptionToken');
            $this->x->writeAttribute('completeListSize', (string) $selected->count());
            $this->x->writeAttribute('cursor', (string) $state['o']);
            if ($next < $selected->count()) {
                $this->x->text(rtrim(strtr(base64_encode(json_encode([...$state, 'o' => $next])), '+/', '-_'), '='));
            }
            $this->x->endElement();
        }
        $this->x->endElement();

        return $this->close();
    }

    private function record(RecordResource $unit, CarbonImmutable $stamp, string $top, string $prefix, bool $withMetadata): void
    {
        if ($withMetadata) {
            $this->x->startElement('record');
        }
        $this->x->startElement('header');
        $this->x->writeElement('identifier', 'oai:'.$this->host().':'.$unit->id);
        $this->x->writeElement('datestamp', $this->stamp($stamp));
        $this->x->writeElement('setSpec', 'records');
        $this->x->writeElement('setSpec', 'records:'.$top);
        $this->x->endElement();
        if ($withMetadata) {
            $unit->loadMissing([...RecordService::DESCRIBED, ...DiscoveryController::RECORD_WITH]);
            $this->x->startElement('metadata');
            if ($prefix === 'oai_dc') {
                $dc = new DOMDocument;
                $dc->loadXML(app(DublinCore::class)->export($unit, $this->catalogue->view));
                $this->x->writeRaw($dc->saveXML($dc->documentElement));
            } else {
                // The wrapper the reference service uses; the JSON-LD goes in a CDATA section, whose end marker it may not contain.
                $json = json_encode((new JsonLd($this->catalogue->view))->dump($unit), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->x->startElementNs('rico', 'Record', 'https://www.ica.org/standards/RiC/ontology#');
                $this->x->startElementNs('openricx', 'jsonld', 'https://openric.org/ns/ext/v1#');
                $this->x->writeRaw('<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $json).']]>');
                $this->x->endElement();
                $this->x->endElement();
            }
            $this->x->endElement();
            $this->x->endElement();
        }
    }

    private function set(string $spec, string $name): void
    {
        $this->x->startElement('set');
        $this->x->writeElement('setSpec', $spec);
        $this->x->writeElement('setName', $name);
        $this->x->endElement();
    }

    /** The published unit an OAI identifier names: oai:{host}:{UUID}. */
    private function unit(string $identifier): ?RecordResource
    {
        $prefix = 'oai:'.$this->host().':';

        return str_starts_with($identifier, $prefix) ? $this->catalogue->find('records', substr($identifier, strlen($prefix))) : null;
    }

    /**
     * The top unit of each unit's hierarchy, for its set.
     *
     * @param  list<RecordResource>  $units
     * @return array<string, string>
     */
    private function tops(array $units): array
    {
        $parents = DB::table('record_resources')->where('institution_id', $this->catalogue->institution->id)->pluck('parent_id', 'id')->all();
        $tops = [];
        foreach ($units as $unit) {
            $id = $unit->id;
            while (($parents[$id] ?? null) !== null) {
                $id = $parents[$id];
            }
            $tops[$unit->id] = $id;
        }

        return $tops;
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: ?string} from, until (inclusive) and what is wrong with them */
    private function range(?string $from, ?string $until): array
    {
        $parse = function (?string $value, bool $end) {
            if ($value === null) {
                return [null, null];
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

                return [$end ? $day->endOfDay() : $day, 'day'];
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) && ($time = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC')) !== false) {
                return [$time, 'second'];
            }

            return [false, null];
        };
        [$start, $startGrain] = $parse($from, false);
        [$end, $endGrain] = $parse($until, true);
        if ($start === false || $end === false) {
            return [null, null, 'from and until are dates as YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.'];
        }
        if ($startGrain !== null && $endGrain !== null && $startGrain !== $endGrain) {
            return [null, null, 'from and until must have the same granularity.'];
        }
        if ($start !== null && $end !== null && $start > $end) {
            return [null, null, 'from is after until.'];
        }

        return [$start, $end, null];
    }

    private function token(string $token): ?array
    {
        $state = json_decode((string) base64_decode(strtr($token, '-_', '+/'), true), true);

        return is_array($state) && isset($state['p'], $state['o']) && is_int($state['o']) && $state['o'] >= 0
            && array_key_exists('f', $state) && array_key_exists('u', $state) && array_key_exists('s', $state) ? $state : null;
    }

    /** A public address to write to: a repository's contact point, then the configured one, then the mail sender. */
    private function adminEmail(Institution $institution): string
    {
        return DB::table('contact_points')->where('subject_table', 'repositories')
            ->whereIn('subject_id', DB::table('repositories')->where('institution_id', $institution->id)->whereNull('deleted_at')->select('id'))
            ->whereNotNull('email')->orderByDesc('is_primary')->value('email')
            ?? config('workspace.oai_admin_email') ?? config('mail.from.address');
    }

    private function host(): string
    {
        return (string) parse_url($this->catalogue->institution->base_iri, PHP_URL_HOST);
    }

    private function stamp(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** The envelope; the request element names the arguments only when they were legal (OAI-PMH 3.2). */
    private function open(bool $withArguments): void
    {
        $x = $this->x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('OAI-PMH');
        $x->writeAttribute('xmlns', 'http://www.openarchives.org/OAI/2.0/');
        $x->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->writeAttribute('xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd');
        $x->writeElement('responseDate', $this->stamp(CarbonImmutable::now()));
        $x->startElement('request');
        if ($withArguments) {
            foreach ($this->args as $name => $value) {
                $x->writeAttribute($name, $value);
            }
        }
        $x->text(route('api.oai'));
        $x->endElement();
    }

    private function error(string $code, string $message): Response
    {
        $this->open(! in_array($code, ['badVerb', 'badArgument'], true));
        $this->x->startElement('error');
        $this->x->writeAttribute('code', $code);
        $this->x->text($message);
        $this->x->endElement();

        return $this->close();
    }

    private function close(): Response
    {
        $this->x->endElement();
        $this->x->endDocument();

        return response($this->x->outputMemory(), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }
}
