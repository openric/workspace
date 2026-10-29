<?php

// OpenRiC Workspace - the institution's watermark, and making viewing copies again (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's MediaProcessingController (ahg-media-processing): watermarkSettings and
// saveWatermarkSettings for the default watermark, with a custom image uploaded as Heratio's
// uploadCustomWatermark does, and regenerate and batchRegenerate, here as background jobs
// (roadmap phase 5) rather than in the request. Settings need manage-institution and are
// audited through the Institution model; a custom image is PNG only, kept on the private disk
// as the logo is. One instantiation's copies may be made again by those who may update it.

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Description\InstantiationService;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WatermarkController extends Controller
{
    private const TYPES = Vocab::BASE.'watermark-type/';

    private const POSITIONS = Vocab::BASE.'watermark-position/';

    public function edit(Request $request): View
    {
        $institution = $request->user()->institution->load('watermarkType', 'watermarkPosition');

        return view('settings.watermark', ['institution' => $institution, 'types' => Concept::choices(self::TYPES)->with('labels')->get(),
            'positions' => Concept::choices(self::POSITIONS)->with('labels')->get()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $types = Concept::choices(self::TYPES)->get();
        $positions = Concept::choices(self::POSITIONS)->get();
        $data = $request->validate([
            'type' => ['nullable', Rule::in($types->pluck('notation'))],
            'position' => ['required', Rule::in($positions->pluck('notation'))],
            'opacity' => ['required', 'integer', 'between:1,100'],
            'min_size' => ['required', 'integer', 'between:0,10000'],
            'image' => ['nullable', 'file', 'mimes:png', 'mimetypes:image/png', 'max:1024', 'dimensions:max_width=4000,max_height=4000'],
        ]);
        $institution = $request->user()->institution;
        $changes = ['watermark_type_id' => $types->firstWhere('notation', $data['type'] ?? null)?->id,
            'watermark_position_id' => $positions->firstWhere('notation', $data['position'])->id,
            'watermark_opacity' => (int) $data['opacity'], 'watermark_min_size' => (int) $data['min_size']];
        $disk = Storage::disk('local');
        $old = $institution->watermark_image_path;
        if ($request->hasFile('image')) {
            $changes['watermark_image_path'] = $disk->putFileAs('branding', $request->file('image'), $institution->id.'-watermark-'.Str::random(12).'.png');
        }
        if (($data['type'] ?? null) === 'custom' && ! ($changes['watermark_image_path'] ?? $old)) {
            return back()->withInput()->withErrors(['image' => __('Upload the image to use as the watermark.')]);
        }
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $institution->update($changes)));
        if ($old && isset($changes['watermark_image_path'])) {
            $disk->delete($old);
        }

        return redirect()->route('watermark.edit')->with('status', __('Watermark saved. Images uploaded from now on carry it; make the viewing copies again for those already kept.'));
    }

    /** Heratio's batchRegenerate: a job for each image of the institution. */
    public function remakeAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        $count = 0;
        Instantiation::where('institution_id', $user->institution_id)->whereNull('deleted_at')
            ->whereHas('files', fn ($q) => $q->where('mime_type', 'like', 'image/%')->whereHas('use', fn ($q) => $q->where('notation', 'master')))
            ->with('files.use')->orderBy('id')->chunkById(200, function ($chunk) use ($user, &$count) {
                foreach ($chunk as $instantiation) {
                    app(InstantiationService::class)->queueRemake($instantiation, $user);
                    $count++;
                }
            });

        return redirect()->route('watermark.edit')->with('status', trans_choice('The viewing copies of :count image are being made again.|The viewing copies of :count images are being made again.', $count));
    }

    /** Heratio's regenerate, for one instantiation. */
    public function remake(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($instantiation->institution_id === $user->institution_id && $instantiation->deleted_at === null, 404);
        abort_unless($user->may('update', 'description', $instantiation->record), 403);
        abort_if($instantiation->file('master') === null, 404);
        app(InstantiationService::class)->queueRemake($instantiation, $user);

        return redirect()->route('instantiations.show', $instantiation)->with('status', __('The viewing copies are being made again.'));
    }
}
