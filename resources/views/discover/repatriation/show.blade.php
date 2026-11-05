{{-- OpenRiC Workspace - one repatriation claim as a virtual return, with the knowledge contributed about it (roadmap phase 17d2), after Heratio's virtual-return/show and repatriation-knowledge/form (ahg-semantic-search). Needs $claim, $unit, $knowledge, $types. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Virtual return'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('discover.repatriation') }}">{{ __('Back to the repatriation overview') }}</a></p>
    <h1>{{ __('Virtual return') }}: {{ $view->label($unit) }}</h1>
    <p>{{ __('This page brings the object back into its place and community of origin in digital form. It honours that context regardless of where the object is physically held today, and independently of any decision about physical return.') }}</p>
    <h2>{{ __('In its origin context') }}</h2>
    <dl>
        <dt>{{ __('Place of origin') }}</dt><dd>{{ $claim->origin_place ?: '-' }}</dd>
        <dt>{{ __('Community') }}</dt><dd>{{ $claim->claimant_community ?: '-' }}</dd>
        <dt>{{ __('Currently held by:') }}</dt><dd>{{ $claim->current_holder ?: '-' }}</dd>
        <dt>{{ __('Where the dialogue stands') }}</dt><dd>{{ $claim->status->label($lang) }}</dd>
    </dl>
    <p><a href="{{ route('discover.record', $unit->id) }}">{{ __('Open the object record') }}</a></p>
    <p class="meta">{{ __($disclaimer) }}</p>
    <h2>{{ __('Community knowledge') }}</h2>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @forelse($knowledge as $k)<p><strong>{{ $k->type->label($lang) }}</strong> · {{ $k->contributor_name ?: __('Anonymous contributor') }}<br><span style="white-space: pre-line">{{ $k->body }}</span>@if($k->source)<br><small>{{ $k->source }}</small>@endif</p>
    @empty<p>{{ __('No approved community knowledge on this object yet. Be the first to contribute.') }}</p>@endforelse
    <h2>{{ __('Share what you know about this object') }}</h2>
    <p>{{ __('Knowledge about this object belongs to its communities. Oral history, provenance, corrections and links to source communities are welcome; contributions are reviewed before they appear, and you are credited only if you ask to be.') }}</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('discover.repatriation.contribute', $claim->id) }}">@csrf
        {{-- People leave this empty; robots fill it. --}}
        <div style="position: absolute; left: -10000px" aria-hidden="true"><label for="rk-website">Website</label><input id="rk-website" name="website" tabindex="-1" autocomplete="off"></div>
        <p><label for="rk-type">{{ __('Type of knowledge') }}</label> <select id="rk-type" name="type">@foreach($types as $t)<option value="{{ $t->notation }}" @selected(old('type') === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select></p>
        <p><label for="rk-body">{{ __('What do you know?') }}</label><br><textarea id="rk-body" name="body" required rows="6" cols="70" placeholder="{{ __('Share the oral history, provenance, correction or community knowledge here...') }}">{{ old('body') }}</textarea></p>
        <p><label for="rk-source">{{ __('Source or attribution (optional)') }}</label> <input id="rk-source" name="source" maxlength="512" size="50" value="{{ old('source') }}"></p>
        <p><label for="rk-name">{{ __('Your name (optional)') }}</label> <input id="rk-name" name="contributor_name" maxlength="255" value="{{ old('contributor_name') }}">
            <label><input type="checkbox" name="credit_consent" value="1" @checked(old('credit_consent'))> {{ __('Credit me by name for this contribution. If unticked, your contribution appears anonymously.') }}</label></p>
        <button type="submit">{{ __('Submit for review') }}</button>
    </form>
@endsection
