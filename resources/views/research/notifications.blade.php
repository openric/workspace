{{-- OpenRiC Workspace - a researcher's notices and how they want them (Heratio's notifications page; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Notices'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Notices') }}</h1>
    @include('research._head')
    <p>{{ __('Show') }}: <a href="{{ route('research.notifications') }}" @if(! $type) aria-current="page" @endif>{{ __('All') }}</a>@foreach($preferences as $notation => $p) · <a href="{{ route('research.notifications', ['type' => $notation]) }}" @if($type === $notation) aria-current="page" @endif>{{ $p['concept']->label($lang) }}</a>@endforeach</p>
    <form method="post" action="{{ route('research.notifications.read') }}">@csrf<button type="submit">{{ __('Mark all read') }}</button></form>
    <ul>
        @forelse($notices as $n)
            <li>@if(! $n->read_at)<strong>@endif<a href="{{ route('research.notifications.open', $n) }}">{{ $n->title }}</a>@if(! $n->read_at)</strong>@endif
                <span class="meta">{{ $n->type->label($lang) }} · {{ $n->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}</span>
                @if($n->message)<br><span style="white-space: pre-wrap">{{ $n->message }}</span>@endif</li>
        @empty
            <li>{{ __('No notices.') }}</li>
        @endforelse
    </ul>
    <h2>{{ __('How you want them') }}</h2>
    <form method="post" action="{{ route('research.notifications.preferences') }}">@csrf
        <table><thead><tr><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('In the Workspace') }}</th><th scope="col">{{ __('By e-mail') }}</th><th scope="col">{{ __('How often') }}</th></tr></thead>
            <tbody>@foreach($preferences as $notation => $p)<tr><th scope="row">{{ $p['concept']->label($lang) }}</th>
                <td><input type="checkbox" name="prefs[{{ $notation }}][in_app]" value="1" @checked($p['in_app']) aria-label="{{ __('In the Workspace') }}"></td>
                <td><input type="checkbox" name="prefs[{{ $notation }}][email]" value="1" @checked($p['email']) aria-label="{{ __('By e-mail') }}"></td>
                <td><select name="prefs[{{ $notation }}][digest]" aria-label="{{ __('How often') }}">@foreach($digests as $d)<option value="{{ $d->notation }}" @selected($p['digest'] === $d->notation)>{{ $d->label($lang) }}</option>@endforeach</select></td></tr>@endforeach</tbody></table>
        <button type="submit">{{ __('Save') }}</button></form>
@endsection
