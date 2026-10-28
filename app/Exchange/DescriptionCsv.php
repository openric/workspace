<?php

// OpenRiC Workspace - units of description as CSV, in AtoM's ISAD(G) column layout.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The columns are AtoM's archival description CSV template (2.x, ISAD(G)), so
// a file goes to AtoM and Heratio and comes back; four columns at the end
// carry what that template has no place for: the persistent IRI of the unit
// and of its parent, the administrative or biographical history (ISAD(G)
// 3.2.2) and the dates as written (3.1.3). legacyId is the unit's id and
// parentId its parent's, when the parent is in the same file. Values with
// several entries are separated by |, as AtoM does. Events pair the unit's
// dates with its creators of the same kind, in order. One row per unit, in
// one language; or one row per language a unit is described in, the
// original first, each translation row holding that language's values only
// (culture). What may be said is decided by ExportView.

namespace App\Exchange;

use App\Description\Elements;
use App\Models\Agent;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Support\Vocab;
use Illuminate\Support\Collection;

class DescriptionCsv
{
    /** AtoM's column => ISAD(G) element notation (a description value or name), or a key computed below. */
    public const COLUMNS = [
        'legacyId' => ':id', 'parentId' => ':parent', 'qubitParentSlug' => null, 'identifier' => ':identifier', 'accessionNumber' => null,
        'title' => 'title', 'levelOfDescription' => ':level', 'extentAndMedium' => 'extent', 'repository' => ':repository',
        'archivalHistory' => 'archival-history', 'acquisition' => 'acquisition', 'scopeAndContent' => 'scope-and-content', 'appraisal' => 'appraisal',
        'accruals' => 'accruals', 'arrangement' => 'arrangement', 'accessConditions' => 'conditions-governing-access',
        'reproductionConditions' => 'conditions-governing-reproduction', 'language' => 'languages-of-material-codes', 'script' => 'scripts-of-material-codes', 'languageNote' => 'languages-of-material',
        'physicalCharacteristics' => 'physical-characteristics', 'findingAids' => 'finding-aids-note', 'locationOfOriginals' => 'location-of-originals',
        'locationOfCopies' => 'location-of-copies', 'relatedUnitsOfDescription' => 'related-units', 'publicationNote' => 'publication-note',
        'digitalObjectPath' => null, 'digitalObjectURI' => null, 'generalNote' => 'note',
        'subjectAccessPoints' => ':subjects', 'placeAccessPoints' => ':places', 'nameAccessPoints' => ':names', 'genreAccessPoints' => ':genres',
        'descriptionIdentifier' => ':description-identifier', 'institutionIdentifier' => ':institution-identifier', 'rules' => 'rules-or-conventions',
        'descriptionStatus' => ':description-status', 'levelOfDetail' => ':level-of-detail', 'revisionHistory' => 'dates-of-descriptions',
        'languageOfDescription' => ':culture', 'scriptOfDescription' => 'scripts-of-description', 'sources' => 'sources-of-description', 'archivistNote' => 'archivists-note',
        'publicationStatus' => ':publication-status', 'physicalObjectName' => ':physical-names', 'physicalObjectLocation' => ':physical-locations', 'physicalObjectType' => ':physical-types',
        'alternativeIdentifiers' => ':alternative-identifiers', 'alternativeIdentifierLabels' => ':alternative-identifier-labels',
        'eventDates' => ':event-dates', 'eventTypes' => ':event-types', 'eventStartDates' => ':event-start-dates', 'eventEndDates' => ':event-end-dates',
        'eventActors' => ':event-actors', 'eventActorHistories' => ':event-actor-histories', 'culture' => ':culture',
        'persistentIri' => ':iri', 'parentIri' => ':parent-iri', 'creatorHistory' => 'creator-history', 'datesAsWritten' => 'dates-expressed',
    ];

    /** AtoM's event type for each date property and creator relation. */
    private const EVENT_TYPES = ['creation' => 'Creation', 'accumulation' => 'Accumulation', 'associated' => 'Associated'];

