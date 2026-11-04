{{-- OpenRiC Workspace - the fields of a replication target (roadmap phase 15c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-name">{{ __('Name') }}</label><input id="{{ $x }}-name" name="name" value="{{ $t?->name }}" required>
<label for="{{ $x }}-description">{{ __('Description') }}</label><input id="{{ $x }}-description" name="description" value="{{ $t?->description }}">
<label for="{{ $x }}-type">{{ __('Kind') }}</label><select id="{{ $x }}-type" name="type">@foreach($choices['replication-target-type'] as $c)<option value="{{ $c->notation }}" @selected($t?->type_id === $c->id)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-path">{{ __('Folder (a full path)') }}</label><input id="{{ $x }}-path" name="path" value="{{ $t?->path }}" required placeholder="/mnt/replica/objects">
<label for="{{ $x }}-host">{{ __('Server (by rsync over SSH)') }}</label><input id="{{ $x }}-host" name="host" value="{{ $t?->host }}">
<label for="{{ $x }}-username">{{ __('User name on it') }}</label><input id="{{ $x }}-username" name="username" value="{{ $t?->username }}">
<label for="{{ $x }}-port">{{ __('SSH port') }}</label><input id="{{ $x }}-port" name="port" type="number" min="1" max="65535" value="{{ $t?->port }}">
<label for="{{ $x }}-key">{{ __('SSH key file on this server') }}</label><input id="{{ $x }}-key" name="ssh_key_path" value="{{ $t?->ssh_key_path }}">
<label for="{{ $x }}-frequency">{{ __('How often') }}</label><select id="{{ $x }}-frequency" name="frequency">@foreach($choices['fixity-frequency'] as $c)<option value="{{ $c->notation }}" @selected($t ? $t->frequency_id === $c->id : $c->notation === 'daily')>{{ $c->label($lang) }}</option>@endforeach</select>
<label><input type="checkbox" name="is_active" value="1" @checked($t?->is_active ?? true)> {{ __('Active') }}</label>
