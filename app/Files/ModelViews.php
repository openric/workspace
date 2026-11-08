<?php

// OpenRiC Workspace - how a 3D model is shown, and what is shown on it (roadmap phase 21, 3D objects), after Heratio's
// ahg-3d-model: its viewer settings, Heratio's defaults overlaid by the institution's and then by the model's own; its
// hotspots, each with its text in the reader's language where it has one, a reader who may not change the description
// seeing published texts only; and its saved views, published ones for everyone and a signed-in reader's own.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Files;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\ModelBookmark;
use App\Models\ModelHotspot;
use App\Models\ModelHotspotText;
use App\Models\ModelSetting;
use App\Models\ModelViewerDefault;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ModelViews
{
    /** Heratio's defaults (object_3d_model and viewer_3d_settings), where neither the institution nor the model says. */
    public const DEFAULTS = ['auto_rotate' => false, 'rotation_speed' => 30, 'camera_orbit' => '0deg 75deg 105%', 'min_camera_orbit' => null, 'max_camera_orbit' => null,
        'field_of_view' => '30deg', 'exposure' => 1.0, 'shadow_intensity' => 1.0, 'shadow_softness' => 1.0, 'neutral_lighting' => true, 'background_color' => '#f5f5f5',
        'ar_enabled' => false, 'ar_fixed_scale' => false, 'ar_placement' => 'floor'];

    public const HOTSPOT_TYPES = Vocab::BASE.'hotspot-type/';

    public const AR_PLACEMENTS = Vocab::BASE.'ar-placement/';

    /** The settings the viewer is given for this model. */
    public function settings(Instantiation $instantiation): array
    {
        $out = self::DEFAULTS;
        foreach ([ModelViewerDefault::find($instantiation->institution_id), ModelSetting::find($instantiation->id)] as $row) {
            foreach ($row ? ModelViewerDefault::FIELDS : [] as $field) {
                if ($row->$field !== null) {
                    $out[$field === 'ar_placement_id' ? 'ar_placement' : $field] = $field === 'ar_placement_id' ? Concept::find($row->$field)?->notation ?? 'floor' : $row->$field;
                }
            }
        }

        return $out;
    }

    /** Validated settings to keep, for an institution's defaults or one model; an empty value is "as the level above says". */
    public static function rules(): array
    {
        $orbit = ['nullable', 'string', 'max:100', 'regex:/^[-0-9.]+(deg|rad)? [-0-9.]+(deg|rad)? [-0-9.]+(m|%|cm|mm)?$|^auto auto auto$/'];

        return ['auto_rotate' => ['nullable', 'boolean'], 'rotation_speed' => ['nullable', 'integer', 'between:1,360'], 'camera_orbit' => $orbit, 'min_camera_orbit' => $orbit,
            'max_camera_orbit' => $orbit, 'field_of_view' => ['nullable', 'regex:/^\d{1,3}(\.\d+)?deg$/'], 'exposure' => ['nullable', 'numeric', 'between:0,5'],
            'shadow_intensity' => ['nullable', 'numeric', 'between:0,5'], 'shadow_softness' => ['nullable', 'numeric', 'between:0,1'], 'neutral_lighting' => ['nullable', 'boolean'],
            'background_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'ar_enabled' => ['nullable', 'boolean'], 'ar_fixed_scale' => ['nullable', 'boolean'],
            'ar_placement' => ['nullable', 'string', fn ($a, $v, $fail) => Concept::current()->where('iri', self::AR_PLACEMENTS.$v)->exists() || $fail(__('Choose a kind from the list.'))]];
    }

    /** The row's values from validated input: the AR placement as its concept. */
    public static function fill(array $data): array
    {
        $row = array_intersect_key($data, array_flip(array_diff(ModelViewerDefault::FIELDS, ['ar_placement_id'])));
        $row['ar_placement_id'] = isset($data['ar_placement']) ? Concept::where('iri', self::AR_PLACEMENTS.$data['ar_placement'])->value('id') : null;

        return $row;
    }

    /**
     * The model's hotspots a reader may see, each with the text to show: the first of the languages given that it has,
     * else the first it has; published texts only, unless the reader may change the description.
     *
     * @return Collection<int, array{hotspot: ModelHotspot, text: ModelHotspotText}>
     */
    public function hotspots(Instantiation $instantiation, bool $drafts, array $languages): Collection
    {
        $published = Concept::where('iri', Vocab::valueState('published'))->value('id');

        return ModelHotspot::where('instantiation_id', $instantiation->id)->with('type.labels', 'texts')->orderBy('position')->orderBy('created_at')->get()
            ->map(function (ModelHotspot $hotspot) use ($drafts, $published, $languages) {
                $texts = $hotspot->texts->filter(fn ($t) => $drafts || $t->state_id === $published);
                $text = collect($languages)->map(fn ($l) => $texts->firstWhere('language', $l))->filter()->first() ?? $texts->first();

                return $text ? ['hotspot' => $hotspot, 'text' => $text] : null;
            })->filter()->values();
    }

    /** A hotspot placed on the model, its text written in one language as a draft. */
    public function placeHotspot(Instantiation $instantiation, array $data, User $actor): ModelHotspot
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($instantiation, $data, $actor) {
            $hotspot = ModelHotspot::create(['institution_id' => $instantiation->institution_id, 'instantiation_id' => $instantiation->id,
                'type_id' => $this->concept(self::HOTSPOT_TYPES, $data['type'] ?? 'annotation'),
                'position_x' => $data['position_x'], 'position_y' => $data['position_y'], 'position_z' => $data['position_z'],
                'normal_x' => $data['normal_x'] ?? 0, 'normal_y' => $data['normal_y'] ?? 1, 'normal_z' => $data['normal_z'] ?? 0,
                'color' => $data['color'] ?? '#10373E', 'link_url' => $data['link_url'] ?? null,
                'position' => (int) ModelHotspot::where('instantiation_id', $instantiation->id)->max('position') + 1, 'created_by_id' => $actor->id]);
            $this->write($hotspot, $data['language'], $data['title'], $data['description'] ?? null);

            return $hotspot;
        }));
    }

    /** Its text in a language, written again as a draft: a published text changed is published again with the record. */
    public function write(ModelHotspot $hotspot, string $language, string $title, ?string $description): ModelHotspotText
    {
        $draft = Concept::where('iri', Vocab::valueState('draft'))->value('id');
        $text = ModelHotspotText::firstOrNew(['hotspot_id' => $hotspot->id, 'language' => $language]);
        $changed = ! $text->exists || $text->title !== $title || $text->description !== $description;
        $text->fill(['title' => $title, 'description' => $description] + ($changed ? ['state_id' => $draft] : []))->save();

        return $text;
    }

    /** The saved views a reader sees: published ones, then their own. */
    public function bookmarks(Instantiation $instantiation, ?User $reader): Collection
    {
        return ModelBookmark::where('instantiation_id', $instantiation->id)
            ->where(fn ($q) => $q->where('is_public', true)->when($reader, fn ($q) => $q->orWhere('user_id', $reader->id)))
            ->orderByDesc('is_public')->orderBy('name')->get();
    }

    private function concept(string $scheme, string $notation): string
    {
        return Concept::where('iri', $scheme.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
