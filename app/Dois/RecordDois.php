<?php

// OpenRiC Workspace - DOIs for units of description (roadmap phase 20c), after Heratio's ahg-doi-manage DoiService:
// a published unit is given a DOI at DataCite, under the institution's prefix and a suffix made by its pattern, its
// DataCite 4 metadata made from what the public reads of it (title, creators with their ORCID iDs, ISNIs or ROR IDs, the
// publisher, the year, its resource type, its descriptions, subjects, dates, language, reference code, the record set it
// is part of, its places with their coordinates, its funding and the rights in force), resolving to its persistent IRI;
// the DOI then kept up to date, verified, or hidden; requests to DataCite queued, with their attempts and a pause that
// grows; DOIs minted by themselves when units are published, where the institution chooses; and every request logged,
// and the person who asked told how it went.
// Changed from Heratio: the DataCite account is the institution's own, the one its research services already keep (no
// separate config per repository); a unit must be published to be minted, since a DOI publishes it; the DOI becomes an
// identifier of the unit, so it is in its RiC-O; and Heratio's field mapping table, which its code never reads, is not
// carried: the metadata follows the RiC description.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Dois;

use App\Audit\AuditLog;
use App\Description\IdentifierService;
use App\Exchange\ExportView;
use App\Mail\DoiMail;
use App\Mail\Outbox;
use App\Models\Concept;
use App\Models\DoiQueueItem;
use App\Models\DoiSetting;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\RecordDoi;
use App\Models\RecordFunding;
use App\Models\RecordResource;
use App\Models\ResearchServiceSettings;
use App\Models\User;
use App\Research\Dois;
use App\Rights\PublicRights;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecordDois
{
    /** Heratio's processQueue pause: five minutes more after each failed attempt. */
    public const RETRY_MINUTES = 5;

    /** The DataCite account, where the institution has set one up (under the research services). */
    public function account(string $institutionId): ?ResearchServiceSettings
    {
        return app(Dois::class)->settings($institutionId);
    }

    public function settings(string $institutionId): DoiSetting
    {
        return DoiSetting::for($institutionId);
    }

    /** Register a DOI for a published unit: findable at once, or reserved as a draft. */
    public function mint(RecordResource $unit, ?User $actor, bool $publish = true): RecordDoi
    {
        // As it is now: what is published of it may have changed since this copy was read.
        $unit = $unit->fresh() ?? $unit;
        $institution = Institution::findOrFail($unit->institution_id);
        $account = $this->account($institution->id) ?? throw new RuntimeException(__('DataCite is not set up yet: give the institution\'s repository ID, password and prefix under the research services settings.'));
        if ($existing = RecordDoi::where('record_resource_id', $unit->id)->first()) {
            return $existing;
        }
        $view = new ExportView(null, false, null, $institution);
        $view->readable($unit) || throw new RuntimeException(__('Publish the unit first: a DOI makes it public.'));
        $settings = $this->settings($institution->id);
        $doi = mb_strtolower(rtrim($account->datacite_prefix, '/').'/'.$this->suffix($unit, $settings));
        $attributes = $this->metadata($unit, $view, $institution, $settings) + ['doi' => $doi, 'url' => $unit->iri];
        $publish && $attributes['event'] = 'publish';
        try {
            $body = $this->call($account, 'post', '/dois', ['data' => ['type' => 'dois', 'attributes' => $attributes]]);
        } catch (Throwable $e) {
            $this->log($institution->id, null, $unit->id, 'mint', false, null, null, ['error' => $e->getMessage()], $actor);
            $actor && app(Outbox::class)->send($actor, new DoiMail($actor, $unit, null, $e->getMessage()));
            throw $e;
        }
        $state = (string) ($body['data']['attributes']['state'] ?? ($publish ? 'findable' : 'draft'));
        $record = AuditLog::actingAs($actor, fn () => DB::transaction(function () use ($institution, $unit, $doi, $state, $account, $attributes, $body, $actor) {
            $record = RecordDoi::create(['institution_id' => $institution->id, 'record_resource_id' => $unit->id, 'doi' => $doi, 'state_id' => $this->concept('doi-state', $state),
                'test' => (bool) $account->datacite_test, 'metadata' => $attributes, 'datacite_response' => $body, 'minted_by_id' => $actor?->id, 'minted_at' => now(), 'last_sync_at' => now()]);
            // The DOI is one of the unit's identifiers, so it goes out with its RiC-O.
            $type = Concept::where('iri', Vocab::IDENTIFIER_TYPES.'doi')->value('id');
            Identifier::where(['subject_table' => 'record_resources', 'subject_id' => $unit->id, 'identifier_type_id' => $type, 'value' => $doi])->exists()
                || app(IdentifierService::class)->add($unit, ['type' => 'doi', 'value' => $doi], $actor);

            return $record;
        }));
        $this->log($institution->id, $record->id, $unit->id, 'mint', true, null, $state, ['doi' => $doi], $actor);
        $actor && app(Outbox::class)->send($actor, new DoiMail($actor, $unit, $record, null));

        return $record;
    }

    /** Send the unit's metadata to DataCite again; with $publish, make a draft DOI findable. */
    public function update(RecordDoi $record, ?User $actor, bool $publish = false): RecordDoi
    {
        $account = $this->usable($record);
        $institution = Institution::findOrFail($record->institution_id);
        $unit = $record->record;
        $attributes = $this->metadata($unit, new ExportView(null, false, null, $institution), $institution, $this->settings($institution->id)) + ['url' => $unit->iri];
        $publish && $attributes['event'] = 'publish';
        $before = $record->state->notation;
        $body = $this->request($record, 'update', $account, 'put', ['data' => ['type' => 'dois', 'attributes' => $attributes]], $actor);
        $state = (string) ($body['data']['attributes']['state'] ?? $before);
        AuditLog::actingAs($actor, fn () => $record->update(['metadata' => $attributes, 'datacite_response' => $body, 'state_id' => $this->concept('doi-state', $state), 'last_sync_at' => now()]));
        $this->log($record->institution_id, $record->id, $record->record_resource_id, 'update', true, $before, $state, [], $actor);

        return $record->fresh('state');
    }

    /** Ask DataCite what state the DOI is in, and keep it. */
    public function verify(RecordDoi $record, ?User $actor = null): RecordDoi
    {
        $account = $this->usable($record);
        $before = $record->state->notation;
        $body = $this->request($record, 'verify', $account, 'get', null, $actor);
        $state = (string) ($body['data']['attributes']['state'] ?? $before);
        AuditLog::actingAs($actor, fn () => $record->update(['state_id' => $this->concept('doi-state', $state), 'last_sync_at' => now()]));
        $this->log($record->institution_id, $record->id, $record->record_resource_id, 'verify', true, $before, $state, [], $actor);

        return $record->fresh('state');
    }

    /** Heratio's deactivate: the DOI out of DataCite's search (registered), still resolving, as a DOI must. */
    public function hide(RecordDoi $record, string $reason, ?User $actor): RecordDoi
    {
        $account = $this->usable($record);
        $before = $record->state->notation;
        $body = $this->request($record, 'hide', $account, 'put', ['data' => ['type' => 'dois', 'attributes' => ['event' => 'hide']]], $actor);
        $state = (string) ($body['data']['attributes']['state'] ?? 'registered');
        AuditLog::actingAs($actor, fn () => $record->update(['state_id' => $this->concept('doi-state', $state), 'last_sync_at' => now()]));
        $this->log($record->institution_id, $record->id, $record->record_resource_id, 'hide', true, $before, $state, ['reason' => $reason], $actor);

        return $record->fresh('state');
    }

    /** Queue a request to DataCite, unless the same one is already waiting. */
    public function enqueue(RecordResource $unit, string $action, ?User $actor, int $priority = 100): ?DoiQueueItem
    {
        $actionId = $this->concept('doi-action', $action);
        $waiting = [$this->concept('doi-queue-status', 'pending'), $this->concept('doi-queue-status', 'processing')];
        if (DoiQueueItem::where(['record_resource_id' => $unit->id, 'action_id' => $actionId])->whereIn('status_id', $waiting)->exists()) {
            return null;
        }

        return DoiQueueItem::create(['institution_id' => $unit->institution_id, 'record_resource_id' => $unit->id, 'action_id' => $actionId,
            'status_id' => $waiting[0], 'priority' => $priority, 'created_by_id' => $actor?->id, 'scheduled_at' => now()]);
    }

    /**
     * Heratio's processQueue: the requests whose time has come, lowest priority number first; a failure tried again
     * after five minutes more each time, until its attempts run out.
     *
     * @return array{done: int, failed: int}
     */
    public function process(int $limit = 50): array
    {
        $pending = $this->concept('doi-queue-status', 'pending');
        $done = $failed = 0;
        $items = DoiQueueItem::where('status_id', $pending)->where('scheduled_at', '<=', now())->orderBy('priority')->orderBy('created_at')->limit($limit)->with('action', 'record', 'createdBy')->get();
        foreach ($items as $item) {
            $item->update(['status_id' => $this->concept('doi-queue-status', 'processing'), 'started_at' => now(), 'attempts' => $item->attempts + 1]);
            try {
                $record = RecordDoi::where('record_resource_id', $item->record_resource_id)->first();
                match ($item->action->notation) {
                    'mint' => $this->mint($item->record, $item->createdBy),
                    'update' => $this->update($record ?? throw new RuntimeException(__('It has no DOI yet.')), $item->createdBy),
                    'verify' => $this->verify($record ?? throw new RuntimeException(__('It has no DOI yet.')), $item->createdBy),
                    'hide' => $this->hide($record ?? throw new RuntimeException(__('It has no DOI yet.')), 'queued', $item->createdBy),
                };
                $item->update(['status_id' => $this->concept('doi-queue-status', 'completed'), 'completed_at' => now(), 'last_error' => null]);
                $done++;
            } catch (Throwable $e) {
                $last = $item->attempts >= $item->max_attempts;
                $item->update(['status_id' => $this->concept('doi-queue-status', $last ? 'failed' : 'pending'), 'last_error' => Str::limit($e->getMessage(), 2000, ''),
                    'started_at' => null, 'scheduled_at' => now()->addMinutes(self::RETRY_MINUTES * $item->attempts)]);
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** Units just published: a DOI queued where the institution mints them by itself, and an existing one brought up to date. */
    public function published(array $unitIds, ?User $actor): void
    {
        foreach (RecordResource::whereIn('id', $unitIds)->whereNull('deleted_at')->get() as $unit) {
            $record = RecordDoi::where('record_resource_id', $unit->id)->first();
            if ($record) {
                $this->enqueue($unit, 'update', $actor, 200);

                continue;
            }
            $settings = $this->settings($unit->institution_id);
            if (! $settings->auto_mint || ! $this->account($unit->institution_id)) {
                continue;
            }
            $levels = (array) $settings->auto_mint_level_ids;
            if ($levels && ! in_array($unit->level_id, $levels, true)) {
                continue;
            }
            if ($settings->require_digital_object && ! $unit->instantiations()->whereNull('deleted_at')->whereHas('files')->exists()) {
                continue;
            }
            $this->enqueue($unit, 'mint', $actor);
        }
    }

    /** Heratio's buildDoiSuffix, from the institution's pattern; only what a DOI suffix may hold. */
    public function suffix(RecordResource $unit, DoiSetting $settings): string
    {
        $repository = $unit->repository ?? $unit->ancestors()[0]?->repository ?? null;
        $code = $repository ? (string) Identifier::where(['subject_table' => 'repositories', 'subject_id' => $repository->id])
            ->whereHas('identifierType', fn ($q) => $q->where('iri', Vocab::IDENTIFIER_TYPES.'local'))->value('value') : '';
        $suffix = strtr($settings->suffix_pattern ?: '{repository_code}/{year}/{id}', ['{repository_code}' => $code ?: 'ws', '{reference_code}' => $unit->referenceCode('-') ?: $unit->id,
            '{year}' => now()->format('Y'), '{id}' => $unit->id]);

        return trim((string) preg_replace(['~[^A-Za-z0-9._/-]+~', '~/{2,}~'], ['-', '/'], $suffix), '/-');
    }

    /** Heratio's buildMetadata: DataCite 4, from what the public reads of the unit. */
    public function metadata(RecordResource $unit, ExportView $view, Institution $institution, DoiSetting $settings): array
    {
        $language = $view->languageOf($unit);
        $text = fn (string $notation) => ($v = $view->value($unit, $notation, $language)) ? trim((string) preg_replace('/\s+/', ' ', strip_tags($v))) : null;
        $publisher = $settings->default_publisher ?: $institution->name;
        $points = $view->accessPoints($unit);
        $dates = collect($unit->dates)->map(fn ($d) => ExportView::isoInterval($d))->filter()->values();
        $years = collect($unit->dates)->map(fn ($d) => $d->earliest ? (int) substr((string) $d->earliest, 0, 4) : null)->filter();
        $type = $settings->default_resource_type_id ? Concept::whereKey($settings->default_resource_type_id)->value('notation') : null;
        $parent = $unit->parent_id ? RecordResource::find($unit->parent_id) : null;
        $parentDoi = $parent ? RecordDoi::where('record_resource_id', $parent->id)->value('doi') : null;
        $rights = collect((new PublicRights($view))->rights($unit))->flatMap(fn ($r) => [$r->statement, $r->licence])->filter()->first();

        return array_filter([
            'titles' => [['title' => (string) ($view->label($unit) ?? $unit->referenceCode())]],
            'creators' => $this->creators($unit, $view, $publisher),
            'publisher' => $publisher,
            'publicationYear' => $years->min() ?: (int) now()->format('Y'),
            'types' => ['resourceTypeGeneral' => $type ?: ($unit->children()->whereNull('deleted_at')->exists() ? 'Collection' : 'Text')],
            'descriptions' => array_values(array_filter([
                ($s = $text('scope-and-content')) ? ['description' => $s, 'descriptionType' => 'Abstract'] : null,
                ($s = $text('archival-history')) ? ['description' => $s, 'descriptionType' => 'Other'] : null,
                ($s = $text('acquisition')) ? ['description' => $s, 'descriptionType' => 'Other'] : null,
            ])),
            'subjects' => $points['subjects']->merge($points['genres'])->map(fn ($r) => ['subject' => (string) $view->label($r->other), 'valueUri' => $r->other->iri ?? null])->values()->all(),
            'dates' => $dates->map(fn ($d) => ['date' => $d, 'dateType' => 'Created'])->all(),
            'language' => $language,
            'alternateIdentifiers' => ($ref = $unit->referenceCode()) ? [['alternateIdentifier' => $ref, 'alternateIdentifierType' => 'Reference code']] : [],
            'relatedIdentifiers' => $parent && $view->readable($parent) ? [$parentDoi ? ['relatedIdentifier' => $parentDoi, 'relatedIdentifierType' => 'DOI', 'relationType' => 'IsPartOf']
                : ['relatedIdentifier' => $parent->iri, 'relatedIdentifierType' => 'URL', 'relationType' => 'IsPartOf']] : [],
            'geoLocations' => $points['places']->map(fn ($r) => array_filter(['geoLocationPlace' => (string) $view->label($r->other),
                'geoLocationPoint' => $r->other->latitude !== null ? ['pointLatitude' => (float) $r->other->latitude, 'pointLongitude' => (float) $r->other->longitude] : null]))->values()->all(),
            'fundingReferences' => RecordFunding::where('record_resource_id', $unit->id)->with('identifierType')->get()->map(fn (RecordFunding $f) => array_filter([
                'funderName' => $f->funder_name, 'funderIdentifier' => $f->funder_identifier,
                'funderIdentifierType' => $f->funder_identifier ? ['ror' => 'ROR', 'crossref-funder-id' => 'Crossref Funder ID', 'isni' => 'ISNI', 'grid' => 'GRID'][$f->identifierType?->notation] ?? 'Other' : null,
                'awardNumber' => $f->award_number, 'awardUri' => $f->award_uri, 'awardTitle' => $f->award_title]))->all(),
            'rightsList' => $rights ? [array_filter(['rights' => $rights->label(['en']), 'rightsUri' => $rights->iri])] : [],
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** Heratio's buildCreators: the unit's creators, each with an ORCID iD, ISNI or ROR ID where it has one; else the publisher. */
    private function creators(RecordResource $unit, ExportView $view, string $publisher): array
    {
        $schemes = ['orcid' => ['ORCID', 'https://orcid.org/'], 'isni' => ['ISNI', 'http://www.isni.org/isni/'], 'ror' => ['ROR', 'https://ror.org/']];
        $creators = collect($view->creators($unit))->map(function ($relation) use ($view, $schemes) {
            $agent = $relation->other;
            $ids = Identifier::where(['subject_table' => 'agents', 'subject_id' => $agent->id])->with('identifierType')->get()
                ->filter(fn ($i) => isset($schemes[$i->identifierType?->notation]))
                ->map(fn ($i) => ['nameIdentifier' => $i->value, 'nameIdentifierScheme' => $schemes[$i->identifierType->notation][0], 'schemeUri' => $schemes[$i->identifierType->notation][1]])->values()->all();

            return array_filter(['name' => (string) $view->label($agent), 'nameType' => ($agent->agentType?->notation ?? '') === 'person' ? 'Personal' : 'Organizational', 'nameIdentifiers' => $ids]);
        })->filter(fn ($c) => $c['name'] !== '')->values()->all();

        return $creators ?: [['name' => $publisher, 'nameType' => 'Organizational']];
    }

    /** The account a DOI was registered with: test or production, as it was. */
    private function usable(RecordDoi $record): ResearchServiceSettings
    {
        $account = $this->account($record->institution_id) ?? throw new RuntimeException(__('DataCite is not set up yet.'));
        (bool) $account->datacite_test === $record->test || throw new RuntimeException(__('The DOI was registered with DataCite\'s other service, test or production.'));

        return $account;
    }

    /** A request about a DOI there is, logged if it fails. */
    private function request(RecordDoi $record, string $action, ResearchServiceSettings $account, string $method, ?array $body, ?User $actor): array
    {
        try {
            return $this->call($account, $method, '/dois/'.rawurlencode($record->doi), $body);
        } catch (Throwable $e) {
            $this->log($record->institution_id, $record->id, $record->record_resource_id, $action, false, $record->state->notation, null, ['error' => $e->getMessage()], $actor);
            throw $e;
        }
    }

    /** DataCite's REST API, with the repository's account; its test service unless the account is for production. */
    private function call(ResearchServiceSettings $account, string $method, string $path, ?array $body): array
    {
        $base = $account->datacite_test ? 'https://api.test.datacite.org' : 'https://api.datacite.org';
        $http = Http::withBasicAuth($account->datacite_repository_id, $account->datacite_password)
            ->withHeaders(['Content-Type' => 'application/vnd.api+json', 'Accept' => 'application/vnd.api+json'])->timeout(20);
        $response = $method === 'get' ? $http->get($base.$path) : $http->{$method}($base.$path, $body ?? []);
        if (! $response->successful() || ! isset($response['data']['id'])) {
            throw new RuntimeException(__('DataCite did not take it: :error', ['error' => $response->json('errors.0.title') ?? $response->status()]));
        }

        return (array) $response->json();
    }

    private function log(string $institutionId, ?string $recordDoiId, ?string $unitId, string $action, bool $ok, ?string $before, ?string $after, array $details, ?User $actor): void
    {
        DB::table('doi_log')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $institutionId, 'record_doi_id' => $recordDoiId, 'record_resource_id' => $unitId,
            'action_id' => $this->concept('doi-action', $action), 'succeeded' => $ok, 'state_before' => $before, 'state_after' => $after,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'performed_by_id' => $actor?->id, 'performed_at' => now()]);
    }

    public function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new RuntimeException(__(':value is not in the list', ['value' => $notation]));
    }
}
