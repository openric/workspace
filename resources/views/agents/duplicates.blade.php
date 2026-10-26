{{-- OpenRiC Workspace - authority records that may describe the same agent. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Possible duplicates'))
@section('content')
    <p><a href="{{ route('agents.index') }}">{{ __('Authority records') }}</a></p>
    <h1>{{ __('Possible duplicates') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('Records of the same type of entity where the authorised name of one equals a name of the other, ignoring case, spaces and punctuation. Merge a real duplicate; mark a pair that is not one, and it is not offered again.') }}</p>
    @forelse($pairs as [$a, $b])
        <section style="border: 1px solid var(--line); padding: 0 1rem; margin-bottom: 1rem">
            <h2 style="font-size: 1.1rem">{{ $a->agentType->label(\App\Support\Vocab::languages()) }}</h2>
            <div style="display: flex; gap: 2rem; flex-wrap: wrap">
                @foreach([[$a, $b], [$b, $a]] as [$one, $other])
                    <div style="flex: 1; min-width: 14rem">
                        <p><a href="{{ route('agents.show', $one) }}">{{ $one->described('agent-authorised-name', ['en']) }}</a>@if($one->identifier) ({{ $one->identifier }})@endif
                            <br><small>{{ $one->names->count() }} {{ $one->names->count() === 1 ? 'name' : 'names' }}, changed {{ $one->updated_at->format('Y-m-d') }}</small>
                            <br><small>{{ \Illuminate\Support\Str::limit((string) $one->described('agent-history', ['en']), 200) }}</small></p>
                        @if(auth()->user()->may('delete', 'authority', $other))
                            <form method="post" action="{{ route('agents.merge', $other) }}">@csrf<input type="hidden" name="into" value="{{ $one->id }}"><button type="submit">{{ __('Keep this one, merge the other into it') }}</button></form>
                        @endif
                    </div>
                @endforeach
            </div>
            <form method="post" action="{{ route('agents.dismiss', $a) }}">@csrf<input type="hidden" name="other" value="{{ $b->id }}"><button type="submit">{{ __('Not duplicates') }}</button></form>
            <p></p>
        </section>
    @empty
        <p>{{ __('No possible duplicates.') }}</p>
    @endforelse
@endsection
