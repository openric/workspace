{{-- OpenRiC Workspace - captured e-mail (Heratio's e-mail capture queue; roadmap phase 13c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Captured e-mail'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Captured e-mail') }}</h1>
    @include('records-management._nav')
    <form method="post" action="{{ route('records-management.emails.capture') }}" enctype="multipart/form-data">@csrf
        <label for="e-files">{{ __('Messages to capture (.eml or Outlook .msg)') }}</label> <input id="e-files" name="files[]" type="file" multiple required accept=".eml,.msg,message/rfc822,application/vnd.ms-outlook">
        <button type="submit">{{ __('Capture') }}</button></form>
    <table><thead><tr><th scope="col">{{ __('Subject') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('Sent') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('File plan') }}</th></tr></thead>
        <tbody>@forelse($emails as $e)<tr><td><a href="{{ route('records-management.email', $e) }}">{{ $e->subject ?? __('[No subject]') }}</a>@if($e->attachment_count) <span class="meta">({{ trans_choice(':count attachment|:count attachments', $e->attachment_count, ['count' => $e->attachment_count]) }})</span>@endif</td>
            <td>{{ $e->from_address }}</td><td>{{ $e->sent_at?->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</td><td>{{ $e->status->label($lang) }}@if($e->record) · <a href="{{ route('records.show', $e->record) }}">{{ __('the record') }}</a>@endif</td><td>{{ $e->node?->code }}</td></tr>
        @empty<tr><td colspan="5">{{ __('None captured.') }}</td></tr>@endforelse</tbody></table>
    {{ $emails->links() }}
@endsection
