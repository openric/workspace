{{-- OpenRiC Workspace - an input for a field of a register (roadmap phase 18a). Needs: $field, $spec, $entry (or null), $lang. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php([$type, $label] = $spec)
@php($required = ($spec[2] ?? null) === 'required')
@php($raw = $entry?->getAttribute($field))
@php($current = old($field, match ($type) {
    'vocab' => $entry?->{\Illuminate\Support\Str::camel(substr($field, 0, -3))}?->notation,
    'jurisdiction' => $entry?->jurisdiction?->code ?? 'popia',
    'date' => $raw?->format('Y-m-d'),
    'datetime' => $raw?->format('Y-m-d\TH:i'),
    default => $raw,
}))
<p>
@if($type === 'bool')
    <label><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked($current)> {{ __($label) }}</label>
@else
    <label for="f-{{ $field }}">{{ __($label) }}@if($required) *@endif</label><br>
    @switch($type)
        @case('textarea')<textarea id="f-{{ $field }}" name="{{ $field }}" rows="3" cols="70" @required($required)>{{ $current }}</textarea>@break
        @case('vocab')<select id="f-{{ $field }}" name="{{ $field }}" @required($required)><option value="">-</option>@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.$spec[3].'/')->get() as $c)<option value="{{ $c->notation }}" @selected($current === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>@break
        @case('jurisdiction')<select id="f-{{ $field }}" name="{{ $field }}" @required($required)>@foreach(\App\Models\PrivacyJurisdiction::where('is_active', true)->orderBy('position')->get() as $j)<option value="{{ $j->code }}" @selected($current === $j->code)>{{ $j->name }}: {{ $j->full_name }}</option>@endforeach</select>@break
        @case('user')<select id="f-{{ $field }}" name="{{ $field }}"><option value="">-</option>@foreach(\App\Models\User::where('institution_id', auth()->user()->institution_id)->whereNull('disabled_at')->orderBy('name')->get() as $u)<option value="{{ $u->id }}" @selected($current === $u->id)>{{ $u->name }}</option>@endforeach</select>@break
        @case('officer')<select id="f-{{ $field }}" name="{{ $field }}"><option value="">-</option>@foreach(\App\Models\PrivacyOfficer::where('institution_id', auth()->user()->institution_id)->orderBy('name')->get() as $o)<option value="{{ $o->id }}" @selected($current === $o->id)>{{ $o->name }}</option>@endforeach</select>@break
        @case('processing')<select id="f-{{ $field }}" name="{{ $field }}"><option value="">-</option>@foreach(\App\Models\ProcessingActivity::where('institution_id', auth()->user()->institution_id)->orderBy('name')->get() as $a)<option value="{{ $a->id }}" @selected($current === $a->id)>{{ $a->name }}</option>@endforeach</select>@break
        @case('date')<input id="f-{{ $field }}" type="date" name="{{ $field }}" value="{{ $current }}" @required($required)>@break
        @case('datetime')<input id="f-{{ $field }}" type="datetime-local" name="{{ $field }}" value="{{ $current }}" @required($required)>@break
        @case('number')<input id="f-{{ $field }}" type="number" min="0" name="{{ $field }}" value="{{ $current }}">@break
        @case('money')<input id="f-{{ $field }}" type="number" min="0" step="0.01" name="{{ $field }}" value="{{ $current }}">@break
        @default<input id="f-{{ $field }}" name="{{ $field }}" size="50" value="{{ $current }}" @required($required)>
    @endswitch
@endif
</p>
