{{-- OpenRiC Workspace - a record's share links (roadmap phase 19a, ADR 0017), after Heratio's share link issue panel (ahg-share-link): issue one for someone outside the institution, see each and its uses, revoke it. Needs: $record. Shown to who may change the record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($links = \App\Models\ShareLink::where('record_resource_id', $record->id)->with('issuedBy')->orderByDesc('created_at')->get())
<section id="share-links">
    <h2>{{ __('Share links') }} @include('description._ric', ['term' => null])</h2>
    @error('share_link')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if(session('share_link') && str_contains(session('share_link'), '/shared/'))<p role="status"><strong>{{ session('status') }}</strong> <code>{{ session('share_link') }}</code></p>@endif
    @forelse($links as $l)
        <p>{{ $l->recipient_email ?? __('No recipient named') }} · {{ __('issued by :who', ['who' => $l->issuedBy?->name ?? '-']) }} ·
            {{ __('until :date', ['date' => $l->expires_at->format('Y-m-d')]) }} · {{ $l->max_access ? __(':used of :max uses', ['used' => $l->access_count, 'max' => $l->max_access]) : trans_choice(':count use|:count uses', $l->access_count, ['count' => $l->access_count]) }}
            @if($l->allows_download) · {{ __('files may be received') }}@endif
            @if($l->revoked_at) · <strong>{{ __('revoked') }}</strong>@if($l->revoke_reason): {{ $l->revoke_reason }}@endif
            @elseif($l->expires_at->isPast()) · <strong>{{ __('expired') }}</strong>
            @else <form method="post" action="{{ route('share-links.revoke', $l) }}" style="display: inline">@csrf<input name="reason" size="20" aria-label="{{ __('Why it is revoked') }}" placeholder="{{ __('Why') }}"> <button type="submit">{{ __('Revoke') }}</button></form>@endif
            @if($l->recipient_note)<br><small>{{ $l->recipient_note }}</small>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('Share it with someone outside the institution') }}</summary>
        <form method="post" action="{{ route('records.share-links.issue', $record) }}">@csrf
            <p class="meta">{{ __('Whoever holds the link sees the record as you may read it, until the link ends or is revoked.') }}</p>
            <p><label for="sl-email">{{ __('Recipient (email, for your records)') }}</label> @include('description._ric', ['term' => null]) <input id="sl-email" name="recipient_email" type="email" size="30">
                <label for="sl-note">{{ __('Note') }}</label> @include('description._ric', ['term' => null]) <input id="sl-note" name="recipient_note" size="40"></p>
            <p><label for="sl-expires">{{ __('Until') }}</label> @include('description._ric', ['term' => null]) <input id="sl-expires" type="date" name="expires_on" value="{{ now()->addDays(\App\Research\ShareLinks::DEFAULT_DAYS)->format('Y-m-d') }}">
                <label for="sl-max">{{ __('At most this many uses (empty: no limit)') }}</label> @include('description._ric', ['term' => null]) <input id="sl-max" type="number" min="1" name="max_access" style="width: 6rem">
                <label><input type="hidden" name="allows_download" value="0"><input type="checkbox" name="allows_download" value="1"> {{ __('Its files may be received') }}</label> @include('description._ric', ['term' => null])</p>
            <button type="submit">{{ __('Make the link') }}</button></form>
    </details>
</section>
