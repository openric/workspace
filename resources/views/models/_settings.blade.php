{{-- OpenRiC Workspace - the settings of how a 3D model is shown, for one model or an institution's defaults (roadmap phase 21, after Heratio's ahg-3d-model). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $action, $own (the row kept, or null), $effective (what applies now) and $placements. --}}
@php($lang = \App\Support\Vocab::languages())
@php($yesNo = fn (string $field, string $label) => ['field' => $field, 'label' => $label])
<form method="post" action="{{ $action }}">@csrf
    @foreach([['auto_rotate', __('Turn by itself')], ['neutral_lighting', __('Even, neutral lighting')], ['ar_enabled', __('Offer augmented reality (on a phone or tablet)')], ['ar_fixed_scale', __('In augmented reality, keep its real size')]] as [$field, $label])
        <label for="s-{{ $field }}">{{ $label }}</label>
        <select id="s-{{ $field }}" name="{{ $field }}">
            <option value="" @selected($own?->$field === null)>{{ __('As the default (:value)', ['value' => $effective[$field] ? __('yes') : __('no')]) }}</option>
            <option value="1" @selected($own?->$field === true)>{{ __('Yes') }}</option>
            <option value="0" @selected($own?->$field === false)>{{ __('No') }}</option>
        </select>
    @endforeach
    @foreach([['rotation_speed', __('Turning speed, degrees a second'), 'number'], ['camera_orbit', __('Starting view (angle, height, distance), as 0deg 75deg 105%'), 'text'],
        ['min_camera_orbit', __('Closest view'), 'text'], ['max_camera_orbit', __('Farthest view'), 'text'], ['field_of_view', __('Field of view, as 30deg'), 'text'],
        ['exposure', __('Brightness (0 to 5)'), 'text'], ['shadow_intensity', __('Shadow (0 to 5)'), 'text'], ['shadow_softness', __('Softness of the shadow (0 to 1)'), 'text'],
        ['background_color', __('Background colour, as #f5f5f5'), 'text']] as [$field, $label, $type])
        <label for="s-{{ $field }}">{{ $label }}</label>
        <input id="s-{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ old($field, $own?->$field) }}" placeholder="{{ $effective[$field] }}" maxlength="100">
    @endforeach
    <label for="s-ar_placement">{{ __('In augmented reality, place it') }}</label>
    <select id="s-ar_placement" name="ar_placement">
        <option value="">{{ __('As the default (:value)', ['value' => $placements->firstWhere('notation', $effective['ar_placement'])?->label($lang) ?? $effective['ar_placement']]) }}</option>
        @foreach($placements as $p)<option value="{{ $p->notation }}" @selected($own && $own->ar_placement_id === $p->id)>{{ $p->label($lang) }}</option>@endforeach
    </select>
    <button type="submit">{{ __('Save') }}</button>
</form>
