{{-- OpenRiC Workspace - sent mail and held addresses (roadmap phase 7). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Email'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Email') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('What the Workspace sent to the accounts of the institution. An address that bounces, or whose owner marks a message as spam, is held: nothing more is sent to it until the hold is lifted.') }}</p>
    <h2>{{ __('Held addresses') }}</h2>
    @if($held->isEmpty())
        <p>{{ __('None.') }}</p>
    @else
        <ul>
            @foreach($held as $account)
                @php($report = $reports[strtolower($account->email)][0] ?? null)
                <li><strong>{{ $account->name }}</strong> &lt;{{ $account->email }}&gt;
                    <span class="meta">{{ __('Held since :date', ['date' => $account->email_bounced_at->format('Y-m-d H:i')]) }}@if($report) · {{ $report->type->label($lang) }}@if($report->provider) ({{ $report->provider }})@endif @endif</span>
                    @if($report?->reason)<span class="summary">{{ \Illuminate\Support\Str::limit($report->reason, 300) }}</span>@endif
                    <form method="post" action="{{ route('email.clear', $account) }}">@csrf<button type="submit" style="margin-top: 0">{{ __('Send to it again') }}</button></form></li>
            @endforeach
        </ul>
    @endif
    <h2>{{ __('Sent mail') }}</h2>
    <form method="get" action="{{ route('email.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="status" style="margin-top: 0">{{ __('Status') }}</label>
            <select id="status" name="status" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($status === $s->notation)>{{ $s->label($lang) }}</option>@endforeach
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    @if($sent->isEmpty())
        <p>{{ __('No mail.') }}</p>
    @else
        <ul>
            @foreach($sent as $mail)
                <li><strong>{{ $mail->subject ?: class_basename($mail->mailable) }}</strong>
                    <span class="meta">{{ collect([$mail->recipient_email, $mail->status->label($lang), ($mail->sent_at ?? $mail->queued_at ?? $mail->created_at)?->format('Y-m-d H:i')])->filter()->join(' · ') }}</span>
                    @if($mail->error)<span class="summary">{{ \Illuminate\Support\Str::limit($mail->error, 300) }}</span>@endif</li>
            @endforeach
        </ul>
        @if($sent->hasPages())
            <nav aria-label="{{ __('Pages') }}"><p>
                @if($sent->previousPageUrl())<a href="{{ $sent->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
                {{ __('Page :page of :pages', ['page' => $sent->currentPage(), 'pages' => $sent->lastPage()]) }}
                @if($sent->nextPageUrl())<a href="{{ $sent->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
            </p></nav>
        @endif
    @endif
@endsection
