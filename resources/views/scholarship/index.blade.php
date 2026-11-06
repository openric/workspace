{{-- OpenRiC Workspace - the discoveries a model made in the catalogue graph (roadmap phase 17d4), after Heratio's scholarship/discoveries (ahg-semantic-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Discoveries'))
@section('content')
    <h1>{{ __('Discoveries') }}</h1>
    <p>{{ __('A model shown only the verified links around a published record, and told to use nothing else, says what connections a cataloguer might not have spotted. Nothing here is public: a curator makes the strongest into research leads.') }}</p>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><a href="{{ route('scholarship.leads') }}">{{ __('Research leads') }}</a> ({{ __('Pending review') }}: {{ $counts['pending'] ?? 0 }}, {{ __('Published') }}: {{ $counts['published'] ?? 0 }})</p>
    @if($refusal)<p class="meta">{{ $refusal }}</p>@else
    <form method="post" action="{{ route('scholarship.generate') }}">@csrf <label for="sg-limit">{{ __('Refresh the discoveries of the most connected records, at most') }}</label> <input id="sg-limit" name="limit" type="number" min="1" max="200" value="25" style="width: 5rem"> <button type="submit">{{ __('Refresh') }}</button></form>
    @endif
    @if($discoveries->isEmpty())<p>{{ __('None yet.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Connections') }}</th><th scope="col">{{ __('Confidence') }}</th><th scope="col">{{ __('What the model found') }}</th><th scope="col">{{ __('Generated') }}</th></tr></thead>
        <tbody>@foreach($discoveries as $d)<tr><td><a href="{{ route('scholarship.show', $d->record) }}">{{ $d->title }}</a></td><td>{{ $d->connection_count }}</td>
            <td>{{ __(\App\Scholarship\Scholarship::band($d->confidence)['label']) }} ({{ $d->confidence }})</td><td style="white-space: pre-line">{{ \Illuminate\Support\Str::limit((string) $d->summary, 300) }}</td><td>{{ $d->generated_at->format('Y-m-d') }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
