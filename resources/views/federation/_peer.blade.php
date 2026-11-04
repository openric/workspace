{{-- OpenRiC Workspace - the fields of a federation peer (roadmap phase 16a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-name">{{ __('Name') }}</label><input id="{{ $x }}-name" name="name" value="{{ $p?->name }}" required>
<label for="{{ $x }}-type">{{ __('Kind') }}</label><select id="{{ $x }}-type" name="type">@foreach($types->whereIn('notation', ['institution', 'workspace', 'heratio']) as $t)<option value="{{ $t->notation }}" @selected($p?->type_id === $t->id)>{{ $t->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-inst">{{ __('The institution, where it is on this installation') }}</label><select id="{{ $x }}-inst" name="peer_institution_id"><option value="">-</option>@foreach($institutions as $i)<option value="{{ $i->id }}" @selected($p?->peer_institution_id === $i->id)>{{ $i->name }}</option>@endforeach</select>
<label for="{{ $x }}-base">{{ __('Its address, where it is elsewhere') }}</label><input id="{{ $x }}-base" name="base_url" value="{{ $p?->base_url }}" placeholder="https://archives.example.org">
<label for="{{ $x }}-search">{{ __('Its search address (empty: /federation/search under its address)') }}</label><input id="{{ $x }}-search" name="search_url" value="{{ $p?->search_url }}">
<label for="{{ $x }}-key">{{ __('API key it asks for') }}@if($p?->api_key) ({{ __('kept; empty keeps it') }})@endif</label><input id="{{ $x }}-key" name="api_key" type="password" autocomplete="off">
<label for="{{ $x }}-desc">{{ __('Description') }}</label><input id="{{ $x }}-desc" name="description" value="{{ $p?->description }}">
<label for="{{ $x }}-contact">{{ __('Contact e-mail') }}</label><input id="{{ $x }}-contact" name="contact_email" type="email" value="{{ $p?->contact_email }}">
<label for="{{ $x }}-timeout">{{ __('Wait for it, in milliseconds') }}</label><input id="{{ $x }}-timeout" name="timeout_ms" type="number" min="500" max="60000" value="{{ $p?->timeout_ms ?? 5000 }}">
<label for="{{ $x }}-max">{{ __('Results from it at most') }}</label><input id="{{ $x }}-max" name="max_results" type="number" min="1" max="200" value="{{ $p?->max_results ?? 20 }}">
<label for="{{ $x }}-priority">{{ __('Priority (lower first)') }}</label><input id="{{ $x }}-priority" name="priority" type="number" value="{{ $p?->priority ?? 100 }}">
<label><input type="checkbox" name="is_active" value="1" @checked($p?->is_active ?? true)> {{ __('Active') }}</label>
<label><input type="checkbox" name="search_enabled" value="1" @checked($p?->search_enabled ?? true)> {{ __('Searched') }}</label>
