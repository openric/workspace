{{-- OpenRiC Workspace - an identity provider's fields (AtoM's arOidcPlugin provider settings). Needs $p (or null), $x, $matches, $roles. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-label">{{ __('Name on the button, after "Sign in with"') }}</label><input id="{{ $x }}-label" name="label" value="{{ $p?->label }}" required maxlength="100">
<label for="{{ $x }}-issuer">{{ __('Issuer address') }}</label><input id="{{ $x }}-issuer" name="issuer_url" value="{{ $p?->issuer_url }}" required placeholder="https://login.microsoftonline.com/&lt;tenant&gt;/v2.0">
<label for="{{ $x }}-client">{{ __('Client ID') }}</label><input id="{{ $x }}-client" name="client_id" value="{{ $p?->client_id }}" required>
<label for="{{ $x }}-secret">{{ __('Client secret') }}</label><input id="{{ $x }}-secret" name="client_secret" type="password" autocomplete="off" @unless($p) required @endunless aria-describedby="{{ $x }}-secret-help">
<small id="{{ $x }}-secret-help">{{ $p ? __('Kept encrypted and never shown. Leave it empty to keep the one there.') : __('Kept encrypted and never shown.') }}</small>
<label for="{{ $x }}-scopes">{{ __('Scopes, besides openid') }}</label><input id="{{ $x }}-scopes" name="scopes" value="{{ $p ? implode(' ', array_diff($p->scopes, ['openid'])) : 'email profile' }}">
<label for="{{ $x }}-match">{{ __('How a sign-in finds its account') }}</label><select id="{{ $x }}-match" name="match">@foreach($matches as $m)<option value="{{ $m->notation }}" @selected(($p?->match->notation ?? 'subject') === $m->notation)>{{ $m->label($lang) }}</option>@endforeach</select>
<small>{{ __('By verified email links an existing account the first time it signs in; by subject links only accounts approved from a request.') }}</small>
<label for="{{ $x }}-claim">{{ __('Claim holding roles (optional), such as roles or realm_access.roles') }}</label><input id="{{ $x }}-claim" name="roles_claim" value="{{ $p?->roles_claim }}">
<label for="{{ $x }}-map">{{ __('Roles each claim value gives, one a line: value = role') }}</label>
<textarea id="{{ $x }}-map" name="role_map" rows="3" placeholder="archive-editors = {{ $roles->first()?->name() }}">{{ collect($p?->role_map ?? [])->map(fn ($id, $value) => $value.' = '.($roles->firstWhere('id', $id)?->name() ?? $id))->join("\n") }}</textarea>
<small>{{ __('Roles are added at each sign-in, never taken away.') }}</small>
<label><input type="checkbox" name="accepts_requests" value="1" @checked($p?->accepts_requests ?? true)> {{ __('A sign-in that matches no account asks for one') }}</label>
<label><input type="checkbox" name="is_active" value="1" @checked($p?->is_active ?? true)> {{ __('On') }}</label>
