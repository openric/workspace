{{-- OpenRiC Workspace - one export permit application, its workflow and documents (PSIS's ahgSAHRAPlugin view page; roadmap phase 18d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $permit->application_ref)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($status = $permit->status->notation)
    @php($mine = $permit->applicant_id === $user->id)
    <p><a href="{{ route('jurisdiction.permits') }}">{{ __('Export permits') }}</a></p>
    <h1>{{ $permit->application_ref }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <dl>
        <dt>{{ __('Status') }}</dt><dd>{{ $permit->status->label($lang) }}</dd>
        <dt>{{ __('Applicant') }}</dt><dd>{{ $permit->applicant?->name ?? '-' }}</dd>
        @if($permit->approvedBy)<dt>{{ __('Approved by') }}</dt><dd>{{ $permit->approvedBy->name }}@if($permit->approval_notes) · {{ $permit->approval_notes }}@endif</dd>@endif
        <dt>{{ __('Issuing authority') }}</dt><dd>{{ $permit->issuing_authority }}@if($permit->authority_reference) · {{ $permit->authority_reference }}@endif</dd>
        @if($permit->permit_number)<dt>{{ __('Permit number') }}</dt><dd>{{ $permit->permit_number }}</dd>@endif
        @if($permit->conditions)<dt>{{ __('Conditions') }}</dt><dd>{{ $permit->conditions }}</dd>@endif
        @if($permit->authority_notes)<dt>{{ __('The authority\'s notes') }}</dt><dd>{{ $permit->authority_notes }}</dd>@endif
        <dt>{{ __('Records') }}</dt><dd><ul>@foreach($permit->records as $r)<li><a href="{{ route('records.show', $r->record) }}">{{ \App\Relations\EntityKinds::label($r->record) }}</a></li>@endforeach</ul></dd>
    </dl>
    @if($mine && in_array($status, ['draft', 'returned'], true))
        <h2>{{ __('Edit') }}</h2>
        <form method="post" action="{{ route('jurisdiction.permits.update', $permit) }}">@csrf @include('jurisdiction._permit-fields', ['p' => $permit])<button type="submit">{{ __('Save') }}</button></form>
    @endif
    <h2>{{ __('Next step') }}</h2>
    @php($steps = ['submit-for-approval' => __('Submit for approval'), 'approve' => __('Approve'), 'return' => __('Return to the applicant'), 'lodge' => __('Lodged with the authority'),
        'issued' => __('Permit issued'), 'declined' => __('Declined'), 'revoke' => __('Revoked'), 'close' => __('Close: the records are back'), 'cancel' => __('Cancel')])
    @php($open = ['draft' => ['submit-for-approval', 'cancel'], 'returned' => ['submit-for-approval', 'cancel'], 'pending-approval' => ['approve', 'return', 'cancel'], 'approved' => ['lodge', 'cancel'],
        'submitted' => ['issued', 'declined'], 'issued' => ['revoke', 'close'], 'expired' => ['close']][$status] ?? [])
    @forelse($open as $s)
        <form method="post" action="{{ route('jurisdiction.permits.step', [$permit, $s]) }}">@csrf
            @if($s === 'lodge')<label for="s-ref">{{ __('The authority\'s reference') }}</label> <input id="s-ref" name="authority_reference" size="20">@endif
            @if($s === 'issued')<label for="s-num">{{ __('Permit number') }}</label> <input id="s-num" name="permit_number" size="16" required>
                <label for="s-cond">{{ __('Conditions') }}</label> <input id="s-cond" name="conditions" size="30">
                <label for="s-from">{{ __('From') }}</label> <input id="s-from" name="start_date" type="date"> <label for="s-to">{{ __('to') }}</label> <input id="s-to" name="end_date" type="date">@endif
            @unless(in_array($s, ['submit-for-approval', 'lodge', 'close', 'cancel'], true))<label for="s-notes-{{ $s }}">{{ __('Notes') }}</label> <input id="s-notes-{{ $s }}" name="notes" size="30">@endunless
            <button type="submit">{{ $steps[$s] }}</button></form>
    @empty<p>{{ __('None: the application is closed.') }}</p>@endforelse
    <h2>{{ __('Documents') }}</h2>
    <ul>@forelse($permit->documents as $d)<li><a href="{{ route('jurisdiction.permits.document', $d) }}">{{ $d->original_name }}</a>@if($d->kind) · {{ $d->kind }}@endif <span class="meta">{{ \Illuminate\Support\Number::fileSize($d->size_bytes) }}</span></li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
    <form method="post" action="{{ route('jurisdiction.permits.step', [$permit, 'document']) }}" enctype="multipart/form-data">@csrf
        <label for="d-file">{{ __('Add a document') }}</label> <input id="d-file" name="file" type="file" required> <label for="d-kind">{{ __('What it is') }}</label> <input id="d-kind" name="kind" size="20"> <button type="submit">{{ __('Add') }}</button></form>
@endsection
