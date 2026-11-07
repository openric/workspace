{{-- OpenRiC Workspace - export a unit of description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Export :name', ['name' => $record->described('title', ['en'])]))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">{{ __('Back to :name', ['name' => $record->described('title', ['en'])]) }}</a></p>
    <h1>{{ __('Export :name', ['name' => $record->described('title', ['en'])]) }}</h1>
    <p>{{ __('Only the units inside it that you may read are exported. Only accepted or disputed relations are exported, and only those whose other end you may read.') }}</p>
    <p>{{ __('Each format carries part of a description. Under each, what of this unit it does not carry, found by reading the export itself;') }} <a href="{{ route('records.export.loss', $record) }}">{{ __('the same for every format and RiC-O, as JSON') }}</a>.</p>
    @if(auth()->user()->hasPermission(\App\Support\Vocab::permission('make-offline-catalogues')))
        <p><a href="{{ route('offline-catalogues.index', ['record' => $record->id]) }}">{{ __('Offline catalogue of this unit and everything inside it') }}</a></p>
    @endif
    @foreach($formats as $format => [$name, $holds])
        <form method="get" action="{{ route('records.export.download', [$record, $format]) }}">
            <fieldset>
                <legend>{{ $name }}</legend>
                <p>{{ $holds }}</p>
                <label for="{{ $format }}-language">{{ __('Language') }}</label>
                <select id="{{ $format }}-language" name="language">
                    @foreach($languages as $language)
                        <option value="{{ $language }}" @selected($language === $original)>{{ \Locale::getDisplayLanguage($language, 'en') ?: $language }} ({{ $language }}){{ $language === $original ? ', the original' : '' }}</option>
                    @endforeach
                </select>
                <small>{{ __('A value not written in this language is given in the language it was first written in.') }}</small>
                @if($format === 'csv')
                    <p><label><input type="checkbox" name="rows" value="every-language"> {{ __('One row for each language a unit is described in, each holding only that language\'s values (the culture column)') }}</label></p>
                @endif
                @if($mayDrafts)
                    <p><label><input type="checkbox" name="drafts" value="1"> {{ __('Include draft records') }}</label> <small>{{ __('Otherwise only published values and names are exported.') }}</small></p>
                @endif
                @php($lost = $losses[$format] ?? [])
                <details>
                    <summary>{{ $lost === [] ? __('Everything of this unit you may read is in it.') : trans_choice('{1} :count thing of this unit is not in it.|[2,*] :count things of this unit are not in it.', count($lost), ['count' => count($lost)]) }}</summary>
                    @if($lost !== [])
                        <ul>@foreach($lost as $item)<li>{{ $item['what'] }}: {{ \Illuminate\Support\Str::limit($item['value'], 120) }}</li>@endforeach</ul>
                    @endif
                </details>
                <button type="submit">{{ __('Download :name', ['name' => $name]) }}</button>
            </fieldset>
        </form>
    @endforeach
@endsection
