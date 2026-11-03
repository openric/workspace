{{-- OpenRiC Workspace - an entity's own fields, and describing it through a form template (Heratio's custom fields edit partial and ahg-forms TemplateEditController; roadmap phase 22b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', \App\Relations\EntityKinds::label($entity))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ url($kind.'/'.$entity->id) }}">{{ \App\Relations\EntityKinds::label($entity) }}</a></p>
    <h1>{{ \App\Relations\EntityKinds::label($entity) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    @php($input = function ($d, $name, $current, $id) use ($lang) {
        $type = $d->fieldType->notation;
        $value = $current?->first();
        return match ($type) {
            'textarea' => '<textarea id="'.$id.'" name="'.$name.'" rows="3" cols="60">'.e($value?->value_text ?? $d->default_value).'</textarea>',
            'boolean' => '<input type="hidden" name="'.$name.'" value="0"><input id="'.$id.'" type="checkbox" name="'.$name.'" value="1"'.($value?->value_boolean ? ' checked' : '').'>',
            'concept', 'concepts' => '<select id="'.$id.'" name="'.$name.'[]"'.($type === 'concepts' ? ' multiple size="5"' : '').'><option value="">-</option>'
                .\App\Models\Concept::where('vocabulary_id', $d->vocabulary_id)->whereNull('deprecated_at')->with('labels')->get()->map(fn ($c) => '<option value="'.$c->id.'"'
                .($current?->contains('concept_id', $c->id) ? ' selected' : '').'>'.e($c->label($lang)).'</option>')->join('').'</select>',
            'number' => '<input id="'.$id.'" type="number" step="any" name="'.$name.'" value="'.e($value ? \App\CustomFields\CustomFields::text($value) : $d->default_value).'">',
            'date' => '<input id="'.$id.'" type="date" name="'.$name.'" value="'.e($value?->value_date?->format('Y-m-d') ?? $d->default_value).'">',
            default => '<input id="'.$id.'" '.($type === 'url' ? 'type="url" ' : '').'name="'.$name.($d->is_repeatable ? '[]' : '').'" size="50" value="'.e($value?->value_text ?? $d->default_value).'">',
        };
    })
    @if($template)
        <h2>{{ __('Described through :name', ['name' => $template->name]) }}</h2>
        @if($templates->count() > 1)<form method="get">@foreach($templates as $t)@if($t->id !== $template->id)<a href="{{ route('custom-fields.entity', [$kind, $entity->id, 'template' => $t->id, 'language' => $language]) }}">{{ $t->name }}</a> @endif @endforeach</form>@endif
        <form method="post" action="{{ route('custom-fields.entity.save', [$kind, $entity->id]) }}">@csrf<input type="hidden" name="template" value="{{ $template->id }}">
            <p><label for="language">{{ __('Language of these values (BCP 47)') }}</label> <input id="language" name="language" size="6" value="{{ $language }}"> @include('description._ric', ['term' => 'xml:lang'])</p>
            @foreach($template->fields->groupBy('section_name') as $section => $fields)
                <fieldset>@if($section)<legend>{{ $section }}</legend>@endif
                @foreach($fields as $f)@php($fid = 'tf'.$loop->parent->index.'-'.$loop->index)
                    @if(str_starts_with($f->target, 'custom:'))
                        @php($d = $definitions->firstWhere('field_key', substr($f->target, 7)))
                        @if($d)<p><label for="{{ $fid }}">{{ $f->label ?? $d->label }}@if($f->is_required) *@endif</label> @include('description._ric', ['term' => null])<br>{!! $input($d, 'form['.str_replace(':', '__', $f->target).']', $values[$d->field_key] ?? null, $fid) !!}@if($f->help_text ?? $d->help_text)<br><small>{{ $f->help_text ?? $d->help_text }}</small>@endif</p>@endif
                    @else
                        @php($element = $elements[$f->target] ?? null)
                        <p><label for="{{ $fid }}">{{ $f->label ?? $element?->label($lang) }}@if($f->is_required) *@endif</label> @include('description._ric', ['term' => $element])<br>
                            <textarea id="{{ $fid }}" name="form[{{ $f->target }}]" rows="{{ \App\Description\Elements::nameType($f->target) ? 1 : 3 }}" cols="70">{{ old('form.'.$f->target, $entity->described($f->target, [$language]) ?? $f->default_value) }}</textarea>@if($f->help_text)<br><small>{{ $f->help_text }}</small>@endif</p>
                    @endif
                @endforeach</fieldset>
            @endforeach
            <button type="submit">{{ __('Save') }}</button></form>
    @endif
    @if($definitions->isNotEmpty())
        <h2>{{ __('Own fields') }}</h2>
        <form method="post" action="{{ route('custom-fields.entity.save', [$kind, $entity->id]) }}">@csrf
            @foreach($definitions->groupBy('field_group') as $group => $defs)<fieldset>@if($group)<legend>{{ $group }}</legend>@endif
                @foreach($defs as $d)<p><label for="cf-{{ $d->field_key }}">{{ $d->label }}@if($d->is_required) *@endif</label> @include('description._ric', ['term' => null])<br>{!! $input($d, 'custom['.$d->field_key.']', $values[$d->field_key] ?? null, 'cf-'.$d->field_key) !!}@if($d->help_text)<br><small>{{ $d->help_text }}</small>@endif</p>@endforeach</fieldset>@endforeach
            <button type="submit">{{ __('Save') }}</button></form>
    @endif
@endsection
