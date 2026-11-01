{{-- OpenRiC Workspace - the use of the research services (Heratio's research analytics; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research analytics'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($f = $figures)
    <h1>{{ __('Research analytics') }}</h1>
    <form method="get" action="{{ route('research.analytics') }}"><label for="ra-from">{{ __('From') }}</label> <input id="ra-from" name="from" type="date" value="{{ $from->toDateString() }}">
        <label for="ra-to">{{ __('To') }}</label> <input id="ra-to" name="to" type="date" value="{{ $to->toDateString() }}"> <button type="submit">{{ __('Show') }}</button></form>
    <table><tbody>
        <tr><th>{{ __('Researchers registered') }}</th><td>{{ $f['researchers']['registered'] }}</td></tr>
        <tr><th>{{ __('Visits booked') }}</th><td>{{ $f['bookings']['total'] }}</td></tr>
        <tr><th>{{ __('Records asked for') }}</th><td>{{ $f['requests']['total'] }}</td></tr>
        <tr><th>{{ __('Reproduction requests') }}</th><td>{{ $f['reproductions']['total'] }} ({{ __('paid: :amount', ['amount' => number_format($f['reproductions']['paid'], 2)]) }})</td></tr>
        <tr><th>{{ __('Records cited') }}</th><td>{{ $f['citations']['total'] }}</td></tr>
        <tr><th>{{ __('Searches saved') }}</th><td>{{ $f['searches']['saved'] }}</td></tr>
        <tr><th>{{ __('Records collected by researchers') }}</th><td>{{ $f['collected']['total'] }}</td></tr>
        <tr><th>{{ __('Research projects begun') }}</th><td>{{ $f['projects'] }}</td></tr>
    </tbody></table>
    @foreach(['requests' => 'Most asked for', 'citations' => 'Most cited', 'collected' => 'Most collected'] as $k => $l)
        @if($f[$k]['top'])<h2>{{ __($l) }}</h2><ol>@foreach($f[$k]['top'] as $t)<li><a href="{{ route('records.show', $t['record']) }}">{{ \App\Relations\EntityKinds::label($t['record'], $lang) }}</a> ({{ $t['n'] }})</li>@endforeach</ol>@endif
    @endforeach
    @if($f['searches']['top'])<h2>{{ __('Most saved searches') }}</h2><ol>@foreach($f['searches']['top'] as $q => $n)<li><code>{{ $q }}</code> ({{ $n }})</li>@endforeach</ol>@endif
    @if($f['bookings']['by_room'])<h2>{{ __('Visits by room') }}</h2><ul>@foreach($f['bookings']['by_room'] as $room => $n)<li>{{ $room }}: {{ $n }}</li>@endforeach</ul>@endif
    @if($f['researchers']['by_type'])<h2>{{ __('Researchers by kind') }}</h2><ul>@foreach($f['researchers']['by_type'] as $type => $n)<li>{{ $type }}: {{ $n }}</li>@endforeach</ul>@endif
@endsection
