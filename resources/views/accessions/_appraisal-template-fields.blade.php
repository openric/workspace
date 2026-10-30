{{-- OpenRiC Workspace - the fields of an appraisal template (PSIS's and Heratio's appraisal templates). Needs $t (or null), $prefix, $criteria (one per line), $sectors. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $prefix }}-name">{{ __('Name') }}</label><input id="{{ $prefix }}-name" name="name" required maxlength="255" value="{{ $t?->name }}">
<label for="{{ $prefix }}-description">{{ __('Description') }}</label><input id="{{ $prefix }}-description" name="description" maxlength="2000" value="{{ $t?->description }}">
<label for="{{ $prefix }}-sector">{{ __('Sector') }}</label>
<select id="{{ $prefix }}-sector" name="sector" style="font: inherit; padding: .5rem"><option value="">{{ __('Not set') }}</option>@foreach($sectors as $c)<option value="{{ $c->notation }}" @selected($t?->sector_id === $c->id)>{{ $c->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
<input type="hidden" name="is_default" value="0">
<label style="font-weight: normal"><input type="checkbox" name="is_default" value="1" @checked($t?->is_default) style="width: auto"> {{ __('Default') }}</label>
<label for="{{ $prefix }}-criteria">{{ __('Criteria') }}</label><textarea id="{{ $prefix }}-criteria" name="criteria" rows="7" required aria-describedby="criteria-help" style="width: 100%; font: inherit">{{ $criteria }}</textarea>
