{{-- OpenRiC Workspace - transfers of records to the National Archives and Records Service of South Africa (Heratio's ahg-narssa transfer pages; roadmap phase 18d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Transfers to the National Archives'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Transfers to the National Archives') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A transfer packages records for the National Archives and Records Service of South Africa: each record\'s description as EAD3 with its master files, a manifest and a METS file with every checksum.') }}</p>
    <table><thead><tr><th scope="col">{{ __('Reference') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Records') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"></th></tr></thead>
        <tbody>@forelse($transfers as $t)<tr><td>{{ $t->reference }}</td><td>{{ $t->title }}@if($t->schedule_codes) <span class="meta">{{ $t->schedule_codes }}</span>@endif</td>
            <td>{{ $t->item_count }} · {{ \Illuminate\Support\Number::fileSize($t->total_size_bytes) }}</td>
            <td>{{ $t->status->label($lang) }}@if($t->receipt_reference) · {{ $t->receipt_reference }}@endif</td>
            <td><a href="{{ route('jurisdiction.narssa.package', $t) }}">{{ __('Package') }}</a>
                @if(in_array($t->status->notation, ['packaged', 'returned'], true))<form method="post" action="{{ route('jurisdiction.narssa.step', [$t, 'transmitted']) }}" style="display: inline">@csrf<button type="submit">{{ __('Transmitted') }}</button></form>@endif
                @if($t->status->notation === 'transmitted')
                    <form method="post" action="{{ route('jurisdiction.narssa.step', [$t, 'accepted']) }}" style="display: inline">@csrf<label for="r-{{ $loop->index }}">{{ __('Receipt') }}</label> <input id="r-{{ $loop->index }}" name="receipt_reference" size="12"> <button type="submit">{{ __('Accepted') }}</button></form>
                    <form method="post" action="{{ route('jurisdiction.narssa.step', [$t, 'returned']) }}" style="display: inline">@csrf<label for="n-{{ $loop->index }}">{{ __('Why') }}</label> <input id="n-{{ $loop->index }}" name="notes" size="16"> <button type="submit">{{ __('Returned') }}</button></form>
                @endif</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('New transfer') }}</h2>
    <form method="post" action="{{ route('jurisdiction.narssa.build') }}">@csrf<input type="hidden" name="from_disposals" value="1"><button type="submit">{{ __('Package every approved transfer disposal') }}</button></form>
    <form method="post" action="{{ route('jurisdiction.narssa.build') }}">@csrf
        <p><label for="t-title">{{ __('Title') }}</label> <input id="t-title" name="title" size="50" value="{{ old('title') }}"></p>
        <p><label for="t-iris">{{ __('Records, by persistent IRI, one a line') }}</label><br><textarea id="t-iris" name="record_iris" rows="5" cols="70">{{ old('record_iris') }}</textarea></p>
        <p><label for="t-desc">{{ __('Description') }}</label><br><textarea id="t-desc" name="description" rows="3" cols="70">{{ old('description') }}</textarea></p>
        <button type="submit">{{ __('Package') }}</button></form>
@endsection
