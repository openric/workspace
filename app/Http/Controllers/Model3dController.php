<?php

// OpenRiC Workspace - 3D models (roadmap phase 21, 3D objects), after Heratio's Model3dController and
// CameraBookmarkController: a model's page for who may change its description (how it is shown, its hotspots placed by
// clicking on it and written in each language, a turntable video asked for); the institution's defaults; saved views,
// published ones for every reader and a signed-in reader's own; and an embed page for a published model.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Description\Bcp47;
use App\Description\InstantiationService;
use App\Exchange\ExportView;
use App\Files\FileStore;
use App\Files\ModelViews;
use App\Iiif\Model3dManifest;
use App\Jobs\JobsService;
use App\Jobs\MakeTurntable;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\ModelBookmark;
use App\Models\ModelHotspot;
use App\Models\ModelSetting;
use App\Models\ModelViewerDefault;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class Model3dController extends Controller
{
    public function __construct(private ModelViews $views, private InstantiationService $instantiations) {}

    public function edit(Request $request, Instantiation $instantiation): View
    {
        $this->own($request, $instantiation, 'update');
        $instantiation->load('files.use', 'record');
        $model = $this->model($instantiation, $request->user()) ?? abort(404, __('It has no 3D model to show.'));

        return view('models.edit', ['instantiation' => $instantiation, 'model' => $model, 'settings' => $this->views->settings($instantiation),
            'own' => ModelSetting::find($instantiation->id), 'hotspots' => ModelHotspot::where('instantiation_id', $instantiation->id)->with('type.labels', 'texts.state')->orderBy('position')->get(),
            'types' => Concept::choices(ModelViews::HOTSPOT_TYPES)->get(), 'placements' => Concept::choices(ModelViews::AR_PLACEMENTS)->get(),
            'turntable' => $instantiation->files->first(fn ($f) => $f->use->notation === 'access' && $f->mime_type === 'video/mp4'),
            'properties' => $instantiation->file('master')?->model_properties ?? []]);
    }

    public function saveSettings(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $this->own($request, $instantiation, 'update');
        $data = $request->validate(ModelViews::rules());
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => ModelSetting::updateOrCreate(['instantiation_id' => $instantiation->id],
            ['institution_id' => $instantiation->institution_id] + ModelViews::fill($this->blanksAsNull($data)))));

        return back()->with('status', __('Saved.'));
    }

    public function storeHotspot(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $this->own($request, $instantiation, 'update');
        $data = $request->validate($this->hotspotRules() + ['position_x' => ['required', 'numeric'], 'position_y' => ['required', 'numeric'], 'position_z' => ['required', 'numeric'],
            'normal_x' => ['nullable', 'numeric'], 'normal_y' => ['nullable', 'numeric'], 'normal_z' => ['nullable', 'numeric']]);
        $this->views->placeHotspot($instantiation, $data, $request->user());

        return back()->with('status', __('The hotspot is placed, as a draft; it is published with the record.'));
    }

    public function updateHotspot(Request $request, Instantiation $instantiation, ModelHotspot $hotspot): RedirectResponse
    {
        $this->own($request, $instantiation, 'update');
        abort_unless($hotspot->instantiation_id === $instantiation->id, 404);
        $data = $request->validate($this->hotspotRules());
        DB::transaction(fn () => AuditLog::actingAs($request->user(), function () use ($hotspot, $data) {
            $hotspot->update(['type_id' => Concept::where('iri', ModelViews::HOTSPOT_TYPES.$data['type'])->value('id'), 'color' => $data['color'] ?? $hotspot->color,
                'link_url' => $data['link_url'] ?? null]);
            $this->views->write($hotspot, $data['language'], $data['title'], $data['description'] ?? null);
        }));

        return back()->with('status', __('Saved.'));
    }

    public function deleteHotspot(Request $request, Instantiation $instantiation, ModelHotspot $hotspot): RedirectResponse
    {
        $this->own($request, $instantiation, 'update');
        abort_unless($hotspot->instantiation_id === $instantiation->id, 404);
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $hotspot->delete()));

        return back()->with('status', __('Deleted.'));
    }

    public function turntable(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $this->own($request, $instantiation, 'update');
        $job = app(JobsService::class)->create($instantiation->institution_id, 'viewing-copies', __('Turntable of :name', ['name' => (string) $instantiation->file('master')?->original_name]),
            $request->user(), $instantiation);
        MakeTurntable::dispatch($job->id, $instantiation->id, $request->user()->id);

        return back()->with('status', __('The turntable video is being made; it appears here when it is ready.'));
    }

    public function defaults(Request $request): View
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-institution')), 403);

        return view('models.defaults', ['own' => ModelViewerDefault::find($request->user()->institution_id), 'placements' => Concept::choices(ModelViews::AR_PLACEMENTS)->get()]);
    }

    public function saveDefaults(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission(Vocab::permission('manage-institution')), 403);
        $data = $request->validate(ModelViews::rules());
        DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => ModelViewerDefault::updateOrCreate(['institution_id' => $request->user()->institution_id],
            ModelViews::fill($this->blanksAsNull($data)))));

        return back()->with('status', __('Saved.'));
    }

    /** Saved views of a model its reader may see (Heratio's GET /3d/{model}/bookmarks), for the public too. */
    public function bookmarks(Request $request, Instantiation $instantiation): JsonResponse
    {
        $this->readable($request, $instantiation);
        $user = $request->user();

        return response()->json(['bookmarks' => $this->views->bookmarks($instantiation, $user)->map(fn (ModelBookmark $b) => ['id' => $b->id, 'name' => $b->name,
            'camera_orbit' => $b->camera_orbit, 'camera_target' => $b->camera_target, 'field_of_view' => $b->field_of_view, 'is_public' => $b->is_public,
            'mine' => $user !== null && $b->user_id === $user->id])->values(), 'signed_in' => $user !== null], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function storeBookmark(Request $request, Instantiation $instantiation): JsonResponse
    {
        $this->readable($request, $instantiation);
        $user = $request->user() ?? abort(401);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'camera_orbit' => ['required', 'string', 'max:100'], 'camera_target' => ['nullable', 'string', 'max:100'],
            'field_of_view' => ['nullable', 'string', 'max:20'], 'is_public' => ['nullable', 'boolean']]);
        // A view for everyone is published by who may change the description, as staff publish one in Heratio.
        $public = ($data['is_public'] ?? false) && $user->may('update', 'description', $instantiation->record);
        $bookmark = ModelBookmark::create(['institution_id' => $instantiation->institution_id, 'instantiation_id' => $instantiation->id, 'user_id' => $user->id, 'is_public' => $public] + $data);

        return response()->json(['id' => $bookmark->id], 201);
    }

    public function deleteBookmark(Request $request, Instantiation $instantiation, ModelBookmark $bookmark): JsonResponse
    {
        $user = $request->user() ?? abort(401);
        abort_unless($bookmark->instantiation_id === $instantiation->id && $instantiation->institution_id === $user->institution_id, 404);
        abort_unless($bookmark->user_id === $user->id || ($bookmark->is_public && $user->may('update', 'description', $instantiation->record)), 403);
        $bookmark->delete();

        return response()->json(['deleted' => true]);
    }

    /** A published model alone on a page, to be embedded elsewhere (Heratio's embed view). */
    public function embed(Request $request, Instantiation $instantiation): View
    {
        $institution = Institution::findOrFail($instantiation->institution_id);
        abort_unless($instantiation->deleted_at === null && (new ExportView(null, false, null, $institution))->readable($instantiation->record), 404);
        $instantiation->load('files.use');
        $model = $this->model($instantiation, null) ?? abort(404);
        $language = $request->query('lang');
        $languages = array_values(array_filter([is_string($language) && Bcp47::valid($language) ? $language : null, app()->getLocale(), 'en']));

        return view('models.embed', ['instantiation' => $instantiation, 'model' => $model, 'settings' => $this->views->settings($instantiation),
            'hotspots' => $this->views->hotspots($instantiation, false, $languages), 'institution' => $institution]);
    }

    /** The model's IIIF manifest (Heratio's /iiif/3d/{id}/manifest.json), for a published model the public may receive. */
    public function manifest(Instantiation $instantiation): JsonResponse
    {
        $institution = Institution::findOrFail($instantiation->institution_id);
        abort_unless($instantiation->deleted_at === null && (new ExportView(null, false, null, $institution))->readable($instantiation->record), 404);
        $instantiation->load('files.use', 'names.nameType', 'names', 'descriptionValues.element');
        $model = $this->model($instantiation, null) ?? abort(404);

        return response()->json(app(Model3dManifest::class)->build($instantiation, $model), 200,
            ['Content-Type' => 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"', 'Access-Control-Allow-Origin' => '*'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The GLB a reader may receive: the reference copy, else the master. */
    private function model(Instantiation $instantiation, ?User $reader)
    {
        return collect([$instantiation->file('reference'), $instantiation->file('master')])
            ->first(fn ($f) => $f?->mime_type === FileStore::MODEL && $this->instantiations->deliverable($reader, $f->setRelation('instantiation', $instantiation)));
    }

    /** The model's record is one its reader may read: the public's catalogue, or a signed-in reader's institution. */
    private function readable(Request $request, Instantiation $instantiation): void
    {
        $institution = Institution::find($instantiation->institution_id);
        abort_unless($institution && $instantiation->deleted_at === null
            && (new ExportView($request->user()?->institution_id === $institution->id ? $request->user() : null, false, null, $institution))->readable($instantiation->record), 404);
    }

    private function own(Request $request, Instantiation $instantiation, string $action): void
    {
        abort_unless($instantiation->institution_id === $request->user()->institution_id && $instantiation->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'description', $instantiation->record), 403);
    }

    private function hotspotRules(): array
    {
        return ['type' => ['required', 'string', fn ($a, $v, $fail) => Concept::current()->where('iri', ModelViews::HOTSPOT_TYPES.$v)->exists() || $fail(__('Choose a kind from the list.'))], 'language' => ['required', 'string', 'max:35', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'link_url' => ['nullable', 'url:http,https', 'max:2000']];
    }

    private function blanksAsNull(array $data): array
    {
        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }
}
