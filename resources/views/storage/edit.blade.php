{{-- OpenRiC Workspace - describe a container and record its facts. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Describe container')
@section('content')
    <p><a href="{{ route('containers.show', $container) }}">Back to the container</a></p>
    <h1>Describe {{ $container->described('container-name', [$language, 'en']) }}</h1>
    <p>Values in <strong>{{ $language }}</strong>. Fill in what you know; empty fields are left out.</p>
    <form method="post" action="{{ route('containers.update', $container) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">Language of these values (BCP 47)</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        @include('storage._type-field', ['types' => $types, 'current' => $container->container_type_id])
        <label for="identifier">Identifier</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="identifier" name="identifier" maxlength="255" value="{{ old('identifier', $container->identifier) }}">
        <label for="barcode">Barcode</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="barcode" name="barcode" maxlength="255" value="{{ old('barcode', $barcode) }}">
        @include('description._fields', ['subject' => $container, 'standard' => \App\Description\Elements::CONTAINER, 'language' => $language])

        <fieldset><legend>Collection management (not in RiC-O)</legend>
            @foreach(['status' => ['Status', $statuses], 'security_level' => ['Security', $securityLevels]] as $field => [$label, $choices])
                <label for="{{ $field }}">{{ $label }}</label>
                <select id="{{ $field }}" name="{{ $field }}" style="font: inherit; padding: .5rem">
                    <option value="">Not set</option>
                    @php($current = ['status' => $container->status?->notation, 'security_level' => $container->securityLevel?->notation][$field])
                    @foreach($choices as $c)<option value="{{ $c->notation }}" @selected(old($field, $current) === $c->notation)>{{ $c->label(['en']) }}</option>@endforeach
                </select>
            @endforeach
            <div style="display: flex; gap: .75rem; flex-wrap: wrap">
                @foreach(['width_cm' => 'Width (cm)', 'height_cm' => 'Height (cm)', 'depth_cm' => 'Depth (cm)'] as $field => $label)
                    <div><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" inputmode="decimal" style="max-width: 8rem" value="{{ old($field, $container->$field) }}"></div>
                @endforeach
            </div>
            <div style="display: flex; gap: .75rem; flex-wrap: wrap">
                <div><label for="capacity">Capacity</label><input id="capacity" name="capacity" inputmode="decimal" style="max-width: 8rem" value="{{ old('capacity', $container->capacity) }}"></div>
                <div><label for="capacity_used">Of it used</label><input id="capacity_used" name="capacity_used" inputmode="decimal" style="max-width: 8rem" value="{{ old('capacity_used', $container->capacity_used) }}"></div>
                <div><label for="capacity_unit">Counted in</label>
                    <select id="capacity_unit" name="capacity_unit" style="font: inherit; padding: .5rem">
                        <option value="">Not set</option>
                        @foreach($units as $u)<option value="{{ $u->notation }}" @selected(old('capacity_unit', $container->capacityUnit?->notation) === $u->notation)>{{ $u->label(['en']) }}</option>@endforeach
                    </select></div>
            </div>
            <label for="climate_controlled">Climate controlled</label>
            <select id="climate_controlled" name="climate_controlled" style="font: inherit; padding: .5rem">
                @php($climate = old('climate_controlled', $container->climate_controlled === null ? '' : ($container->climate_controlled ? '1' : '0')))
                <option value="" @selected($climate === '')>Not recorded</option><option value="1" @selected($climate === '1')>Yes</option><option value="0" @selected($climate === '0')>No</option>
            </select>
            <div style="display: flex; gap: .75rem; flex-wrap: wrap">
                @foreach(['temperature_min' => 'Lowest temperature (°C)', 'temperature_max' => 'Highest temperature (°C)', 'humidity_min' => 'Lowest relative humidity (%)', 'humidity_max' => 'Highest relative humidity (%)'] as $field => $label)
                    <div><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" inputmode="decimal" style="max-width: 8rem" value="{{ old($field, $container->$field) }}"></div>
                @endforeach
            </div>
        </fieldset>
        <button type="submit">Save</button>
    </form>
@endsection
