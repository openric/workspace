{{-- OpenRiC Workspace - a form template: its fields in order and where it applies (Heratio's ahg-forms builder; roadmap phase 22b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $template?->name ?? __('New form template'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('custom-fields.index') }}">{{ __('Own fields and form templates') }}</a></p>
    <h1>{{ $template?->name ?? __('New form template') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('List the fields the form shows, in order: a field left empty is not on the form. A template applies where an assignment below says, the lowest priority number first, or everywhere as the default.') }}</p>
    @php($targets = $elements->map(fn ($e) => $e->label($lang))->all() + $custom->mapWithKeys(fn ($c) => ['custom:'.$c->field_key => $c->label.' ('.__('own field').')'])->all())
    @php($rows = old('fields', $template?->fields->map->only(['target', 'label', 'help_text', 'default_value', 'is_required', 'section_name'])->all() ?? []))
    @php($rows = array_merge(array_values($rows), array_fill(0, 5, [])))
    <form method="post" action="{{ $template ? route('custom-fields.templates.update', $template) : route('custom-fields.templates.store') }}">@csrf
        <input type="hidden" name="entity_table" value="{{ $table }}">
        <p><label for="name">{{ __('Name') }}</label> <input id="name" name="name" size="40" required value="{{ old('name', $template?->name) }}">
            <label><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $template?->is_default))> {{ __('the default') }}</label>
            <input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template?->is_active ?? true))> {{ __('in use') }}</label></p>
        <p><label for="description">{{ __('Description') }}</label> <input id="description" name="description" size="70" value="{{ old('description', $template?->description) }}"></p>
        <table><thead><tr><th scope="col">{{ __('Field') }}</th><th scope="col">{{ __('Label on the form') }}</th><th scope="col">{{ __('Help') }}</th><th scope="col">{{ __('Default') }}</th><th scope="col">{{ __('Section') }}</th><th scope="col">{{ __('Required') }}</th></tr></thead>
            <tbody>@foreach($rows as $n => $f)<tr>
                <td><select name="fields[{{ $n }}][target]" aria-label="{{ __('Field') }}"><option value="">-</option>@foreach($targets as $value => $text)<option value="{{ $value }}" @selected(($f['target'] ?? '') === $value)>{{ $text }}</option>@endforeach</select></td>
                <td><input name="fields[{{ $n }}][label]" size="16" value="{{ $f['label'] ?? '' }}" aria-label="{{ __('Label on the form') }}"></td>
                <td><input name="fields[{{ $n }}][help_text]" size="20" value="{{ $f['help_text'] ?? '' }}" aria-label="{{ __('Help') }}"></td>
                <td><input name="fields[{{ $n }}][default_value]" size="12" value="{{ $f['default_value'] ?? '' }}" aria-label="{{ __('Default') }}"></td>
                <td><input name="fields[{{ $n }}][section_name]" size="12" value="{{ $f['section_name'] ?? '' }}" aria-label="{{ __('Section') }}"></td>
                <td><input type="checkbox" name="fields[{{ $n }}][is_required]" value="1" @checked(! empty($f['is_required'])) aria-label="{{ __('Required') }}"></td></tr>@endforeach</tbody></table>
        <h2>{{ __('Where it applies') }}</h2>
        @php($assignments = array_merge(array_values(old('assignments', $template?->assignments->map(fn ($a) => ['repository_id' => $a->repository_id, 'level' => $a->level?->notation, 'collection_iri' => $a->collection?->iri, 'priority' => $a->priority, 'inherit_to_children' => $a->inherit_to_children])->all() ?? [])), array_fill(0, 2, [])))
        <table><thead><tr><th scope="col">{{ __('Repository') }}</th><th scope="col">{{ __('Level') }}</th><th scope="col">{{ __('Collection (persistent IRI)') }}</th><th scope="col">{{ __('Priority') }}</th><th scope="col">{{ __('Inside it too') }}</th></tr></thead>
            <tbody>@foreach($assignments as $n => $a)<tr>
                <td><select name="assignments[{{ $n }}][repository_id]" aria-label="{{ __('Repository') }}"><option value="">{{ __('any') }}</option>@foreach($repositories as $r)<option value="{{ $r->id }}" @selected(($a['repository_id'] ?? '') === $r->id)>{{ \App\Relations\EntityKinds::label($r) }}</option>@endforeach</select></td>
                <td>@if($table === 'record_resources')<select name="assignments[{{ $n }}][level]" aria-label="{{ __('Level') }}"><option value="">{{ __('any') }}</option>@foreach($levels as $l)<option value="{{ $l->notation }}" @selected(($a['level'] ?? '') === $l->notation)>{{ $l->label($lang) }}</option>@endforeach</select>@endif</td>
                <td>@if($table === 'record_resources')<input name="assignments[{{ $n }}][collection_iri]" size="36" value="{{ $a['collection_iri'] ?? '' }}" aria-label="{{ __('Collection (persistent IRI)') }}">@endif</td>
                <td><input name="assignments[{{ $n }}][priority]" type="number" value="{{ $a['priority'] ?? 100 }}" style="width: 5rem" aria-label="{{ __('Priority') }}"></td>
                <td><input type="hidden" name="assignments[{{ $n }}][inherit_to_children]" value="0"><input type="checkbox" name="assignments[{{ $n }}][inherit_to_children]" value="1" @checked($a['inherit_to_children'] ?? true) aria-label="{{ __('Inside it too') }}"></td></tr>@endforeach</tbody></table>
        <button type="submit">{{ __('Save') }}</button></form>
@endsection
