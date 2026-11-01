<?php

// OpenRiC Workspace - snapshots, the timeline and the map of a research project (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After the snapshots, timeline builder and map builder of Heratio's ResearchProjectOutputsController. A snapshot takes
// the project's records (its record resources, clipboard and collections) as the researcher may read them: for each,
// its IRI, title, reference code, a SHA-256 of its description and whether it was public and under an embargo; and a
// SHA-256 of the whole, to cite. Frozen, it is not taken again. Two snapshots are compared: what came, went and
// changed. Changed: Heratio's snapshot is a title and a description, its items never filled.
//
// The timeline holds the project's events, typed in, or taken from the dates of a collection's records (Heratio's
// auto_populate, from the records' events); the map holds points with their coordinates and dates, given out as
// GeoJSON as Heratio gives them out as JSON.

namespace App\Research;

use App\Audit\AuditLog;
use App\Exchange\ExportView;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchCollection;
use App\Models\ResearchMapPoint;
use App\Models\ResearchProject;
use App\Models\ResearchSnapshot;
use App\Models\ResearchTimelineEvent;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Rights\Embargoes;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProjectAnalysis
{
    public function __construct(private Projects $projects) {}

    // Snapshots.

    public function snapshot(User $user, ResearchProject $project, array $data): ResearchSnapshot
    {
        $this->projects->must($user, $project, 'contributor');
        $title = trim((string) ($data['title'] ?? '')) ?: __('Snapshot of :date', ['date' => now(config('workspace.timezone'))->format('j F Y H:i')]);

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $data, $title) {
            $snapshot = $project->snapshots()->create(['user_id' => $user->id, 'title' => mb_substr($title, 0, 500),
                'description' => trim((string) ($data['description'] ?? '')) ?: null, 'status_id' => $this->concept('snapshot-status', 'active')]);
            $this->take($user, $snapshot);

            return $snapshot;
        }));
    }

    /** Take the records again, unless it is frozen. */
    public function retake(User $user, ResearchSnapshot $snapshot): void
    {
        $this->projects->must($user, $snapshot->project, 'contributor');
        if ($snapshot->status->notation !== 'active') {
            throw new InvalidArgumentException(__('A frozen snapshot is not taken again.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $this->take($user, $snapshot)));
    }

    /** Freeze it, with an identifier to cite it by. */
    public function freeze(User $user, ResearchSnapshot $snapshot, string $status = 'frozen'): void
    {
        $this->projects->must($user, $snapshot->project, 'contributor');
        in_array($status, ['frozen', 'archived'], true) || throw new InvalidArgumentException(__('Choose a status from the list.'));
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $snapshot->update(['status_id' => $this->concept('snapshot-status', $status),
            'frozen_at' => $snapshot->frozen_at ?? now(), 'citation_id' => $snapshot->citation_id ?? 'snapshot:'.substr((string) $snapshot->hash_sha256, 0, 16)])));
    }

    /**
     * What came, went and changed between two snapshots of a project.
     *
     * @return array{added: list<array>, removed: list<array>, changed: list<array>, unchanged: int}
     */
    public function compare(ResearchSnapshot $before, ResearchSnapshot $after): array
    {
        $a = $before->items()->get()->keyBy('record_iri');
        $b = $after->items()->get()->keyBy('record_iri');
        $row = fn ($i) => ['iri' => $i->record_iri, 'title' => $i->title, 'reference' => $i->reference_code];

        return ['added' => $b->diffKeys($a)->map($row)->values()->all(), 'removed' => $a->diffKeys($b)->map($row)->values()->all(),
            'changed' => $b->intersectByKeys($a)->filter(fn ($i, $k) => ($i->metadata_version['hash'] ?? null) !== ($a[$k]->metadata_version['hash'] ?? null))->map($row)->values()->all(),
            'unchanged' => $b->intersectByKeys($a)->filter(fn ($i, $k) => ($i->metadata_version['hash'] ?? null) === ($a[$k]->metadata_version['hash'] ?? null))->count()];
    }

    private function take(User $user, ResearchSnapshot $snapshot): void
    {
        $project = $snapshot->project;
        $records = app(ProjectExport::class)->records($user, $project);
        $view = new ExportView($user, $user->mayAnywhere('view-draft', 'description'), null, Institution::find($project->institution_id));
        $public = new ExportView(null, false, null, Institution::find($project->institution_id));
        $snapshot->items()->delete();
        $hashes = [];
        foreach ($records as $i => $record) {
            $values = $record->descriptionValues()->with('element')->get()->map(fn ($v) => $v->element->iri.'|'.$v->language.'|'.$v->value)->sort()->values()->all();
            $hash = hash('sha256', $record->iri."\n".implode("\n", $values));
            $hashes[] = $hash;
            $snapshot->items()->create(['record_id' => $record->id, 'record_iri' => $record->iri, 'title' => (string) ($view->value($record, 'title') ?? EntityKinds::label($record)),
                'reference_code' => $record->referenceCode(), 'language' => $view->languageOf($record), 'metadata_version' => ['hash' => $hash, 'values' => count($values), 'taken_at' => now()->toIso8601String()],
                'rights_snapshot' => ['public' => $public->readable($record), 'embargoed' => app(Embargoes::class)->inForce($record) !== null], 'sort_order' => $i]);
        }
        sort($hashes);
        $snapshot->update(['item_count' => count($records), 'hash_sha256' => hash('sha256', implode("\n", $hashes)),
            'query_state' => ['sources' => ['record resources', 'clipboard', 'collections'], 'collections' => ResearchCollection::where('project_id', $project->id)->pluck('id')->all()],
            'rights_state' => ['public' => collect($snapshot->items()->get())->filter(fn ($i) => $i->rights_snapshot['public'])->count(),
                'embargoed' => collect($snapshot->items()->get())->filter(fn ($i) => $i->rights_snapshot['embargoed'])->count()],
            'metadata' => ['taken_by' => $user->name, 'taken_at' => now()->toIso8601String()]]);
    }

    // The timeline.

    public function saveEvent(User $user, ResearchProject $project, array $data, ?ResearchTimelineEvent $event = null): ResearchTimelineEvent
    {
        $this->projects->must($user, $project, 'contributor');
        if (trim((string) ($data['label'] ?? '')) === '' || ($data['date_start'] ?? '') === '') {
            throw new InvalidArgumentException(__('Give the event a label and a date.'));
        }
        if (($data['date_end'] ?? '') !== '' && $data['date_end'] < $data['date_start']) {
            throw new InvalidArgumentException(__('The end date comes before the start.'));
        }
        $colour = ($data['color'] ?? '') !== '' ? (preg_match('/^#[0-9a-fA-F]{6}$/', (string) $data['color']) ? $data['color'] : throw new InvalidArgumentException(__('Give a colour as #rrggbb.'))) : null;
        $fields = ['label' => mb_substr(trim($data['label']), 0, 500), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'date_start' => $data['date_start'],
            'date_end' => ($data['date_end'] ?? '') ?: null, 'date_type_id' => $this->concept('timeline-date-type', (string) ($data['date_type'] ?? 'event')), 'color' => $colour,
            'position' => (int) ($data['position'] ?? $event?->position ?? 0)];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $event ? tap($event)->update($fields)
            : $project->timelineEvents()->create($fields + ['user_id' => $user->id])));
    }

    /** Heratio's auto_populate: an event for each dated record of a collection of the project. @return int how many */
    public function fromCollection(User $user, ResearchProject $project, ResearchCollection $collection): int
    {
        $this->projects->must($user, $project, 'contributor');
        if ($collection->project_id !== $project->id) {
            throw new InvalidArgumentException(__('Choose a collection of this project.'));
        }
        $records = RecordResource::whereIn('id', $collection->items()->where('subject_table', 'record_resources')->pluck('subject_id'))->with('dates.property')->get()
            ->filter(fn ($r) => EntityKinds::readable($user, $r));
        $existing = $project->timelineEvents()->where('source_table', 'record_resources')->pluck('source_id')->all();

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $project, $records, $existing) {
            $n = 0;
            foreach ($records as $record) {
                $date = $record->dates->filter(fn ($d) => $d->earliest !== null)->sortBy('earliest')->first();
                if ($date === null || in_array($record->id, $existing, true)) {
                    continue;
                }
                $type = ['creation' => 'creation', 'accumulation' => 'accession', 'publication' => 'publication'][$date->property?->notation] ?? 'event';
                $project->timelineEvents()->create(['user_id' => $user->id, 'label' => mb_substr(EntityKinds::label($record), 0, 500),
                    'date_start' => $date->earliest->toDateString(), 'date_end' => $date->latest?->toDateString(), 'date_type_id' => $this->concept('timeline-date-type', $type),
                    'source_table' => 'record_resources', 'source_id' => $record->id]);
                $n++;
            }

            return $n;
        }));
    }

    // The map.

    public function savePoint(User $user, ResearchProject $project, array $data, ?ResearchMapPoint $point = null): ResearchMapPoint
    {
        $this->projects->must($user, $project, 'contributor');
        $lat = $data['latitude'] ?? null;
        $long = $data['longitude'] ?? null;
        if (trim((string) ($data['label'] ?? '')) === '' || ! is_numeric($lat) || ! is_numeric($long) || abs((float) $lat) > 90 || abs((float) $long) > 180) {
            throw new InvalidArgumentException(__('Give the point a label, and a latitude and longitude on the earth.'));
        }
        $fields = ['label' => mb_substr(trim($data['label']), 0, 500), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'latitude' => (float) $lat, 'longitude' => (float) $long,
            'place_name' => trim((string) ($data['place_name'] ?? '')) ?: null, 'date_valid_from' => ($data['date_valid_from'] ?? '') ?: null, 'date_valid_to' => ($data['date_valid_to'] ?? '') ?: null];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $point ? tap($point)->update($fields)
            : $project->mapPoints()->create($fields + ['user_id' => $user->id])));
    }

    /** The map as GeoJSON. */
    public function geojson(ResearchProject $project): array
    {
        return ['type' => 'FeatureCollection', 'features' => $project->mapPoints()->get()->map(fn ($p) => ['type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$p->longitude, $p->latitude]],
            'properties' => array_filter(['id' => $p->id, 'label' => $p->label, 'place' => $p->place_name, 'description' => $p->description,
                'from' => $p->date_valid_from?->toDateString(), 'to' => $p->date_valid_to?->toDateString()], fn ($v) => $v !== null)])->values()->all()];
    }

    /** Remove an event, point or snapshot: who added it, or an editor. */
    public function remove(User $user, ResearchTimelineEvent|ResearchMapPoint|ResearchSnapshot $thing): void
    {
        $this->projects->must($user, $thing->project, $thing->user_id === $user->id ? 'contributor' : 'editor');
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $thing->delete()));
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
