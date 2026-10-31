{{-- OpenRiC Workspace - one disposal from initiation to its certificate (roadmap phase 13b, ADR 0015), after Heratio's disposal page and timeline. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Disposal of :name', ['name' => \App\Relations\EntityKinds::label($disposal->record)]))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($s = $disposal->status->notation)
    <p><a href="{{ route('retention.index') }}">{{ __('Retention and disposal') }}</a>@if(! $disposal->record->deleted_at) · <a href="{{ route('records.show', $disposal->record) }}#retention">{{ \App\Relations\EntityKinds::label($disposal->record) }}</a>@endif</p>
    <h1>{{ __('Disposal of :name', ['name' => \App\Relations\EntityKinds::label($disposal->record)]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ $disposal->action->label($lang) }}</strong> · {{ $disposal->status->label($lang) }}@if($disposal->disposalClass) · {{ $disposal->disposalClass->schedule->reference }}: {{ $disposal->disposalClass->reference }} {{ $disposal->disposalClass->title }}@endif</p>
    @if($disposal->reason)<p>{{ $disposal->reason }}</p>@endif
    <ol>
        <li>{{ __('Initiated by :name on :date', ['name' => $disposal->initiatedBy?->name, 'date' => $disposal->initiated_at->format('Y-m-d H:i')]) }}</li>
        @if($disposal->recommended_at)<li>{{ __('Recommended by :name on :date', ['name' => $disposal->recommendedBy?->name, 'date' => $disposal->recommended_at->format('Y-m-d H:i')]) }}</li>@endif
        @if($disposal->approved_at)<li>{{ __('Approved by :name on :date', ['name' => $disposal->approvedBy?->name, 'date' => $disposal->approved_at->format('Y-m-d H:i')]) }}</li>@endif
        @if($disposal->cleared_at)<li>{{ __('Legally cleared by :name on :date', ['name' => $disposal->clearedBy?->name, 'date' => $disposal->cleared_at->format('Y-m-d H:i')]) }}</li>@endif
        @if($disposal->executed_at)<li>{{ __('Carried out by :name on :date', ['name' => $disposal->executedBy?->name, 'date' => $disposal->executed_at->format('Y-m-d H:i')]) }}@if($disposal->transfer_destination): {{ $disposal->transfer_destination }}@endif</li>@endif
    </ol>
    @if($disposal->notes)<p style="white-space: pre-line" class="meta">{{ $disposal->notes }}</p>@endif
    @if($disposal->certificate)<p>{{ __('Certificate of destruction') }}: <a href="{{ route('retention.certificate', $disposal->certificate) }}">{{ $disposal->certificate->number }}</a></p>@endif
    @if($disposal->verification)
        <h2>{{ __('Verification') }} ({{ $disposal->verified_at->format('Y-m-d H:i') }})</h2>
        <ul>@foreach($disposal->verification as $c)<li>{{ $c['passed'] ? __('Yes') : __('No') }}: {{ __($c['check']) }}@if($c['detail']) ({{ $c['detail'] }})@endif</li>@endforeach</ul>
    @endif
    @foreach(array_filter([
        in_array($s, ['pending'], true) ? ['recommend', 'Recommend', false] : null,
        in_array($s, ['pending', 'recommended'], true) ? ['approve', 'Approve', false] : null,
        $s === 'approved' ? ['clear', 'Clear: no legal hold applies', false] : null,
        in_array($s, ['pending', 'recommended', 'approved', 'cleared'], true) ? ['reject', 'Reject', true] : null,
        in_array($s, ['pending', 'recommended'], true) ? ['cancel', 'Cancel', true] : null,
    ]) as [$step, $label, $reason])
        <form method="post" action="{{ route('disposals.step', [$disposal, $step]) }}" style="margin: .3rem 0">@csrf
            <input name="comment" size="40" placeholder="{{ $reason ? __('Reason') : __('Comment') }}" @if($reason) required @endif aria-label="{{ $reason ? __('Reason') : __('Comment') }}">
            <button type="submit">{{ __($label) }}</button></form>
    @endforeach
    @if($s === 'cleared')
        <form method="post" action="{{ route('disposals.step', [$disposal, 'execute']) }}">@csrf
            @if($disposal->action->notation === 'destroy')
                @if($mayDestroy)
                    <p><strong>{{ __('Destroying erases its files for good and removes its description from every list; the certificate keeps what was destroyed.') }}</strong></p>
                    <p><label for="m">{{ __('Method') }}</label> <input id="m" name="method" size="40" value="{{ __('Secure erasure from the file store') }}"> <label for="w">{{ __('Witness') }}</label> <input id="w" name="witness" size="25"></p>
                    <button type="submit" style="background: var(--error)">{{ __('Destroy') }}</button>
                @else<p>{{ __('Only who administers the institution destroys records.') }}</p>@endif
            @elseif($disposal->action->notation === 'transfer')
                <label for="dest">{{ __('Transferred to') }}</label> <input id="dest" name="destination" required size="40"> <button type="submit">{{ __('Record the transfer') }}</button>
            @else
                <label for="rr">{{ __('Reason') }}</label> <input id="rr" name="comment" size="40"> <button type="submit">{{ __('Retain permanently') }}</button>
            @endif
        </form>
    @endif
    @if($disposal->certificate)<form method="post" action="{{ route('disposals.step', [$disposal, 'verify']) }}" style="margin-top: .5rem">@csrf<button type="submit">{{ __('Verify the destruction') }}</button></form>@endif
@endsection
