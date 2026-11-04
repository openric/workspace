<?php

// OpenRiC Workspace - usage and citation events reported to DataCite for the institution's DOIs (roadmap phase 20c),
// after Heratio's ahg-doi-manage DataciteEventsService: a view of a unit's public page, or a download of one of its
// files, while its DOI is findable, is kept as an event to report, and each kept event is sent to DataCite's Events API
// with the institution's token from DataCite Fabrica; one event is reported once, by a hash of what it says. Events
// are off until the institution switches them on and gives its token.
// Changed from Heratio: a view or download is counted once a DOI and day, rather than once a URL, so the figures DataCite
// shows read as daily counts; and events are sent by a scheduled run rather than one queued job each.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Dois;

use App\Models\RecordDoi;
use App\Models\RecordResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DataciteEvents
{
    public const SOURCE = 'openric-workspace';

    public const MAX_ATTEMPTS = 3;

    public function __construct(private RecordDois $dois) {}

    /** A public view or download of a unit with a findable DOI, kept to report, where events are on. */
    public function used(RecordResource $unit, string $relation): void
    {
        $record = RecordDoi::where('record_resource_id', $unit->id)->with('state')->first();
        if (! $record || $record->state->notation !== 'findable' || ! $this->dois->settings($unit->institution_id)->events_enabled) {
            return;
        }
        $this->register($record, $relation, $unit->iri, 'url', now()->toDateString());
    }

    /** Keep an event to report, once: the same subject, relation, object and source (and day, for usage) are one event. */
    public function register(RecordDoi $record, string $relation, string $objectId, string $objectType = 'url', ?string $day = null): bool
    {
        $hash = hash('sha256', implode('|', [$record->doi, $relation, $objectId, self::SOURCE, (string) $day]));

        return DB::table('datacite_events')->insertOrIgnore(['id' => (string) Str::uuid7(), 'institution_id' => $record->institution_id, 'dedupe_hash' => $hash,
            'subj_doi' => $record->doi, 'relation_id' => $this->dois->concept('datacite-event-relation', $relation), 'obj_id' => $objectId, 'obj_id_type' => $objectType,
            'source_id' => self::SOURCE, 'status_id' => $this->dois->concept('datacite-event-status', 'pending'), 'created_at' => now(), 'updated_at' => now()]) > 0;
    }

    /**
     * Heratio's metrics backfill: the views and downloads already counted since a day, of units whose DOI is findable,
     * kept as events to report, once a DOI and day; crawlers are left out, as in the usage figures.
     */
    public function backfill(string $institutionId, string $since): int
    {
        $kept = 0;
        $events = ['view' => 'unique-dataset-investigations-regular', 'download' => 'unique-dataset-requests-regular'];
        $records = RecordDoi::where('institution_id', $institutionId)->whereHas('state', fn ($q) => $q->where('notation', 'findable'))->with('record')->get();
        foreach ($records as $record) {
            $rows = DB::table('usage_events as u')->join('concepts as c', 'c.id', '=', 'u.event_id')->where(['u.institution_id' => $institutionId, 'u.subject_table' => 'record_resources', 'u.subject_id' => $record->record_resource_id])
                ->whereNull('u.crawler_id')->where('u.occurred_at', '>=', $since)->whereIn('c.notation', array_keys($events))
                ->selectRaw("c.notation, (u.occurred_at AT TIME ZONE 'UTC')::date as day")->distinct()->get();
            foreach ($rows as $row) {
                $kept += (int) $this->register($record, $events[$row->notation], $record->record->iri, 'url', (string) $row->day);
            }
        }

        return $kept;
    }

    /** Send what is waiting, for every institution with events on; each failure tried again until its attempts run out. */
    public function flush(int $limit = 200): array
    {
        $pending = $this->dois->concept('datacite-event-status', 'pending');
        $sent = $failed = 0;
        foreach (DB::table('datacite_events')->where('status_id', $pending)->orderBy('created_at')->limit($limit)->get() as $event) {
            $settings = $this->dois->settings($event->institution_id);
            $account = $this->dois->account($event->institution_id);
            if (! $settings->events_enabled || ! $settings->events_token || ! $account) {
                continue;
            }
            $relation = DB::table('concepts')->where('id', $event->relation_id)->value('notation');
            $payload = ['data' => ['id' => (string) Str::uuid(), 'type' => 'events', 'attributes' => ['subj-id' => 'https://doi.org/'.$event->subj_doi,
                'obj-id' => $event->obj_id_type === 'doi' ? 'https://doi.org/'.$event->obj_id : $event->obj_id, 'relation-type-id' => $relation, 'source-id' => $event->source_id,
                'source-token' => $settings->events_token, 'occurred-at' => Carbon::parse($event->created_at)->utc()->toIso8601String()]]];
            $base = $account->datacite_test ? 'https://api.test.datacite.org' : 'https://api.datacite.org';
            try {
                $response = Http::withToken($settings->events_token)->withHeaders(['Content-Type' => 'application/vnd.api+json'])->timeout(20)->post($base.'/events', $payload);
                $ok = $response->successful();
                $status = $response->status();
                $body = Str::limit($response->body(), 4000, '');
            } catch (Throwable $e) {
                [$ok, $status, $body] = [false, null, $e->getMessage()];
            }
            $attempts = $event->attempts + 1;
            DB::table('datacite_events')->where('id', $event->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES), 'response_status' => $status, 'response_body' => $body,
                'attempts' => $attempts, 'last_error' => $ok ? null : Str::limit((string) $body, 2000, ''), 'submitted_at' => $ok ? now() : null, 'updated_at' => now(),
                'status_id' => $this->dois->concept('datacite-event-status', $ok ? 'sent' : ($attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending'))]);
            $ok ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