    /**
     * @param  iterable<RecordResource>  $units  in the order of the file; a parent before the units inside it
     * @param  bool  $everyLanguage  one row per language, else one row in the view's language
     */
    public function export(iterable $units, ExportView $view, bool $everyLanguage = false): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys(self::COLUMNS), escape: '');
        $written = [];
        foreach ($units as $unit) {
            $languages = $everyLanguage ? ($view->languages($unit) ?: [$view->languageOf($unit)]) : [$view->languageOf($unit)];
            foreach ($languages as $i => $language) {
                fputcsv($out, $this->row($unit, $view, $language, $everyLanguage && $i > 0, isset($written[$unit->parent_id])), escape: '');
            }
            $written[$unit->id] = true;
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @return list<string> */
    private function row(RecordResource $unit, ExportView $view, string $language, bool $translation, bool $parentInFile): array
    {
        $computed = $this->computed($unit, $view, $language, $parentInFile);
        $row = [];
        foreach (self::COLUMNS as $source) {
            $row[] = match (true) {
                $source === null => '',
                str_starts_with($source, ':') => (string) ($computed[substr($source, 1)] ?? ''),
                // ISO codes are in no language: the same on every row, separated by | as AtoM writes them.
                Elements::codeKind($source) !== null => str_replace(' ', '|', (string) $view->value($unit, $source, 'zxx')),
                default => (string) ($translation ? $view->valueIn($unit, $source, $language) : $view->value($unit, $source, $language)),
            };
        }

        return $row;
    }

    private function computed(RecordResource $unit, ExportView $view, string $language, bool $parentInFile): array
    {
        $points = $view->accessPoints($unit);
        $labels = fn (Collection $relations) => $relations->map(fn (Relation $r) => $view->label($r->other, $language))->filter()->join('|');
        $others = $unit->identifiers->reject(fn ($i) => $i->identifierType->notation === 'local')->values();
        $events = $this->events($unit, $view, $language);
        $storage = $view->storage($unit);
        $title = $unit->namesOfType('authorised')->first();

        return [
            'id' => $unit->id, 'parent' => $parentInFile ? $unit->parent_id : '', 'identifier' => $unit->identifier,
            'level' => $unit->level->label(['en', 'und']), 'repository' => $unit->repository ? $view->label($unit->repository, $language) : '',
            'subjects' => $labels($points['subjects']), 'places' => $labels($points['places']), 'names' => $labels($points['names']), 'genres' => $labels($points['genres']),
            'description-identifier' => $unit->description_identifier,
            // As written on the description, else the repository's ISIL or identifier.
            'institution-identifier' => $view->value($unit, 'institution-identifier', $language) ?: $unit->repository?->identifiers->first(fn ($i) => $i->identifierType->notation === 'isil')?->value ?? $unit->repository?->identifier,
            'description-status' => $unit->descriptionStatus?->label(['en', 'und']), 'level-of-detail' => $unit->descriptionDetail?->label(['en', 'und']),
            'culture' => $language,
            'publication-status' => $title && $title->state?->iri === Vocab::valueState('published') ? 'Published' : 'Draft',
            'alternative-identifiers' => $others->pluck('value')->join('|'),
            'alternative-identifier-labels' => $others->map(fn ($i) => $i->identifierType->label(['en', 'und']))->join('|'),
            'event-dates' => implode('|', array_column($events, 'date')), 'event-types' => implode('|', array_column($events, 'type')),
            'event-start-dates' => implode('|', array_column($events, 'start')), 'event-end-dates' => implode('|', array_column($events, 'end')),
            'event-actors' => implode('|', array_column($events, 'actor')), 'event-actor-histories' => implode('|', array_column($events, 'history')),
            'iri' => $unit->iri, 'parent-iri' => $unit->parent?->iri,
            'physical-names' => implode('|', array_column($storage, 'name')),
            'physical-locations' => $storage === [] ? '' : implode('|', array_map(fn ($c) => $c['location'] ?? 'NULL', $storage)),
            'physical-types' => $storage === [] ? '' : implode('|', array_map(fn ($c) => $c['type'] ?? 'NULL', $storage)),
        ];
    }

    /**
     * AtoM's events: each date of the unit, and each creator or accumulator, paired by kind in order.
     *
     * @return list<array{type: string, date: string, start: string, end: string, actor: string, history: string}>
     */
    private function events(RecordResource $unit, ExportView $view, string $language): array
    {
        $events = [];
        $creators = $view->creators($unit)->groupBy(fn (Relation $r) => $r->relationType->notation);
        foreach (self::EVENT_TYPES as $kind => $type) {
            $dates = $unit->dates->filter(fn ($d) => $d->property->notation === $kind)->values();
            $actors = ($creators[$kind] ?? collect())->values();
            for ($i = 0; $i < max($dates->count(), $actors->count()); $i++) {
                $date = $dates[$i] ?? null;
                $actor = $actors[$i]->other ?? null;
                $events[] = [
                    'type' => $type,
                    'date' => $date ? ($date->expressed ?? $date->display()) : '',
                    'start' => $date?->earliest?->format('Y-m-d') ?? '',
                    'end' => $date?->latest?->format('Y-m-d') ?? '',
                    'actor' => $actor ? (string) $view->label($actor, $language) : '',
                    'history' => $actor ? (string) $view->value($actor, $actor instanceof Agent ? 'agent-history' : 'history', $language) : '',
                ];
            }
        }

        return $events;
    }
}
