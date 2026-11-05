{{-- OpenRiC Workspace - the names to resolve to authority records and places (Heratio's authority resolution queue; roadmap phase 17b2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Names to resolve'))
@section('content')
    <h1>{{ __('Names to resolve') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('People, organisations and places a description names, found by a model or sent through the API, each with the authority records or places it may be, ranked by name and by evidence: dates, places, relations, words of role, the places the fonds uses. Link each to the right one, make a new one, park it until there is more to go on, or reject it.') }}
        <a href="{{ route('authority.parked') }}">{{ __('Parked names') }}</a></p>
    @if($waiting)
        <form method="post" action="{{ route('authority.promote') }}">@csrf<p>{{ trans_choice(':count proposed name is not in this queue yet.|:count proposed names are not in this queue yet.', $waiting, ['count' => $waiting]) }}
            <button type="submit" style="margin-top: 0">{{ __('Bring them in') }}</button></p></form>
    @endif
    <form method="get" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="aq-state" style="margin-top: 0">{{ __('State') }}</label><select id="aq-state" name="state"><option value="any">{{ __('Any') }}</option>@foreach($states as $s)<option value="{{ $s->notation }}" @selected($state === $s->notation)>{{ $s->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select></div>
        <div><label for="aq-kind" style="margin-top: 0">{{ __('Kind') }}</label><select id="aq-kind" name="kind"><option value="">{{ __('Any') }}</option><option value="agent" @selected($kind === 'agent')>{{ __('People and organisations') }}</option><option value="place" @selected($kind === 'place')>{{ __('Places') }}</option></select></div>
        <div><label style="margin-top: 0"><input type="checkbox" name="mine" value="1" style="width: auto" @checked($mine)> {{ __('Given to me') }}</label></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    <form method="post" action="{{ route('authority.assign') }}">@csrf
        <table><thead><tr><th scope="col"><span class="meta">{{ __('Give') }}</span></th><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('In') }}</th><th scope="col">{{ __('Best candidate') }}</th><th scope="col">{{ __('State') }}</th><th scope="col">{{ __('Given to') }}</th></tr></thead><tbody>
            @forelse($mentions as $m)
                @php($best = $m->candidates->first())
                <tr><td><input type="checkbox" name="mentions[]" value="{{ $m->id }}" style="width: auto" aria-label="{{ __('Give :name to an archivist', ['name' => $m->value()]) }}"></td>
                    <td><a href="{{ route('authority.review', $m) }}"><strong>{{ $m->value() }}</strong></a> <span class="meta">{{ $m->isPlace() ? __('place') : ($m->label() === 'PERSON' ? __('person') : __('organisation')) }}</span></td>
                    <td><a href="{{ route('records.show', $m->record) }}">{{ \App\Relations\EntityKinds::label($m->record) }}</a></td>
                    <td>@if($best){{ $best->display_name }} <span class="meta">{{ number_format((float) $best->composite, 2) }}</span>@else<span class="meta">{{ __('none: make a new one') }}</span>@endif</td>
                    <td>{{ $m->state->label(\App\Support\Vocab::languages()) }}</td><td>{{ $m->assignedTo?->name }}</td></tr>
            @empty
                <tr><td colspan="6">{{ __('No names here.') }}</td></tr>
            @endforelse
        </tbody></table>
        @if($mentions->isNotEmpty() && $archivists->isNotEmpty())
            <label for="aq-archivist">{{ __('Give the ticked names to') }}</label>
            <select id="aq-archivist" name="archivist">@foreach($archivists as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
            <label for="aq-note">{{ __('A note for them') }}</label><input id="aq-note" name="note" maxlength="2000">
            <button type="submit">{{ __('Give them') }}</button>
        @endif
    </form>
    {{ $mentions->links() }}
@endsection
