<?php

// OpenRiC Workspace - reports and statistics (roadmap phase 8), after Heratio's ReportController, CatalogueGrowthController
// and DataQualityController (ahg-reports): the overview, data quality, and the lists with their filters, pages and CSV.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Reports\ReportService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** The heading of each list, as Heratio's report menu names it. */
    public const TITLES = ['descriptions' => 'Archival descriptions', 'authorities' => 'Authority records', 'repositories' => 'Repositories',
        'updates' => 'Recent updates', 'activity' => 'User activity', 'taxonomies' => 'Taxonomies'];

    public function __construct(private ReportService $reports) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('read', 'description'), 403);

        return view('reports.index', ['overview' => $this->reports->overview($request->user()->institution_id), 'lists' => $this->lists($request)]);
    }

    public function quality(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('read', 'description'), 403);

        return view('reports.quality', ['quality' => $this->reports->quality($request->user()->institution_id), 'lists' => $this->lists($request)]);
    }

    public function show(Request $request, string $list): View|StreamedResponse
    {
        abort_unless(array_key_exists($list, ReportService::LISTS), 404);
        abort_unless(ReportService::mayRead($request->user(), $list), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'of' => ['nullable', Rule::in(['created', 'updated'])],
            'level' => ['nullable', 'uuid'], 'type' => ['nullable', 'uuid'], 'published' => ['nullable', Rule::in(['yes', 'no'])],
            'per' => ['nullable', Rule::in(['20', '50', '100'])], 'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $query = $this->reports->query($request->user(), $list, $filters);

        if (($filters['format'] ?? null) === 'csv') {
            $rows = match (true) {
                $query instanceof Collection => $query,
                $query instanceof Builder => $query->lazy(500),
                default => $query->get(),
            };

            return response()->streamDownload(function () use ($list, $rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $this->reports->header($list));
                foreach ($rows as $row) {
                    fputcsv($out, $this->reports->row($list, $row));
                }
                fclose($out);
            }, "report-$list.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $per = (int) ($filters['per'] ?? 50);
        $page = $query instanceof Collection
            ? (new LengthAwarePaginator($query->forPage(LengthAwarePaginator::resolveCurrentPage(), $per)->values(), $query->count(), $per, null, ['path' => $request->url()]))->withQueryString()
            : $query->paginate($per)->withQueryString();

        return view('reports.list', ['list' => $list, 'page' => $page, 'filters' => $filters, 'reports' => $this->reports, 'lists' => $this->lists($request),
            'levels' => $list === 'descriptions' ? Concept::choices(Vocab::BASE.'level-of-description/')->with('labels')->get() : collect(),
            'types' => $list === 'authorities' ? Concept::choices(Vocab::BASE.'agent-type/')->with('labels')->get() : collect()]);
    }

    /** @return list<string> the lists the account may read, for the menu of reports */
    private function lists(Request $request): array
    {
        return array_values(array_filter(array_keys(ReportService::LISTS), fn ($list) => ReportService::mayRead($request->user(), $list)));
    }
}
