{{-- OpenRiC Workspace - an account's API keys, and a form for a new one (Heratio's key manager, ADR 0007). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $keys, $scopes (the api-scope concepts this account may give), $newKey (?string, once), $storeUrl and $revokeUrl (fn (ApiKey) => string). --}}
@if($newKey)
    <div role="status">
        <p><strong>{{ __('The new key') }}</strong>{{ __('. Copy it now: it is shown only this once, and the Workspace keeps only a fingerprint of it.') }}</p>
        <p><code style="word-break: break-all">{{ $newKey }}</code></p>
    </div>
@endif
<p>{{ __('A key reads what the public may see, whatever its scopes. Send it in the') }} <code>{{ __('X-API-Key') }}</code> {{ __('header, or as') }} <code>{{ __('Authorization: Bearer') }}</code>, to {{ url('/api/ric/v1') }}.</p>

@if($keys->isEmpty())
    <p>{{ __('No keys yet.') }}</p>
@else
    <table>
        <caption>{{ __('API keys') }}</caption>
        <thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Begins') }}</th><th scope="col">{{ __('Scopes') }}</th><th scope="col">{{ __('Limits') }}</th><th scope="col">{{ __('Ends') }}</th><th scope="col">{{ __('Last used') }}</th><th scope="col">{{ __('State') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead>
        <tbody>
        @foreach($keys as $key)
            <tr>
                <td>{{ $key->name }}</td>
                <td><code>{{ $key->prefix }}</code></td>
                <td>{{ $key->scopes->map(fn ($s) => $s->anyLabel(['en']))->implode(', ') }}</td>
                <td>{{ number_format($key->hourly_limit) }} an hour{{ $key->daily_limit ? ", ".number_format($key->daily_limit)." a day" : "" }}</td>
                <td>{{ $key->expires_at?->toDateString() ?? 'Never' }}</td>
                <td>{{ $key->last_used_at?->toDayDateTimeString() ?? 'Never' }}</td>
                <td>{{ $key->isRevoked() ? 'Revoked '.$key->revoked_at->toDateString() : ($key->isExpired() ? 'Expired' : 'Active') }}</td>
                <td>
                    @unless($key->isRevoked())
                        <form method="post" action="{{ $revokeUrl($key) }}">@csrf<button type="submit">{{ __('Revoke') }}</button></form>
                    @endunless
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h3>{{ __('New key') }}</h3>
<form method="post" action="{{ $storeUrl }}">
    @csrf
    <p><label for="key-name">{{ __('Name') }}</label><br><input id="key-name" name="name" required maxlength="100" value="{{ old('name') }}"></p>
    <fieldset>
        <legend>{{ __('Scopes') }}</legend>
        @foreach($scopes as $scope)
            <p style="margin: .2rem 0"><label><input type="checkbox" name="scopes[]" value="{{ $scope->notation }}" @checked(in_array($scope->notation, old('scopes', ['read']), true))>
                {{ $scope->anyLabel(['en']) }}</label>: {{ $scope->labels->firstWhere('language', 'en')?->definition }}</p>
        @endforeach
        @if($scopes->count() < 2)<p>{{ __('This account may give its keys the read scope only.') }}</p>@endif
    </fieldset>
    <p><label for="key-ends">{{ __('Ends on (optional)') }}</label><br><input id="key-ends" type="date" name="expires_at" value="{{ old('expires_at') }}"></p>
    <p><label for="key-daily">{{ __('Requests a day (optional)') }}</label><br><input id="key-daily" type="number" name="daily_limit" min="1" max="1000000" value="{{ old('daily_limit') }}"></p>
    <button type="submit">{{ __('Make key') }}</button>
</form>
