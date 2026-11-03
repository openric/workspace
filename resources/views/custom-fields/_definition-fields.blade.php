{{-- OpenRiC Workspace - the fields of an institution's own field. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<input type="hidden" name="entity_table" value="{{ $table }}">
<p><label for="{{ $x }}-label">{{ __('Label') }}</label> <input id="{{ $x }}-label" name="label" size="30" required value="{{ old('label', $d?->label) }}">
    <label for="{{ $x }}-key">{{ __('Key') }}</label> <input id="{{ $x }}-key" name="field_key" size="20" pattern="[a-z][a-z0-9_]*" value="{{ old('field_key', $d?->field_key) }}">
    <label for="{{ $x }}-type">{{ __('Kind of value') }}</label> <select id="{{ $x }}-type" name="field_type">@foreach($types as $t)<option value="{{ $t->notation }}" @selected(old('field_type', $d?->fieldType?->notation ?? 'text') === $t->notation)>{{ $t->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
    <label for="{{ $x }}-voc">{{ __('Terms from') }}</label> <select id="{{ $x }}-voc" name="vocabulary_id"><option value="">-</option>@foreach($vocabularies as $v)<option value="{{ $v->id }}" @selected(old('vocabulary_id', $d?->vocabulary_id) === $v->id)>{{ $v->title }}</option>@endforeach</select></p>
<p><label for="{{ $x }}-group">{{ __('Group') }}</label> <input id="{{ $x }}-group" name="field_group" size="16" value="{{ old('field_group', $d?->field_group) }}">
    <label for="{{ $x }}-order">{{ __('Order') }}</label> <input id="{{ $x }}-order" name="sort_order" type="number" value="{{ old('sort_order', $d?->sort_order ?? 0) }}" style="width: 4rem">
    <label for="{{ $x }}-default">{{ __('Default') }}</label> <input id="{{ $x }}-default" name="default_value" size="14" value="{{ old('default_value', $d?->default_value) }}">
    <label for="{{ $x }}-rule">{{ __('Check (regular expression)') }}</label> <input id="{{ $x }}-rule" name="validation_rule" size="16" value="{{ old('validation_rule', $d?->validation_rule) }}"></p>
<p><label for="{{ $x }}-help">{{ __('Help') }}</label> <input id="{{ $x }}-help" name="help_text" size="50" value="{{ old('help_text', $d?->help_text) }}"></p>
<p>@foreach(['is_required' => [__('required'), false], 'is_repeatable' => [__('repeatable'), false], 'is_searchable' => [__('searchable'), false], 'is_visible_public' => [__('shown to the public'), true],
    'is_visible_edit' => [__('on the edit form'), true], 'include_in_export' => [__('exported'), true], 'is_active' => [__('in use'), true]] as $flag => [$text, $default])
    <input type="hidden" name="{{ $flag }}" value="0"><label><input type="checkbox" name="{{ $flag }}" value="1" @checked(old($flag, $d?->$flag ?? $default))> {{ $text }}</label> @endforeach</p>
