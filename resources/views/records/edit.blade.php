{{-- OpenRiC Workspace - describe a unit. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Describe'))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">{{ __('Back') }}</a></p>
    <h1>{{ __('Describe :name', ['name' => $record->described('title', [$language, 'en'])]) }}</h1>
    <p>{{ __('Values in') }} <strong>{{ $language }}</strong>{{ __('. Fill in what you know; empty fields are left out. A value you change goes back to draft.') }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('records.update', $record) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <label for="identifier">{{ __('Reference code') }} (3.1.1)</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="identifier" name="identifier" value="{{ old('identifier', $record->identifier) }}">
        <label for="level">{{ __('Level of description') }} (3.1.4)</label> <small>{{ __('(each choice names its RiC-O class)') }}</small>
        <select id="level" name="level" style="font: inherit; padding: .5rem">
            @foreach($levels as $level)<option value="{{ $level->iri }}" @selected(old('level', $record->level->iri) === $level->iri)>{{ $level->label(\App\Support\Vocab::languages()) }} ({{ $level->ricTerm() ?? 'rico:RecordResource' }})</option>@endforeach
        </select>
        @include('description._fields', ['subject' => $record, 'standard' => \App\Description\Elements::ISAD_G, 'language' => $language])
        @if($mayUpdate)
        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Description control') }}</strong></legend>
            <label for="description_identifier">{{ __('Description identifier') }}</label> @include('description._ric', ['term' => null])
            <input id="description_identifier" name="description_identifier" maxlength="255" value="{{ old('description_identifier', $record->description_identifier) }}">
            <label for="source_standard">{{ __('Source standard') }}</label> @include('description._ric', ['term' => null])
            <small id="source-standard-help">{{ __('Record the standard used when entering the description, such as ISAD(G), RAD or DACS.') }}</small>
            <input id="source_standard" name="source_standard" maxlength="255" aria-describedby="source-standard-help" value="{{ old('source_standard', $record->source_standard) }}">
            <label for="display_as_compound">{{ __('View children as compound?') }}</label>
            <small id="compound-help">{{ __('Yes: the public page shows the pictures of the units inside as one strip. No: they are reached one by one.') }}</small>
            <select id="display_as_compound" name="display_as_compound" aria-describedby="compound-help" style="font: inherit; padding: .5rem">
                <option value="1" @selected(old('display_as_compound', $record->display_as_compound ? '1' : '0') === '1')>{{ __('Yes') }}</option>
                <option value="0" @selected(old('display_as_compound', $record->display_as_compound ? '1' : '0') === '0')>{{ __('No') }}</option>
            </select>
            <label for="description_status">{{ __('Status') }}</label> @include('description._ric', ['term' => null])
            <select id="description_status" name="description_status" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($statuses as $status)<option value="{{ $status->iri }}" @selected(old('description_status', $record->descriptionStatus?->iri) === $status->iri)>{{ $status->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select>
            <label for="description_detail">{{ __('Level of detail') }}</label> @include('description._ric', ['term' => null])
            <select id="description_detail" name="description_detail" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($details as $detail)<option value="{{ $detail->iri }}" @selected(old('description_detail', $record->descriptionDetail?->iri) === $detail->iri)>{{ $detail->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select>
        </fieldset>
        @endif
        @if($mayUpdate)
        <fieldset>
            <legend>{{ __('Watermark') }}</legend>
            <small id="watermark-help">{{ __('Written on the reference copies of this description\'s images, instead of the institution\'s. Empty follows the institution\'s watermark.') }}</small>
            @php($current = $watermark ? ($watermark->enabled ? $watermark->watermarkType?->notation : 'none') : null)
            <label for="watermark">{{ __('Watermark for this description') }}</label>
            <select id="watermark" name="watermark" aria-describedby="watermark-help" style="font: inherit; padding: .5rem">
                <option value="">{{ __('The institution\'s') }}</option>
                <option value="none" @selected(old('watermark', $current) === 'none')>{{ __('None') }}</option>
                @foreach($watermarkTypes as $t)<option value="{{ $t->notation }}" @selected(old('watermark', $current) === $t->notation)>{{ $t->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select>
            <label for="watermark_position">{{ __('Position') }}</label>
            <select id="watermark_position" name="watermark_position" style="font: inherit; padding: .5rem">
                <option value="">{{ __('The institution\'s') }}</option>
                @foreach($positions as $p)<option value="{{ $p->notation }}" @selected(old('watermark_position', $watermark?->watermarkPosition?->notation) === $p->notation)>{{ $p->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select>
            <label for="watermark_opacity">{{ __('Opacity (per cent)') }}</label>
            <input id="watermark_opacity" name="watermark_opacity" type="number" min="1" max="100" value="{{ old('watermark_opacity', $watermark?->opacity) }}" placeholder="{{ __('The institution\'s') }}">
            <label style="font-weight: normal"><input type="hidden" name="remake_copies" value="0"><input type="checkbox" name="remake_copies" value="1" style="width: auto"> {{ __('Make the viewing copies of its images again') }}</label>
        </fieldset>
        @endif
        @if($mayCreateInside)
        <fieldset>
            <legend>{{ __('Add new child levels') }}</legend>
            <small>{{ __('Each row with a title becomes a unit inside this one when you save. To add more, save and edit again.') }}</small>
            <table>
                <thead><tr><th scope="col">{{ __('Identifier') }}</th><th scope="col">{{ __('Level of description') }}</th><th scope="col">{{ __('Title') }}</th></tr></thead>
                <tbody>
                @for($i = 0; $i < 3; $i++)
                    <tr><td><input name="child_levels[{{ $i }}][identifier]" maxlength="255" aria-label="{{ __('Identifier') }}" value="{{ old("child_levels.$i.identifier") }}"></td>
                        <td><select name="child_levels[{{ $i }}][level]" aria-label="{{ __('Level of description') }}" style="font: inherit; padding: .5rem"><option value=""></option>
                            @foreach($levels as $level)<option value="{{ $level->iri }}" @selected(old("child_levels.$i.level") === $level->iri)>{{ $level->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select></td>
                        <td><input name="child_levels[{{ $i }}][title]" maxlength="2000" aria-label="{{ __('Title') }}" value="{{ old("child_levels.$i.title") }}"></td></tr>
                @endfor
                </tbody>
            </table>
        </fieldset>
        @endif
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
