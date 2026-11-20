<?php

// OpenRiC Workspace - a record set's name-first explorer in the public catalogue (roadmap phase 27, after the Smuts and
// Brenthurst explorers and Heratio's /explore/{slug}): the names its units carry, searched by name, each drawn with the
// units that carry it and the names printed beside it, the evidence alongside. Its settings are kept by whoever may
// describe the set.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Api\PublicCatalogue;
use App\Description\InstantiationService;
use App\Discover\ExplorerData;
use App\Models\ExplorerSetting;
use App\Models\Instantiation;
use App\Models\RecordResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ExplorerController extends Controller
{
    public function show(Request $request, string $id): View
    {
        /** @var PublicCatalogue $catalogue */
        $catalogue = $request->attributes->get('catalogue');
        $set = $catalogue->find('records', $id) ?? abort(404);
        $settings = ExplorerSetting::for($set);
        $user = $request->user();

        // Built for the public and kept until anything in the institution changes (its audit chain grows). ponytail: an
        // embargo that starts or ends by its date writes nothing, so the copy lives five minutes at most.
        $seq = (int) DB::table('audit_log')->where('institution_id', $set->institution_id)->max('seq');
        $data = Cache::remember("explorer:{$set->id}:$seq:".app()->getLocale(), 300, fn () => app(ExplorerData::class)->build($catalogue, $set, $settings));

        return view('discover.explorer', ['set' => $set, 'data' => $data, 'settings' => $settings,
            'editable' => $user !== null && $user->institution_id === $set->institution_id && $user->may('update', 'description', $set)]);
    }

    /** The text of one of the unit's instantiations, to whoever may have its reference copy (the explorer's transcript). */
    public function text(Request $request, string $id, string $instantiation): Response
    {
        /** @var PublicCatalogue $catalogue */
        $catalogue = $request->attributes->get('catalogue');
        $unit = $catalogue->find('records', $id) ?? abort(404);
        $found = Instantiation::where(['id' => $instantiation, 'record_resource_id' => $unit->id])->whereNull('deleted_at')->with('files.use')->first() ?? abort(404);
        abort_unless(app(InstantiationService::class)->textDeliverable($request->user(), $found), 404);
        $text = ExplorerData::text($found, $catalogue->view) ?? abort(404);

        return response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function save(Request $request, RecordResource $record): RedirectResponse
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
        abort_unless($request->user()->may('update', 'description', $record), 403);
        $data = $request->validate(['hide' => ['nullable', 'string', 'max:20000'], 'min_mentions' => ['required', 'integer', 'min:1', 'max:1000'],
            'unreviewed' => ['nullable', 'boolean'], 'min_confidence' => ['nullable', 'numeric', 'min:0', 'max:1']]);
        ExplorerSetting::updateOrCreate(['record_resource_id' => $record->id], ['institution_id' => $record->institution_id, 'hide' => $data['hide'] ?? null,
            'min_mentions' => (int) $data['min_mentions'], 'unreviewed' => (bool) ($data['unreviewed'] ?? false),
            'min_confidence' => isset($data['min_confidence']) && $data['min_confidence'] !== '' ? (float) $data['min_confidence'] : null]);

        return redirect()->route('discover.explorer', $record->id)->with('status', __('The explorer settings are saved.'));
    }
}
