{{-- OpenRiC Workspace - an account's API keys, and a form for a new one (Heratio's key manager, ADR 0007). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $keys, $scopes (the api-scope concepts this account may give), $newKey (?string, once), $storeUrl and $revokeUrl (fn (ApiKey) => string). --}}
@if($newKey)
    <div role="status">
        <p><strong>The new key</strong>. Copy it now: it is shown only this once, and the Workspace keeps only a fingerprint of it.</p>
        <p><code style="word-break: break-all">{{ $newKey }}</code></p>
    </div>
@endif
<p>A key reads what the public may see, whatever its scopes. Send it in the <code>X-API-Key</code> header, or as <code>Authorization: Bearer</code>, to {{ url('/api/ric/v1') }}.</p>

@if($keys->isEmpty())
    <p>No keys yet.</p>
@else
    <table>
        <caption>API keys</caption>
        <thead><tr><th scope="col">Name</th><th scope="col">Begins</th><th scope="col">Scopes</th><th scope="col">Limits</th><th scope="col">Ends</th><th scope="col">Last used</th><th scope="col">State</th><th scope="col">Action</th></tr></thead>
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
                        <form method="post" action="{{ $revokeUrl($key) }}">@csrf<button type="submit">Revoke</button></form>
                    @endunless
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h3>New key</h3>
<form method="post" action="{{ $storeUrl }}">
    @csrf
    <p><label for="key-name">Name</label><br><input id="key-name" name="name" required maxlength="100" value="{{ old('name') }}"></p>
    <fieldset>
        <legend>Scopes</legend>
        @foreach($scopes as $scope)
            <p style="margin: .2rem 0"><label><input type="checkbox" name="scopes[]" value="{{ $scope->notation }}" @checked(in_array($scope->notation, old('scopes', ['read']), true))>
                {{ $scope->anyLabel(['en']) }}</label>: {{ $scope->labels->firstWhere('language', 'en')?->definition }}</p>
        @endforeach
        @if($scopes->count() < 2)<p>This account may give its keys the read scope only.</p>@endif
    </fieldset>
    <p><label for="key-ends">Ends on (optional)</label><br><input id="key-ends" type="date" name="expires_at" value="{{ old('expires_at') }}"></p>
    <p><label for="key-daily">Requests a day (optional)</label><br><input id="key-daily" type="number" name="daily_limit" min="1" max="1000000" value="{{ old('daily_limit') }}"></p>
    <button type="submit">Make key</button>
</form>
