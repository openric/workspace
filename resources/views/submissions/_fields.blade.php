{{-- OpenRiC Workspace - a submission's own fields (roadmap phase 19e). Needs $s (or null) and $repositories. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($p = $s ? 'se' : 'sn')
<label for="{{ $p }}-title">{{ __('Title') }}</label> <input id="{{ $p }}-title" name="title" required size="50" value="{{ old('title', $s?->title) }}"><br>
<label for="{{ $p }}-desc">{{ __('Description') }}</label><br><textarea id="{{ $p }}-desc" name="description" rows="3" cols="70">{{ old('description', $s?->description) }}</textarea><br>
<label for="{{ $p }}-repo">{{ __('Repository') }}</label> <select id="{{ $p }}-repo" name="repository_id"><option value="">{{ __('None') }}</option>@foreach($repositories as $r)<option value="{{ $r->id }}" @selected(old('repository_id', $s?->repository_id) === $r->id)>{{ \App\Relations\EntityKinds::label($r, \App\Support\Vocab::languages()) }}</option>@endforeach</select>
<label for="{{ $p }}-parent">{{ __('To go under (a record\'s persistent IRI)') }}</label> <input id="{{ $p }}-parent" name="parent_iri" size="40" value="{{ old('parent_iri', $s?->parentRecord?->iri) }}"><br>
