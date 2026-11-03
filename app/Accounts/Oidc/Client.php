<?php

// OpenRiC Workspace - the OpenID Connect client AtoM's arOidcPlugin uses (jumbojett/openid-connect-php, Apache 2.0), fitted
// to Laravel (roadmap phase 12b): it sends its redirect back as an exception for the controller to answer instead of
// calling exit, keeps its state, nonce and code verifier in Laravel's session, and fetches through Laravel's HTTP
// client. Everything that checks the provider's answer (the state, the token's signature against the provider's
// keys, its issuer, audience, expiry and nonce) is the library's, unchanged.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Accounts\Oidc;

use Illuminate\Support\Facades\Http;
use Jumbojett\OpenIDConnectClient;

class Client extends OpenIDConnectClient
{
    public function redirect(string $url)
    {
        throw new Redirect($url);
    }

    protected function startSession() {}

    protected function commitSession() {}

    protected function getSessionKey(string $key)
    {
        return session('oidc.'.$key);
    }

    protected function setSessionKey(string $key, $value)
    {
        session(['oidc.'.$key => $value]);
    }

    protected function unsetSessionKey(string $key)
    {
        session()->forget('oidc.'.$key);
    }

    protected function fetchURL(string $url, ?string $post_body = null, array $headers = [])
    {
        $request = Http::timeout(15)->withHeaders(collect($headers)->mapWithKeys(function ($h) {
            [$name, $value] = array_map('trim', explode(':', $h, 2) + [1 => '']);

            return [$name => $value];
        })->all());
        $response = $post_body === null ? $request->get($url) : $request->withBody($post_body, $this->contentType($headers))->post($url);
        $this->responseCode = $response->status();

        return $response->body();
    }

    private function contentType(array $headers): string
    {
        foreach ($headers as $h) {
            if (stripos($h, 'content-type:') === 0) {
                return trim(substr($h, 13));
            }
        }

        return 'application/x-www-form-urlencoded';
    }
}
