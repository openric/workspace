{{-- OpenRiC Workspace - the institution's own wording of the interface, by language (Heratio's UI strings; roadmap phase 17c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Interface wording'))
@section('content')
    <h1>{{ __('Interface wording') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Every string of the interface in each language it offers, as the Workspace ships it and in the institution\'s own wording, which only this institution sees. A change you propose waits for whoever administers the institution; they may also change a string at once. Leave a wording empty to go back to the Workspace\'s.') }}</p>
    @if($admin && $pending->isNotEmpty())
        <h2>{{ __('Changes waiting') }}</h2>
        <table><thead><tr><th scope="col">{{ __('String') }}</th><th scope="col">{{ __('Language') }}</th><th scope="col">{{ __('Proposed') }}</th><th scope="col">{{ __('By') }}</th><th scope="col"></th></tr></thead><tbody>
            @foreach($pending as $c)<tr><td>{{ $c->key }}</td><td>{{ $c->locale }}</td><td>{{ $c->new_value ?? __('(the Workspace\'s)') }}</td><td>{{ $c->submitted_by }}</td>
                <td><form method="post" action="{{ route('translation.review', [$c->id, 'accept']) }}" style="display: inline">@csrf<button type="submit" style="margin: 0">{{ __('Accept') }}</button></form>
                    <form method="post" action="{{ route('translation.review', [$c->id, 'reject']) }}" style="display: inline">@csrf<button type="submit" style="margin: 0">{{ __('Reject') }}</button></form></td></tr>@endforeach
        </tbody></table>
    @endif
    <form method="get" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="is-locale" style="margin-top: 0">{{ __('Language') }}</label><select id="is-locale" name="locale">@foreach($locales as $l)<option value="{{ $l }}" @selected($l === $locale)>{{ \App\Support\InterfaceLanguages::name($l) }} ({{ $l }})</option>@endforeach</select></div>
        <div><label for="is-q" style="margin-top: 0">{{ __('Containing') }}</label><input id="is-q" name="q" value="{{ $q }}"></div>
        <div><label style="margin-top: 0"><input type="checkbox" name="missing" value="1" style="width: auto" @checked($missing)> {{ __('Not yet translated') }}</label></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    <p class="meta">{{ trans_choice(':count string|:count strings', $rows->total(), ['count' => $rows->total()]) }}</p>
    @foreach($rows as $r)
        <form method="post" action="{{ route('translation.save') }}" style="border-top: 1px solid var(--line); padding: .4rem 0">@csrf
            <input type="hidden" name="locale" value="{{ $locale }}"><input type="hidden" name="key" value="{{ $r['key'] }}">
            <p style="margin: .2rem 0"><strong>{{ $r['key'] }}</strong>@if($r['pending']) <span class="meta">{{ trans_choice(':count change waiting|:count changes waiting', $r['pending'], ['count' => $r['pending']]) }}</span>@endif
                <a href="{{ route('translation.history', ['locale' => $locale, 'key' => $r['key']]) }}" class="meta">{{ __('History') }}</a></p>
            @if($r['shipped'])<p class="meta" style="margin: .2rem 0">{{ __('The Workspace\'s') }}: {{ $r['shipped'] }}</p>@endif
            <textarea name="value" rows="2" aria-label="{{ __('Our wording') }}" placeholder="{{ $r['shipped'] ?? $r['key'] }}">{{ $r['own'] }}</textarea>
            <button type="submit" style="margin-top: .2rem">{{ $admin ? __('Save') : __('Propose') }}</button>
            @if($suggest && $locale !== 'en' && in_array($locale, (array) config('workspace.ai.translation_targets'), true))<a href="{{ route('translation.suggest', ['key' => $r['key'], 'locale' => $locale]) }}" class="meta">{{ __('Machine suggestion') }}</a>@endif
        </form>
    @endforeach
    {{ $rows->links() }}
@endsection
