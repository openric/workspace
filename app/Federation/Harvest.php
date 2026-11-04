<?php

// OpenRiC Workspace - OAI-PMH harvest of a peer (roadmap phase 16b), after Heratio's ahg-federation HarvestClient and
// HarvestService: a peer's records listed by ListRecords, page after page by resumption token, from its last harvest
// unless a full one is asked for; each record in Dublin Core taken in as a draft unit of description in the repository
// the peer's records are kept in, mapped as Heratio maps it (title, description as scope and content, rights as
// conditions governing access, format as extent, date, source, the first identifier that is not an address); a record
// harvested before brought up to date; one the peer deleted marked deleted here; and each run kept with what it did.
// Changed from Heratio: the peer must be at a public address, checked before each request; the unit remembers the record
// it came from in a table of its own, not as properties; and only a unit the harvest made is ever changed or deleted by it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Description\EntityDeletion;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\FederationPeer;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use App\Webhooks\Webhooks;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class Harvest
{
    private const OAI = 'http://www.openarchives.org/OAI/2.0/';

    private const DC = 'http://purl.org/dc/elements/1.1/';

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/item';

    public function __construct(private RecordService $records, private RepositoryService $repositories, private EntityDeletion $deletion) {}

    /** One harvest of the peer; the session it kept. */
    public function run(FederationPeer $peer, ?User $actor = null, bool $full = false, ?string $from = null, ?string $until = null, ?string $set = null): object
    {
        $peer->loadMissing('type');
        $peer->type->notation === 'oai-pmh' || throw new InvalidArgumentException(__('Only an OAI-PMH peer is harvested.'));
        $prefix = $peer->metadata_prefix ?: 'oai_dc';
        $from = $from ?: ($full || ! $peer->last_harvest_at ? null : $peer->last_harvest_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $set = $set ?: $peer->default_set;
        $session = (string) Str::uuid7();
        DB::table('federation_harvest_sessions')->insert(['id' => $session, 'peer_id' => $peer->id, 'status_id' => $this->concept('harvest-status', 'running'), 'metadata_prefix' => $prefix,
            'harvest_from' => $from, 'harvest_until' => $until, 'harvest_set' => $set, 'is_full_harvest' => $full, 'initiated_by_id' => $actor?->id, 'started_at' => now()]);
        $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];
        $error = null;
        try {
            $repository = $this->repository($peer, $actor);
            foreach ($this->listRecords((string) $peer->base_url, array_filter(['metadataPrefix' => $prefix, 'from' => $from, 'until' => $until, 'set' => $set])) as $record) {
                $counts['total']++;
                try {
                    $action = $this->take($peer, $repository, $record, $prefix, $session, $actor);
                    $counts[$action]++;
                } catch (Throwable $e) {
                    $counts['errors']++;
                    $this->log($peer, $session, null, $record['identifier'], $prefix, 'skipped', mb_substr($e->getMessage(), 0, 1000));
                }
            }
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 2000);
        }
        $status = $error !== null ? 'failed' : ($counts['errors'] > 0 ? 'partial' : 'success');
        DB::table('federation_harvest_sessions')->where('id', $session)->update(['status_id' => $this->concept('harvest-status', $status), 'error_message' => $error, 'completed_at' => now(),
            ...collect($counts)->mapWithKeys(fn ($n, $k) => ['records_'.$k => $n])->all()]);
        $peer->forceFill(['last_harvest_status_id' => $this->concept('harvest-status', $status), 'last_harvest_records' => $counts['total']]
            + ($error === null ? ['last_harvest_at' => now()] : []))->save();

        return DB::table('federation_harvest_sessions')->where('id', $session)->first();
    }

    /** Harvest each OAI-PMH peer whose interval has passed. The number harvested. */
    public function runDue(): int
    {
        $due = FederationPeer::where('is_active', true)->whereNotNull('harvest_interval_hours')->whereHas('type', fn ($q) => $q->where('notation', 'oai-pmh'))->get()
            ->filter(fn ($p) => ! $p->last_harvest_at || $p->last_harvest_at->addHours($p->harvest_interval_hours)->isPast());
        $due->each(fn ($peer) => $this->run($peer));

        return $due->count();
    }

    /** @return Generator<array{identifier: string, datestamp: string, deleted: bool, metadata: array<string, list<string>>}> */
    public function listRecords(string $base, array $params): Generator
    {
        Webhooks::publicUrl($base) || throw new RuntimeException(__('Its address is not a public one.'));
        $query = ['verb' => 'ListRecords', ...$params];
        for ($page = 0; $page < 10000; $page++) {
            $xml = $this->request($base, $query);
            if (isset($xml->error)) {
                if ((string) $xml->error['code'] === 'noRecordsMatch') {
                    return;
                }
                throw new RuntimeException(__('The peer answered OAI-PMH error :code: :message', ['code' => (string) $xml->error['code'], 'message' => trim((string) $xml->error)]));
            }
            foreach ($xml->ListRecords->record ?? [] as $record) {
                $header = $record->header;
                $metadata = [];
                if (isset($record->metadata)) {
                    // <oai_dc:dc> holding the dc: elements, or (as some repositories write it) the dc: elements directly.
                    $wrapper = $record->metadata->children('http://www.openarchives.org/OAI/2.0/oai_dc/');
                    foreach (($wrapper->count() ? $wrapper->dc->children(self::DC) : $record->metadata->children(self::DC)) as $element) {
                        $metadata[$element->getName()][] = trim((string) $element);
                    }
                }
                yield ['identifier' => (string) $header->identifier, 'datestamp' => (string) $header->datestamp, 'deleted' => (string) $header['status'] === 'deleted', 'metadata' => $metadata];
            }
            $token = trim((string) ($xml->ListRecords->resumptionToken ?? ''));
            if ($token === '') {
                return;
            }
            $query = ['verb' => 'ListRecords', 'resumptionToken' => $token];
        }
    }

    /** Take one record in; what was done with it. */
    private function take(FederationPeer $peer, Repository $repository, array $record, string $prefix, string $session, ?User $actor): string
    {
        $known = DB::table('federation_harvested_records')->where(['peer_id' => $peer->id, 'source_identifier' => $record['identifier']])->first();
        $unit = $known ? RecordResource::find($known->record_resource_id) : null;
        if ($record['deleted']) {
            if ($unit === null || $unit->deleted_at !== null) {
                return $this->logged($peer, $session, $unit?->id, $record['identifier'], $prefix, 'skipped');
            }
            $this->deletion->delete($unit, $actor, __('Deleted by the peer :peer it was harvested from.', ['peer' => $peer->name]));

            return $this->logged($peer, $session, $unit->id, $record['identifier'], $prefix, 'deleted');
        }
        $values = self::mapDublinCore($record['metadata']);
        if (($values['title'] ?? '') === '') {
            return $this->logged($peer, $session, null, $record['identifier'], $prefix, 'skipped', __('It has no title.'));
        }
        $language = Institution::find($peer->institution_id)?->interface_languages[0] ?? 'en';
        $identifier = $values['_identifier'] ?? null;
        unset($values['_identifier']);
        DB::transaction(function () use (&$unit, $peer, $repository, $record, $prefix, $values, $language, $identifier, $actor, $known) {
            if ($unit === null || $unit->deleted_at !== null) {
                $unit = $this->records->create(Institution::findOrFail($peer->institution_id), self::LEVEL, $values['title'], $language, null, $repository, $identifier, $actor);
            }
            $this->records->describe($unit, $values, $language, $actor);
            DB::table('federation_harvested_records')->upsert([['id' => $known->id ?? (string) Str::uuid7(), 'peer_id' => $peer->id, 'record_resource_id' => $unit->id,
                'source_identifier' => $record['identifier'], 'datestamp' => $record['datestamp'] ?: null, 'metadata_format' => $prefix,
                'first_harvested_at' => $known->first_harvested_at ?? now(), 'last_harvested_at' => now()]], ['peer_id', 'source_identifier'], ['record_resource_id', 'datestamp', 'last_harvested_at']);
        });

        return $this->logged($peer, $session, $unit->id, $record['identifier'], $prefix, $known && RecordResource::whereKey($known->record_resource_id)->whereNull('deleted_at')->exists() ? 'updated' : 'created');
    }

    /**
     * Heratio's mapDublinCore, onto ISAD(G) element notations.
     *
     * @param  array<string, list<string>>  $m
     * @return array<string, string>
     */
    public static function mapDublinCore(array $m): array
    {
        $join = fn (string $key, string $glue) => implode($glue, array_filter($m[$key] ?? [], fn ($v) => $v !== '')) ?: null;

        return array_filter(['title' => $m['title'][0] ?? null, 'scope-and-content' => $join('description', "\n\n"), 'conditions-governing-access' => $join('rights', "\n"),
            'extent' => $join('format', '; '), 'dates-expressed' => $join('date', '; '), 'note' => ($m['source'][0] ?? '') !== '' ? __('Source: :source', ['source' => $m['source'][0]]) : null,
            '_identifier' => collect($m['identifier'] ?? [])->first(fn ($i) => ! preg_match('#^https?://#', $i))], fn ($v) => $v !== null && $v !== '');
    }

    /** The repository the peer's records are kept in, made the first time. */
    private function repository(FederationPeer $peer, ?User $actor): Repository
    {
        if ($peer->harvest_repository_id && ($repository = Repository::find($peer->harvest_repository_id))) {
            return $repository;
        }
        $institution = Institution::findOrFail($peer->institution_id);
        $repository = $this->repositories->create($institution, __('Harvested from :peer', ['peer' => $peer->name]), $institution->interface_languages[0] ?? 'en', null, $actor);
        $peer->forceFill(['harvest_repository_id' => $repository->id])->save();

        return $repository;
    }

    private function request(string $base, array $query): SimpleXMLElement
    {
        $response = null;
        for ($try = 0; $try < 3; $try++) {
            $response = Http::timeout(60)->withHeaders(['Accept' => 'text/xml, application/xml', 'User-Agent' => 'OpenRiC-Workspace-Harvester/1.0'])->withoutRedirecting()->get($base, $query);
            if ($response->status() !== 503) {
                break;
            }
            // OAI-PMH's flow control: a repository too busy says when to come back.
            usleep(min(30, (int) ($response->header('Retry-After') ?: 2 * ($try + 1))) * 1_000_000);
        }
        $response->successful() || throw new RuntimeException(__('The peer answered HTTP :status.', ['status' => $response->status()]));
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        $xml === false && throw new RuntimeException(__('The peer did not answer in XML.'));

        return $xml;
    }

    private function logged(FederationPeer $peer, string $session, ?string $unit, string $identifier, string $prefix, string $action, ?string $note = null): string
    {
        $this->log($peer, $session, $unit, $identifier, $prefix, $action, $note);

        return $action;
    }

    private function log(FederationPeer $peer, string $session, ?string $unit, string $identifier, string $prefix, string $action, ?string $note): void
    {
        DB::table('federation_harvest_log')->insert(['id' => (string) Str::uuid7(), 'peer_id' => $peer->id, 'session_id' => $session, 'record_resource_id' => $unit,
            'source_identifier' => mb_substr($identifier, 0, 1000), 'metadata_format' => $prefix, 'action_id' => $this->concept('harvest-action', $action), 'note' => $note, 'harvested_at' => now()]);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
