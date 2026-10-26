{{-- OpenRiC Workspace - export a unit of description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Export :name', ['name' => $record->described('title', ['en'])]))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">{{ __('Back to :name', ['name' => $record->described('title', ['en'])]) }}</a></p>
    <h1>{{ __('Export :name', ['name' => $record->described('title', ['en'])]) }}</h1>
    <p>{{ __('Only the units inside it that you may read are exported. Only accepted or disputed relations are exported, and only those whose other end you may read.') }}</p>
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
                <button type="submit">{{ __('Download :name', ['name' => $name]) }}</button>
            </fieldset>
        </form>
    @endforeach
@endsection
