{{-- OpenRiC Workspace - the log of inferences: every call a model answered (Heratio's ahg-provenance-ai governance list; roadmap phase 17a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('The log of inferences'))
@section('content')
    <h1>{{ __('The log of inferences') }}</h1>
    <p><a href="{{ route('ai.index') }}">{{ __('AI') }}</a></p>
    <form method="get"><label for="is">{{ __('Service') }}</label><select id="is" name="service"><option value="">{{ __('Any') }}</option>@foreach($services as $s)<option value="{{ $s->notation }}" @selected($service === $s->notation)>{{ $s->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select> <button type="submit">{{ __('Show') }}</button></form>
    <table><thead><tr><th scope="col">{{ __('Receipt') }}</th><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Model') }}</th><th scope="col">{{ __('Confidence') }}</th><th scope="col">{{ __('Proposals') }}</th><th scope="col">{{ __('Asked by') }}</th></tr></thead>
        <tbody>@forelse($inferences as $i)<tr><td><a href="{{ route('ai.inference', $i) }}">{{ $i->seq }}</a>@unless($i->succeeded) <span class="meta">({{ __('not answered') }})</span>@endunless</td>
            <td>{{ $i->occurred_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</td><td>{{ $i->service->label(\App\Support\Vocab::languages()) }}</td><td><code>{{ $i->model }}</code></td>
            <td>{{ $i->confidence !== null ? number_format($i->confidence, 2) : '' }}</td><td>{{ $i->proposals_count ?: '' }}</td><td>{{ $i->user?->name }}</td></tr>
        @empty<tr><td colspan="7">{{ __('No model has been asked anything yet.') }}</td></tr>@endforelse</tbody></table>
    {{ $inferences->links() }}
@endsection
