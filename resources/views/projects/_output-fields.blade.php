{{-- OpenRiC Workspace - an output's fields (Heratio's research_output; roadmap phase 19f). Needs $o (or null), $x, $outputTypes, $identifierTypes, $outputStatuses. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-title">{{ __('Title') }}</label> <input id="{{ $x }}-title" name="title" required size="50" value="{{ $o?->title }}">
<label for="{{ $x }}-type">{{ __('Kind') }}</label> <select id="{{ $x }}-type" name="output_type">@foreach($outputTypes as $c)<option value="{{ $c->notation }}" @selected(($o?->outputType->notation ?? 'journal-article') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">@foreach($outputStatuses as $c)<option value="{{ $c->notation }}" @selected(($o?->status->notation ?? 'planned') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select><br>
<label for="{{ $x }}-authors">{{ __('Authors') }}</label> <input id="{{ $x }}-authors" name="authors" size="40" value="{{ $o?->authors }}">
<label for="{{ $x }}-venue">{{ __('Journal, conference or publisher') }}</label> <input id="{{ $x }}-venue" name="venue" size="30" value="{{ $o?->venue }}"><br>
<label for="{{ $x }}-idtype">{{ __('Identifier') }}</label> <select id="{{ $x }}-idtype" name="identifier_type" aria-label="{{ __('Kind of identifier') }}"><option value="">{{ __('None') }}</option>@foreach($identifierTypes as $c)<option value="{{ $c->notation }}" @selected($o?->identifierType?->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
<input id="{{ $x }}-id" name="identifier" size="25" value="{{ $o?->identifier }}" aria-label="{{ __('Identifier') }}">
<label for="{{ $x }}-url">{{ __('Web address') }}</label> <input id="{{ $x }}-url" name="identifier_url" type="url" size="30" value="{{ $o?->identifier_url }}">
<label for="{{ $x }}-date">{{ __('Date') }}</label> <input id="{{ $x }}-date" name="output_date" type="date" value="{{ $o?->output_date?->toDateString() }}"><br>
<label for="{{ $x }}-notes">{{ __('Abstract or notes') }}</label><br><textarea id="{{ $x }}-notes" name="notes" rows="2" cols="70">{{ $o?->notes }}</textarea><br>
