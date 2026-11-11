{{-- OpenRiC Workspace - requests to DataCite waiting, done and failed (Heratio's DOI queue; roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('DOI queue'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('DOI queue') }}</h1>
    @include('dois._nav')
    <p>{{ __('Requests to DataCite are sent every five minutes. One that fails is tried again later, five minutes more each time, three times in all.') }}</p>
    <form method="post" action="{{ route('dois.process') }}">@csrf<button type="submit">{{ __('Send them now') }}</button></form>
    <form method="get" action="{{ route('dois.queue') }}"><label for="qs">{{ __('Status') }}</label><select id="qs" name="status" onchange="this.form.submit()"><option value="">{{ __('Any') }}</option>@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($status === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select><noscript><button type="submit">{{ __('Show') }}</button></noscript></form>
    <table><thead><tr><th scope="col">{{ __('Unit of description') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Attempts') }}</th><th scope="col">{{ __('Next') }}</th><th scope="col">{{ __('Asked by') }}</th><th scope="col">{{ __('Actions') }}</th></tr></thead>
        <tbody>@forelse($items as $i)<tr><td>@if($i->record)<a href="{{ route('records.show', $i->record) }}">{{ \App\Relations\EntityKinds::label($i->record) }}</a>@endif @if($i->last_error)<br><span class="meta">{{ $i->last_error }}</span>@endif</td>
            <td>{{ $i->action->label($lang) }}</td><td>{{ $i->status->label($lang) }}</td><td>{{ $i->attempts }} / {{ $i->max_attempts }}</td><td>{{ $i->status->notation === 'pending' ? $i->scheduled_at?->timezone(config('workspace.timezone'))->format('Y-m-d H:i') : '' }}</td><td>{{ $i->createdBy?->name }}</td>
            <td>@if($i->status->notation === 'failed')<form method="post" action="{{ route('dois.queue.step', [$i, 'retry']) }}" style="display: inline">@csrf<button type="submit">{{ __('Try again') }}</button></form>@endif
                @if(in_array($i->status->notation, ['pending', 'failed'], true))<form method="post" action="{{ route('dois.queue.step', [$i, 'remove']) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</td></tr>
        @empty<tr><td colspan="6">{{ __('None.') }}</td></tr>@endforelse</tbody></table>
    {{ $items->links() }}
@endsection
