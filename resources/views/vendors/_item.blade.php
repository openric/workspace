{{-- OpenRiC Workspace - a record's condition and the work on it, in a vendor transaction (Heratio's ahg-vendor _add-item-modal). Needs $item (or null), $x, $grades. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@foreach(['before' => __('Condition before'), 'after' => __('Condition after')] as $when => $label)
    <label for="{{ $x }}-{{ $when }}-grade">{{ $label }}</label>
    <select id="{{ $x }}-{{ $when }}-grade" name="condition_{{ $when }}_grade"><option value="">{{ __('Not graded') }}</option>@foreach($grades as $g)<option value="{{ $g->notation }}" @selected($item?->{'grade'.ucfirst($when)}?->id === $g->id)>{{ $g->label($lang) }}</option>@endforeach</select>
    <textarea name="condition_{{ $when }}" rows="2" aria-label="{{ $label }}">{{ $item?->{'condition_'.$when} }}</textarea>
@endforeach
<label for="{{ $x }}-value">{{ __('Declared value') }}</label><input id="{{ $x }}-value" name="declared_value" value="{{ $item?->declared_value }}" inputmode="decimal">
<label for="{{ $x }}-cost">{{ __('Cost of the work on it') }}</label><input id="{{ $x }}-cost" name="item_cost" value="{{ $item?->item_cost }}" inputmode="decimal">
<label for="{{ $x }}-work">{{ __('Work to be done') }}</label><textarea id="{{ $x }}-work" name="service_description" rows="2">{{ $item?->service_description }}</textarea>
<label for="{{ $x }}-notes">{{ __('Notes on the work') }}</label><textarea id="{{ $x }}-notes" name="service_notes" rows="2">{{ $item?->service_notes }}</textarea>
<label><input type="checkbox" name="service_completed" value="1" @checked($item?->service_completed)> {{ __('Work done') }}</label>
