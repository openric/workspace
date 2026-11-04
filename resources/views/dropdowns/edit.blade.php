{{-- OpenRiC Workspace - one bundled vocabulary as the institution wants it offered (Heratio's dropdown edit; roadmap phase 20d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', str_replace('OpenRiC Workspace ', '', (string) $vocabulary->title))
@section('content')
    <h1>{{ str_replace('OpenRiC Workspace ', '', (string) $vocabulary->title) }}</h1>
    <p><a href="{{ route('dropdowns.index') }}">{{ __('Dropdown manager') }}</a> · <span class="meta">{{ $vocabulary->iri }}</span></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ $open ? __('An open list: you may add terms of your own and hide the ones you do not use.') : __('A fixed list: the Workspace relies on its terms, or a standard does, so terms are not added or hidden. You may still word them your way, order them and choose a default.') }}</p>
    <form method="post" action="{{ route('dropdowns.save', $vocabulary) }}">@csrf
        <table><thead><tr><th scope="col">{{ __('Term') }}</th>@foreach($languages as $l)<th scope="col">{{ __('Our wording') }} ({{ $l }})</th>@endforeach<th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Default') }}</th>@if($open)<th scope="col">{{ __('Hidden') }}</th>@endif</tr></thead>
            <tbody>
                <tr><td colspan="{{ count($languages) + 2 }}"></td><td><label><input type="radio" name="default" value="" @checked($terms->doesntContain(fn ($t) => $t['overlay']?->is_default))> {{ __('None') }}</label></td>@if($open)<td></td>@endif</tr>
                @foreach($terms as $t)
                    @php($c = $t['concept'])
                    <tr @if($t['overlay']?->hidden) style="opacity: .6" @endif>
                        <td><strong>{{ $c->labels->firstWhere('language', 'en')?->pref_label ?? $c->notation }}</strong>@if($t['own']) <span class="meta">({{ __('our own') }})</span>@endif<br><span class="meta">{{ $c->notation }}</span></td>
                        @foreach($languages as $l)<td><input name="terms[{{ $c->id }}][labels][{{ $l }}]" value="{{ $t['overlay']->labels[$l] ?? '' }}" placeholder="{{ $c->labels->firstWhere('language', $l)?->pref_label }}" aria-label="{{ __('Our wording') }} ({{ $l }})" style="max-width: 14rem"></td>@endforeach
                        <td><input name="terms[{{ $c->id }}][sort_order]" type="number" min="0" value="{{ $t['overlay']?->sort_order }}" aria-label="{{ __('Order') }}" style="max-width: 5rem"></td>
                        <td><input type="radio" name="default" value="{{ $c->id }}" @checked($t['overlay']?->is_default) aria-label="{{ __('Default') }}"></td>
                        @if($open)<td><input type="hidden" name="terms[{{ $c->id }}][hidden]" value="0"><input type="checkbox" name="terms[{{ $c->id }}][hidden]" value="1" @checked($t['overlay']?->hidden) aria-label="{{ __('Hidden') }}">
                            @if($t['own'])<button type="submit" form="remove-{{ $c->id }}" style="margin: 0; padding: .1rem .5rem">{{ __('Remove') }}</button>@endif</td>@endif
                    </tr>
                @endforeach
            </tbody></table>
        <p class="meta">{{ __('Leave a wording empty to keep the bundled one. Terms with no order come after those with one.') }}</p>
        <button type="submit">{{ __('Save') }}</button>
    </form>
    @foreach($terms->where('own', true) as $t)<form id="remove-{{ $t['concept']->id }}" method="post" action="{{ route('dropdowns.remove', [$vocabulary, $t['concept']]) }}">@csrf</form>@endforeach
    @if($open)
        <h2>{{ __('Add a term of our own') }}</h2>
        <form method="post" action="{{ route('dropdowns.add', $vocabulary) }}">@csrf
            <label for="nl">{{ __('Label') }}</label><input id="nl" name="label" required maxlength="255" value="{{ old('label') }}">
            <label for="ng">{{ __('Its language (BCP 47)') }}</label><input id="ng" name="language" value="{{ old('language', app()->getLocale()) }}" required size="6">
            <label for="nn">{{ __('Code') }}</label><input id="nn" name="notation" maxlength="100" value="{{ old('notation') }}" placeholder="{{ __('Made from the label when left empty') }}">
            <label for="nb">{{ __('It narrows') }}</label><select id="nb" name="broader"><option value="">{{ __('None') }}</option>@foreach($terms->where('own', false) as $t)<option value="{{ $t['concept']->id }}">{{ $t['concept']->labels->firstWhere('language', 'en')?->pref_label ?? $t['concept']->notation }}</option>@endforeach</select>
            <p class="meta">{{ __('A term that narrows a bundled one is exported as the bundled one where a standard needs it.') }}</p>
            <button type="submit">{{ __('Add') }}</button></form>
    @endif
@endsection
