{{-- OpenRiC Workspace - a unit's elements translated by machine into another language, each proposed for review (Heratio's side-by-side translate page; roadmap phase 17c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Translate :title', ['title' => $record->described('title', ['en'])]))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">{{ __('Back to :name', ['name' => $record->described('title', ['en'])]) }}</a></p>
    <h1>{{ __('Translate with AI') }}</h1>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    @if($refusal)<p role="status">{{ $refusal }}</p>@endif
    <p>{{ __('The elements you choose are translated by the gateway\'s translation model (NLLB-200), one at a time, from the language they are described in. Each translation waits in the proposals queue as the element in the other language, for you to accept, correct or reject. An element that already has a value in that language is left.') }}</p>
    <form method="post" action="{{ route('records.translate.start', $record) }}">@csrf
        <label for="tr-source">{{ __('From') }}</label>
        <select id="tr-source" name="source">@foreach($languages as $l)<option value="{{ $l }}" @selected($l === $source)>{{ \App\Support\InterfaceLanguages::name($l) }} ({{ $l }})</option>@endforeach</select>
        <label for="tr-target">{{ __('Into') }}</label>
        <select id="tr-target" name="target">@foreach($targets as $code => $name)<option value="{{ $code }}" @selected($code === $target)>{{ $name }} ({{ $code }})</option>@endforeach</select>
        <fieldset><legend>{{ __('Elements') }}</legend>
            @foreach($elements as $e)
                @php($text = $record->describedIn($e->notation, $source))
                <label><input type="checkbox" name="elements[]" value="{{ $e->notation }}" style="width: auto" @checked(in_array($e->notation, $chosen, true) && $text) @disabled(! $text)>
                    {{ \App\Description\Elements::number($e) }} {{ $e->label(\App\Support\Vocab::languages()) }}@if($text) <span class="meta">{{ \Illuminate\Support\Str::limit(strip_tags($text), 90) }}</span>@endif</label>
            @endforeach
        </fieldset>
        <button type="submit" @disabled($refusal)>{{ __('Translate them') }}</button>
    </form>
@endsection
