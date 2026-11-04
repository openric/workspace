{{-- OpenRiC Workspace - the fields of a federation peer (roadmap phase 16a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-name">{{ __('Name') }}</label><input id="{{ $x }}-name" name="name" value="{{ $p?->name }}" required>
<label for="{{ $x }}-type">{{ __('Kind') }}</label><select id="{{ $x }}-type" name="type">@foreach($types as $t)<option value="{{ $t->notation }}" @selected($p?->type_id === $t->id)>{{ $t->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-inst">{{ __('The institution, where it is on this installation') }}</label><select id="{{ $x }}-inst" name="peer_institution_id"><option value="">-</option>@foreach($institutions as $i)<option value="{{ $i->id }}" @selected($p?->peer_institution_id === $i->id)>{{ $i->name }}</option>@endforeach</select>
<label for="{{ $x }}-base">{{ __('Its address, where it is elsewhere (an OAI-PMH peer: its OAI-PMH address; Alma: its SRU address)') }}</label><input id="{{ $x }}-base" name="base_url" value="{{ $p?->base_url }}" placeholder="https://archives.example.org">
<label for="{{ $x }}-search">{{ __('Its search address (empty: /federation/search under its address)') }}</label><input id="{{ $x }}-search" name="search_url" value="{{ $p?->search_url }}">
<label for="{{ $x }}-key">{{ __('API key it asks for') }}@if($p?->api_key) ({{ __('kept; empty keeps it') }})@endif</label><input id="{{ $x }}-key" name="api_key" type="password" autocomplete="off">
<label for="{{ $x }}-desc">{{ __('Description') }}</label><input id="{{ $x }}-desc" name="description" value="{{ $p?->description }}">
<label for="{{ $x }}-contact">{{ __('Contact e-mail') }}</label><input id="{{ $x }}-contact" name="contact_email" type="email" value="{{ $p?->contact_email }}">
<label for="{{ $x }}-timeout">{{ __('Wait for it, in milliseconds') }}</label><input id="{{ $x }}-timeout" name="timeout_ms" type="number" min="500" max="60000" value="{{ $p?->timeout_ms ?? 5000 }}">
<label for="{{ $x }}-max">{{ __('Results from it at most') }}</label><input id="{{ $x }}-max" name="max_results" type="number" min="1" max="200" value="{{ $p?->max_results ?? 20 }}">
<label for="{{ $x }}-priority">{{ __('Priority (lower first)') }}</label><input id="{{ $x }}-priority" name="priority" type="number" value="{{ $p?->priority ?? 100 }}">
<label><input type="checkbox" name="is_active" value="1" @checked($p?->is_active ?? true)> {{ __('Active') }}</label>
<label><input type="checkbox" name="search_enabled" value="1" @checked($p?->search_enabled ?? true)> {{ __('Searched') }}</label>
<details><summary>{{ __('Harvest (OAI-PMH)') }}</summary>
<label for="{{ $x }}-prefix">{{ __('Metadata format') }}</label><input id="{{ $x }}-prefix" name="metadata_prefix" value="{{ $p?->metadata_prefix }}" placeholder="oai_dc">
<label for="{{ $x }}-set">{{ __('Set') }}</label><input id="{{ $x }}-set" name="default_set" value="{{ $p?->default_set }}">
<label for="{{ $x }}-interval">{{ __('Harvest every so many hours (empty: only when asked)') }}</label><input id="{{ $x }}-interval" name="harvest_interval_hours" type="number" min="1" value="{{ $p?->harvest_interval_hours }}">
</details>
<details><summary>{{ __('DSpace and Alma') }}</summary>
<label for="{{ $x }}-rest">{{ __('DSpace REST address (empty: /server/api under its address)') }}</label><input id="{{ $x }}-rest" name="rest_base_url" value="{{ $p?->config['rest_base_url'] ?? '' }}">
<label for="{{ $x }}-tpl">{{ __('Alma record address, with {mms_id}') }}</label><input id="{{ $x }}-tpl" name="record_url_template" value="{{ $p?->config['record_url_template'] ?? '' }}">
</details>
<details><summary>{{ __('Governance') }}</summary>
<label for="{{ $x }}-trust">{{ __('Trust') }}</label><select id="{{ $x }}-trust" name="trust_level"><option value="">{{ __('Not set') }}</option>@foreach($trustLevels as $t)<option value="{{ $t->notation }}" @selected($p?->trust_level_id === $t->id)>{{ $t->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-rate">{{ __('Ask it at most once every so many seconds') }}</label><input id="{{ $x }}-rate" name="rate_limit_seconds" type="number" min="1" value="{{ $p?->rate_limit_seconds }}">
<fieldset><legend>{{ __('It may be asked for (none ticked: all it offers)') }}</legend>
@foreach(['search' => __('Search'), 'union' => __('Union catalogue'), 'vocabularies' => __('Vocabularies')] as $surface => $label)<label style="display: inline-block; margin-right: 1rem"><input type="checkbox" name="allowed_surfaces[]" value="{{ $surface }}" @checked(in_array($surface, (array) $p?->allowed_surfaces, true))> {{ $label }}</label>@endforeach</fieldset>
</details>
