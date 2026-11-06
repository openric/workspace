{{-- OpenRiC Workspace - what the collection holds in a language, and its community glossary (roadmap phase 17d4), after Heratio's language corpus page (ahg-semantic-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $label)
@section('content')
    <p><a href="{{ route('discover.languages') }}">{{ __('Languages of the collection') }}</a></p>
    <h1 lang="{{ $code }}">{{ $label }}</h1>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <h2>{{ __('Records described in it') }}</h2>
    <ul>@forelse($records as $r)<li><a href="{{ route('discover.record', $r['id']) }}" lang="{{ $code }}">{{ $r['title'] }}</a>@if($r['snippet'])<br><span class="meta" lang="{{ $code }}">{{ $r['snippet'] }}</span>@endif</li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
    <h2>{{ __('Terms and places named in it') }}</h2>
    <p lang="{{ $code }}">@forelse($terms as $t)@if($t['kind'] === 'place')<a href="{{ route('discover.place', $t['id']) }}">{{ $t['name'] }}</a>@else{{ $t['name'] }}@endif{{ $loop->last ? '' : ' · ' }}@empty{{ __('None.') }}@endforelse</p>
    <h2>{{ __('Text in the language') }}</h2>
    @forelse($transcriptions as $t)<blockquote lang="{{ $code }}" style="white-space: pre-line">{{ $t['text'] }}</blockquote><p><a href="{{ route('discover.record', $t['id']) }}">{{ __('Open the record') }}</a>@if($translatable) · <button type="button" class="mt" data-text="{{ $t['text'] }}">{{ __('Translate by machine') }}</button><span class="mt-out"></span>@endif</p>
    @empty<p>{{ __('No transcript of a page is in this language yet.') }}</p>@endforelse
    @foreach($contributed as $c)<blockquote lang="{{ $c->language }}" style="white-space: pre-line">{{ $c->body }}</blockquote><p class="meta">{{ $c->type->label(\App\Support\Vocab::languages()) }} · {{ $c->contributor_name ?: __('Anonymous contributor') }} · <a href="{{ route('discover.record', $c->record_resource_id) }}">{{ __('Open the record') }}</a></p>@endforeach
    <h2>{{ __('Community glossary') }}</h2>
    @if($glossary->isEmpty())<p>{{ __('No glossary entries yet. Be the first to contribute.') }}</p>@else
    <dl>@foreach($glossary as $g)<dt lang="{{ $code }}">{{ $g->term }}</dt><dd>{{ $g->meaning }}@if($g->usage_example)<br><em lang="{{ $code }}">{{ $g->usage_example }}</em>@endif @if($g->contributor_name)<br><small>{{ $g->contributor_name }}</small>@endif</dd>@endforeach</dl>
    @endif
    <details><summary>{{ __('Add a word to the glossary') }}</summary>
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <form method="post" action="{{ route('discover.languages.glossary', $code) }}">@csrf
            <div style="position: absolute; left: -10000px" aria-hidden="true"><label for="lg-website">Website</label><input id="lg-website" name="website" tabindex="-1" autocomplete="off"></div>
            <p><label for="lg-term">{{ __('Term') }}</label> <input id="lg-term" name="term" required maxlength="512" lang="{{ $code }}" value="{{ old('term') }}"></p>
            <p><label for="lg-meaning">{{ __('What it means') }}</label><br><textarea id="lg-meaning" name="meaning" required rows="3" cols="70">{{ old('meaning') }}</textarea></p>
            <p><label for="lg-usage">{{ __('An example of its use (optional)') }}</label><br><textarea id="lg-usage" name="usage_example" rows="2" cols="70" lang="{{ $code }}">{{ old('usage_example') }}</textarea></p>
            <p><label for="lg-source">{{ __('Source or attribution (optional)') }}</label> <input id="lg-source" name="source" maxlength="512" value="{{ old('source') }}">
                <label for="lg-name">{{ __('Your name (optional)') }}</label> <input id="lg-name" name="contributor_name" maxlength="255" value="{{ old('contributor_name') }}"></p>
            <button type="submit">{{ __('Submit for review') }}</button>
        </form>
    </details>
    @if($translatable)
    <p class="meta">{{ __($mtLabel) }}</p>
    <script>
        document.querySelectorAll('button.mt').forEach(b => b.addEventListener('click', async () => {
            const out = b.nextElementSibling; out.textContent = ' ...';
            const r = await fetch(@json(route('discover.languages.translate', $code)), {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json'},
                body: JSON.stringify({text: b.dataset.text, target: @json(app()->getLocale())})});
            const j = await r.json().catch(() => ({}));
            out.textContent = j.ok ? ' ' + j.text + ' (' + j.label + ')' : ' ' + @json(__('No translation was given.'));
        }));
    </script>
    @endif
@endsection
