<?php

// OpenRiC Workspace - the use of the research services, for who runs them (roadmap phase 19i, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchAnalyticsService and ResearchAnalyticsController: the figures an institution needs to show
// how its records are used, for a period: researchers registered and by kind, visits booked and by room, records
// asked for (and the most asked for), reproductions, records cited (and the most cited), searches saved, records
// collected by researchers (and the most collected), projects begun. Changed: Heratio adds these up from
// research_statistics_daily and research_activity_log, which other parts of it fill; here they are counted from the
// research services' own tables, so there is no daily table to keep.

namespace App\Research;

use App\Models\CitationLogEntry;
use App\Models\MaterialRequest;
use App\Models\ReadingRoomBooking;
use App\Models\RecordResource;
use App\Models\ReproductionRequest;
use App\Models\ResearchCollectionItem;
use App\Models\Researcher;
use App\Models\ResearchProject;
use App\Models\SavedSearch;
use App\Models\User;
use App\Relations\EntityKinds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Analytics
{
    private const TOP = 10;

    /** @return array<string, mixed> */
    public function dashboard(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $institution = $user->institution_id;
        $within = fn ($q, string $column = 'created_at') => $q->whereBetween($column, [$from->startOfDay(), $to->endOfDay()]);
        $bookings = $within(ReadingRoomBooking::where('institution_id', $institution), 'booking_date');
        $requests = MaterialRequest::whereIn('booking_id', (clone $bookings)->select('id'));
        $top = function ($query, string $column) use ($user) {
            return $query->select($column, DB::raw('count(*) as n'))->groupBy($column)->orderByDesc('n')->limit(self::TOP)->get()
                ->map(fn ($row) => ['record' => RecordResource::find($row->{$column}), 'n' => (int) $row->n])
                ->filter(fn ($r) => $r['record'] && EntityKinds::readable($user, $r['record']))->values()->all();
        };

        return [
            'researchers' => ['registered' => $within(Researcher::where('institution_id', $institution))->count(),
                'by_type' => Researcher::where('institution_id', $institution)->with('type')->get()->countBy(fn ($r) => $r->type?->name ?? __('None'))->all()],
            'bookings' => ['total' => (clone $bookings)->count(), 'by_room' => (clone $bookings)->with('room')->get()->countBy(fn ($b) => $b->room?->name)->all(),
                'by_status' => (clone $bookings)->with('status')->get()->countBy(fn ($b) => $b->status->notation)->all()],
            'requests' => ['total' => (clone $requests)->count(), 'top' => $top(clone $requests, 'record_resource_id')],
            'reproductions' => ['total' => $within(ReproductionRequest::where('institution_id', $institution))->count(),
                'paid' => (float) $within(ReproductionRequest::where('institution_id', $institution))->whereNotNull('payment_date')->sum('final_cost')],
            'citations' => ['total' => $within(CitationLogEntry::where('institution_id', $institution))->count(),
                'top' => $top($within(CitationLogEntry::where('institution_id', $institution)), 'record_resource_id')],
            'searches' => ['saved' => $within(SavedSearch::where('institution_id', $institution))->count(),
                'top' => $within(SavedSearch::where('institution_id', $institution))->select('search_query', DB::raw('count(*) as n'))->groupBy('search_query')->orderByDesc('n')->limit(self::TOP)->pluck('n', 'search_query')->all()],
            'collected' => ['total' => $within(ResearchCollectionItem::where('subject_table', 'record_resources')->whereIn('subject_id', RecordResource::where('institution_id', $institution)->select('id')))->count(),
                'top' => $top($within(ResearchCollectionItem::where('subject_table', 'record_resources')->whereIn('subject_id', RecordResource::where('institution_id', $institution)->select('id'))), 'subject_id')],
            'projects' => $within(ResearchProject::where('institution_id', $institution))->count(),
        ];
    }
}
