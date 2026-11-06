{{-- OpenRiC Workspace - transcribe, correct, translate or add a note to a record in a language (roadmap phase 17d4), after Heratio's language-transcription form (ahg-semantic-search). Needs $unit, $types, $approved, $language, $languages. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Transcribe or translate'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('discover.record', $unit->id) }}">{{ $view->label($unit) }}</a></p>
    <h1>{{ __('Transcribe or translate') }}</h1>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('discover.transcribe.store', $unit->id) }}">@csrf
        <div style="position: absolute; left: -10000px" aria-hidden="true"><label for="lt-website">Website</label><input id="lt-website" name="website" tabindex="-1" autocomplete="off"></div>
        <p><label for="lt-type">{{ __('Kind of contribution') }}</label> <select id="lt-type" name="type">@foreach($types as $t)<option value="{{ $t->notation }}" @selected(old('type') === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select>
            <label for="lt-language">{{ __('Language') }}</label> <input id="lt-language" name="language" maxlength="35" size="6" list="lt-languages" value="{{ old('language', $language) }}">
            <datalist id="lt-languages">@foreach($languages as $l)<option value="{{ $l['code'] }}">{{ $l['label'] }}</option>@endforeach</datalist></p>
        <p><label for="lt-body">{{ __('Your text') }}</label><br><textarea id="lt-body" name="body" required rows="10" cols="80">{{ old('body') }}</textarea></p>
        <p><label for="lt-source">{{ __('Source or attribution (optional)') }}</label> <input id="lt-source" name="source" maxlength="512" value="{{ old('source') }}"></p>
        <p><label for="lt-name">{{ __('Your name (optional)') }}</label> <input id="lt-name" name="contributor_name" maxlength="255" value="{{ old('contributor_name') }}">
            <label><input type="checkbox" name="credit_consent" value="1" @checked(old('credit_consent'))> {{ __('Credit me by name for this contribution. If unticked, your contribution appears anonymously.') }}</label></p>
        <button type="submit">{{ __('Submit for review') }}</button>
    </form>
    <h2>{{ __('Contributed so far') }}</h2>
    @forelse($approved as $c)<blockquote lang="{{ $c->language }}" style="white-space: pre-line">{{ $c->body }}</blockquote><p class="meta">{{ $c->type->label($lang) }} · {{ \App\Language\Corpus::label($c->language) }} · {{ $c->contributor_name ?: __('Anonymous contributor') }}</p>
    @empty<p>{{ __('Nothing yet.') }}</p>@endforelse
@endsection
