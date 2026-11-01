{{-- OpenRiC Workspace - an item of a submission (PSIS's addItem and editItem; roadmap phase 19e). Needs $i (or null), $submission, $levels, $types. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($p = $i ? 'ie' : 'in')
@php($type = $i?->itemType->notation)
@if(! $i)<label for="{{ $p }}-type">{{ __('Kind') }}</label> <select id="{{ $p }}-type" name="item_type">@foreach($types as $c)<option value="{{ $c->notation }}" @selected(old('item_type') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select><br>@endif
<label for="{{ $p }}-title">{{ __('Title, or name') }}</label> <input id="{{ $p }}-title" name="title" size="50" value="{{ old('title', $i?->title) }}">
@if(! $type || $type === 'note')<label for="{{ $p }}-ref">{{ __('For a note: the record (its persistent IRI)') }}</label> <input id="{{ $p }}-ref" name="reference_iri" size="40" value="{{ old('reference_iri', $i?->referenceRecord?->iri) }}">@endif<br>
@if(! $type || $type === 'description')
    <label for="{{ $p }}-id">{{ __('Identifier') }}</label> <input id="{{ $p }}-id" name="identifier" size="15" value="{{ old('identifier', $i?->identifier) }}">
    <label for="{{ $p }}-level">{{ __('Level of description') }}</label> <select id="{{ $p }}-level" name="level"><option value="">{{ __('None') }}</option>@foreach($levels as $c)<option value="{{ $c->iri }}" @selected(old('level', $i?->level?->iri) === $c->iri)>{{ $c->anyLabel($lang) }}</option>@endforeach</select>
    <label for="{{ $p }}-parent">{{ __('Inside') }}</label> <select id="{{ $p }}-parent" name="parent_item_id"><option value="">{{ __('None') }}</option>@foreach($submission->items->filter(fn ($x) => $x->itemType->notation === 'description' && $x->id !== $i?->id) as $x)<option value="{{ $x->id }}" @selected(old('parent_item_id', $i?->parent_item_id) === $x->id)>{{ $x->title }}</option>@endforeach</select><br>
    <label for="{{ $p }}-ds">{{ __('Dates') }}</label> <input id="{{ $p }}-ds" name="date_display" size="25" value="{{ old('date_display', $i?->date_display) }}">
    <label for="{{ $p }}-d1">{{ __('From') }}</label> <input id="{{ $p }}-d1" name="date_start" type="date" value="{{ old('date_start', $i?->date_start?->toDateString()) }}">
    <label for="{{ $p }}-d2">{{ __('To') }}</label> <input id="{{ $p }}-d2" name="date_end" type="date" value="{{ old('date_end', $i?->date_end?->toDateString()) }}"><br>
    <label for="{{ $p }}-ext">{{ __('Extent and medium') }}</label> <input id="{{ $p }}-ext" name="extent_and_medium" size="50" value="{{ old('extent_and_medium', $i?->extent_and_medium) }}"><br>
@endif
<label for="{{ $p }}-scope">{{ $type === 'note' ? __('The note') : ($type === 'creator' ? __('History') : __('Scope and content')) }}</label><br><textarea id="{{ $p }}-scope" name="scope_and_content" rows="4" cols="80">{{ old('scope_and_content', $i?->scope_and_content) }}</textarea><br>
@if(! $type || $type === 'description')
    <label for="{{ $p }}-cr">{{ __('Creators') }}</label> <input id="{{ $p }}-cr" name="creators" size="40" value="{{ old('creators', $i?->creators) }}" aria-describedby="{{ $p }}-hint">
    <label for="{{ $p }}-sub">{{ __('Subjects') }}</label> <input id="{{ $p }}-sub" name="subjects" size="40" value="{{ old('subjects', $i?->subjects) }}" aria-describedby="{{ $p }}-hint"><br>
    <label for="{{ $p }}-pl">{{ __('Places') }}</label> <input id="{{ $p }}-pl" name="places" size="40" value="{{ old('places', $i?->places) }}" aria-describedby="{{ $p }}-hint">
    <label for="{{ $p }}-gen">{{ __('Genres') }}</label> <input id="{{ $p }}-gen" name="genres" size="40" value="{{ old('genres', $i?->genres) }}" aria-describedby="{{ $p }}-hint"> <span id="{{ $p }}-hint" class="meta">{{ __('Separate them with commas.') }}</span><br>
    <label for="{{ $p }}-acc">{{ __('Conditions governing access') }}</label> <input id="{{ $p }}-acc" name="access_conditions" size="50" value="{{ old('access_conditions', $i?->access_conditions) }}"><br>
    <label for="{{ $p }}-rep">{{ __('Conditions governing reproduction') }}</label> <input id="{{ $p }}-rep" name="reproduction_conditions" size="50" value="{{ old('reproduction_conditions', $i?->reproduction_conditions) }}"><br>
@endif
@if(! $type || $type === 'creator')<label for="{{ $p }}-dx">{{ __('Dates of existence') }}</label> <input id="{{ $p }}-dx" name="date_display" size="25" value="{{ old('date_display', $i?->date_display) }}" @if(! $type) disabled hidden @endif>@endif
@if(! $type || $type === 'repository')
    <label for="{{ $p }}-rn">{{ __('Repository name') }}</label> <input id="{{ $p }}-rn" name="repository_name" size="40" value="{{ old('repository_name', $i?->repository_name) }}">
    <label for="{{ $p }}-ra">{{ __('Address') }}</label> <input id="{{ $p }}-ra" name="repository_address" size="40" value="{{ old('repository_address', $i?->repository_address) }}">
    <label for="{{ $p }}-rc">{{ __('Contact') }}</label> <input id="{{ $p }}-rc" name="repository_contact" size="30" value="{{ old('repository_contact', $i?->repository_contact) }}"><br>
@endif
<label for="{{ $p }}-notes">{{ __('Notes') }}</label><br><textarea id="{{ $p }}-notes" name="notes" rows="2" cols="80">{{ old('notes', $i?->notes) }}</textarea><br>
