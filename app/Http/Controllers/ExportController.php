<?php

// OpenRiC Workspace - export units of description: EAD 2002, EAD3, CSV, Dublin Core and MODS.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Exporting is reading: a unit and the units inside it that the account may
// read. Published values only, unless the account may see drafts and asks
// for them (App\Exchange\ExportView).

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Description\RecordService;
use App\Exchange\DescriptionCsv;
use App\Exchange\DublinCore;
use App\Exchange\Ead;
use App\Exchange\ExportView;
use App\Exchange\Mods;
use App\Models\RecordResource;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ExportController extends Controller
{
    /** format => [name, what it holds, file extension, content type] */
    public const FORMATS = [
        'ead' => ['EAD 2002', 'The finding aid: this unit and every unit inside it.', 'xml', 'application/xml'],
        'ead3' => ['EAD3', 'The finding aid: this unit and every unit inside it.', 'xml', 'application/xml'],
        'csv' => ['CSV', 'This unit and every unit inside it, one row each, in AtoM\'s ISAD(G) columns.', 'csv', 'text/csv'],
        'dc' => ['Dublin Core', 'This unit alone, as simple Dublin Core (oai_dc).', 'xml', 'application/xml'],
        'mods' => ['MODS 3.8', 'This unit alone.', 'xml', 'application/xml'],
    ];

    public function __construct(private RecordService $records) {}

    public function form(Request $request, RecordResource $record): View
    {
        $this->own($request, $record);
        $record->load('descriptionValues', 'names');

        return view('records.export', ['record' => $record, 'formats' => self::FORMATS, 'languages' => $record->languages(),
            'original' => $record->originalLanguage('title'), 'mayDrafts' => $request->user()->may('view-draft', 'description', $record)]);
    }

    public function download(Request $request, RecordResource $record, string $format): Response
    {
        $this->own($request, $record);
        abort_unless(isset(self::FORMATS[$format]), 404);
        $view = $this->view($request, $record);
        $content = match ($format) {
            'ead', 'ead3' => app(Ead::class)->export($record, $view, $format === 'ead3'),
            'csv' => app(DescriptionCsv::class)->export([$record->loadMissing(RecordService::DESCRIBED),
                ...array_column(RecordService::flatten($this->records->hierarchy($record, $request->user()), $record->id), 0)], $view, $request->query('rows') === 'every-language'),
            'dc' => app(DublinCore::class)->export($record->loadMissing(RecordService::DESCRIBED), $view),
            'mods' => app(Mods::class)->export($record->loadMissing(RecordService::DESCRIBED), $view),
        };
        [, , $extension, $type] = self::FORMATS[$format];
        $name = trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($record->referenceCode() ?? $record->id)), '-');

        return response($content, 200, [
            'Content-Type' => "$type; charset=UTF-8",
            'Content-Disposition' => 'attachment; filename="'.$format.'-'.$name.'.'.$extension.'"',
        ]);
    }

    /** Every unit of description of the institution the account may read, or of one repository, as CSV. */
    public function everything(Request $request): View|Response
    {
        $user = $request->user();
        $repositories = Repository::where('institution_id', $user->institution_id)->whereNull('deleted_at')->with('names.nameType', 'descriptionValues.element')->get();
        if (! $request->has('download')) {
            return view('records.export-all', ['repositories' => $repositories, 'mayDrafts' => $user->mayAnywhere('view-draft', 'description')]);
        }
        $repository = $request->query('repository') ? ($repositories->firstWhere('id', $request->query('repository')) ?? abort(404)) : null;
        $tops = RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')->whereNull('parent_id')
            ->when($repository, fn ($q) => $q->where('repository_id', $repository->id))
            ->with(RecordService::DESCRIBED)->orderBy('position')->orderBy('id')->get()
            ->filter(fn (RecordResource $r) => $user->may('read', 'description', $r));
        $units = [];
        foreach ($tops as $top) {
            $units = [...$units, $top, ...array_column(RecordService::flatten($this->records->hierarchy($top, $user), $top->id), 0)];
        }
        // Drafts only when the account may see the drafts of every unit in the file.
        $view = new ExportView($user, $request->boolean('drafts') && $units !== [] && collect($units)->every(fn ($u) => $user->may('view-draft', 'description', $u)), $this->language($request));

        return response(app(DescriptionCsv::class)->export($units, $view, $request->query('rows') === 'every-language'), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="archival-descriptions-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    /** Drafts only when asked for and the account may see the drafts of every unit the export holds. */
    private function view(Request $request, RecordResource $record): ExportView
    {
        $user = $request->user();
        $drafts = $request->boolean('drafts') && $user->may('view-draft', 'description', $record)
            && collect(RecordService::flatten($this->records->hierarchy($record, $user), $record->id))->every(fn ($row) => $user->may('view-draft', 'description', $row[0]));

        return new ExportView($user, $drafts, $this->language($request) ?? $record->originalLanguage('title'));
    }

    private function language(Request $request): ?string
    {
        $language = $request->query('language');
        abort_if($language !== null && $language !== '' && ! Bcp47::valid((string) $language), 404);

        return $language ?: null;
    }

    /** Another institution's unit does not exist here (404); without read, 403 (ADR 0005). */
    private function own(Request $request, RecordResource $record): void
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
        abort_unless($request->user()->may('read', 'description', $record), 403);
    }
}
