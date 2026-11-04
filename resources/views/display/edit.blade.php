{{-- OpenRiC Workspace - one display profile: its level, elements in order, labels, hierarchy, pictures and actions (Heratio's display profile and field settings; roadmap phase 20d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $profile?->name ?? __('New profile'))
@section('content')
    <h1>{{ $profile?->name ?? __('New profile') }}</h1>
    <p><a href="{{ route('display.index') }}">{{ __('Display') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    @php($chosen = old('elements', $profile?->elements ?? []))
    @php($position = array_flip($chosen))
    <form method="post" action="{{ $profile ? route('display.update', $profile) : route('display.store') }}">@csrf
        <label for="d-name">{{ __('Name') }}</label><input id="d-name" name="name" required maxlength="200" value="{{ old('name', $profile?->name) }}">
        <label for="d-level">{{ __('Level of description') }}</label>
        <select id="d-level" name="level"><option value="">{{ __('Every level (default)') }}</option>@foreach($levels as $l)<option value="{{ $l->notation }}" @selected(old('level', $profile?->level?->notation) === $l->notation)>{{ $l->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
        <label><input type="hidden" name="show_tree" value="0"><input type="checkbox" name="show_tree" value="1" style="width: auto" @checked(old('show_tree', $profile?->show_tree ?? true))> {{ __('Show the hierarchy beside the page') }}</label>
        <label for="d-size">{{ __('Pictures of the units inside') }}</label>
        <select id="d-size" name="thumbnail_size"><option value="">{{ __('As the page shows them') }}</option>@foreach($sizes as $s)<option value="{{ $s->notation }}" @selected(old('thumbnail_size', $profile?->thumbnailSize?->notation) === $s->notation)>{{ $s->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
        <fieldset><legend>{{ __('What a reader may do from the page') }}</legend>
            @foreach($actions as $a)<label><input type="checkbox" name="actions[]" value="{{ $a->notation }}" style="width: auto" @checked(in_array($a->notation, old('actions', $profile ? $profile->actions->pluck('notation')->all() : $actions->pluck('notation')->all()), true))> {{ $a->label(\App\Support\Vocab::languages()) }}</label>@endforeach
        </fieldset>
        <h2>{{ __('Elements') }}</h2>
        <p class="meta">{{ __('Tick the elements to show, and number them for the order within their area; tick none to show every element. Leave a label empty to keep the standard\'s.') }}</p>
        @foreach($areas as $area => $elements)
            <h3>{{ $area }}</h3>
            <table><thead><tr><th scope="col">{{ __('Show') }}</th><th scope="col">{{ __('Element') }}</th><th scope="col">{{ __('Order') }}</th>@foreach($languages as $l)<th scope="col">{{ __('Our label') }} ({{ $l }})</th>@endforeach</tr></thead><tbody>
                @foreach($elements as $e)<tr>
                    <td><input type="checkbox" name="elements[]" value="{{ $e->notation }}" style="width: auto" @checked(isset($position[$e->notation])) aria-label="{{ __('Show') }}"></td>
                    <td>{{ \App\Description\Elements::number($e) }} {{ $e->label(\App\Support\Vocab::languages()) }}</td>
                    <td><input name="order[{{ $e->notation }}]" type="number" min="0" max="1000" value="{{ isset($position[$e->notation]) ? $position[$e->notation] + 1 : '' }}" style="max-width: 5rem" aria-label="{{ __('Order') }}"></td>
                    @foreach($languages as $l)<td><input name="labels[{{ $e->notation }}][{{ $l }}]" value="{{ old('labels.'.$e->notation.'.'.$l, $profile?->labels[$e->notation][$l] ?? '') }}" maxlength="255" style="max-width: 14rem" aria-label="{{ __('Our label') }} ({{ $l }})"></td>@endforeach
                </tr>@endforeach
            </tbody></table>
        @endforeach
        <button type="submit">{{ __('Save') }}</button>
    </form>
    @if($profile)<form method="post" action="{{ route('display.destroy', $profile) }}">@csrf<button type="submit">{{ __('Delete the profile') }}</button></form>@endif
@endsection
