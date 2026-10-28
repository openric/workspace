<?php

// OpenRiC Workspace - reports and statistics (roadmap phase 8), after Heratio's ReportController, CatalogueGrowthController
// and DataQualityController (ahg-reports): the overview, data quality, and the lists with their filters, pages and CSV.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Repository;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Reports\ReportService;
use App\Reports\UsageService;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
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

    /** Usage statistics of the public catalogue (PSIS's statistics dashboard, with its item and repository pages as filters). */
    public function usage(Request $request): View|StreamedResponse
    {
        abort_unless($request->user()->mayAnywhere('read', 'description'), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'repository' => ['nullable', 'uuid'], 'subject' => ['nullable', 'uuid'], 'format' => ['nullable', Rule::in(['csv'])], 'by' => ['nullable', Rule::in(['day', 'item'])],
        ]);
        $to = $filters['to'] ?? now()->toDateString();
        $from = $filters['from'] ?? CarbonImmutable::parse($to)->subDays(29)->toDateString();
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 3660, 422);
        $usage = app(UsageService::class);
        $statistics = $usage->statistics($request->user()->institution_id, $from, $to, $filters['repository'] ?? null, $filters['subject'] ?? null);

        if (($filters['format'] ?? null) === 'csv') {
            $rows = ($filters['by'] ?? 'day') === 'item'
                ? [[__('Kind'), __('Name'), __('Views'), 'IRI'], ...$statistics['viewed']->map(fn ($r) => [__(EntityKinds::noun($r['entity'])), (string) EntityKinds::label($r['entity']), (string) $r['count'], $r['entity']->iri])]
                : [[__('Date'), __('Views'), __('Downloads')], ...$statistics['days']->map(fn ($d, $day) => [$day, (string) $d['views'], (string) $d['downloads']])->values()];

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, "usage-$from-$to.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('reports.usage', ['statistics' => $statistics, 'from' => $from, 'to' => $to, 'filters' => $filters, 'lists' => $this->lists($request),
            'subject' => isset($filters['subject']) ? $usage->subject($filters['subject']) : null,
            'repositories' => Repository::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->with(EntityKinds::get('repository')['with'])->get()
                ->sortBy(fn ($r) => mb_strtolower((string) EntityKinds::label($r)))->values()]);
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

    /** @return list<string> the lists an account may read, for the menu of reports */
    public function listsFor(User $user): array
    {
        return array_values(array_filter(array_keys(ReportService::LISTS), fn ($list) => ReportService::mayRead($user, $list)));
    }

    private function lists(Request $request): array
    {
        return $this->listsFor($request->user());
    }
}
