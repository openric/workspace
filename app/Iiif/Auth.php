<?php

// OpenRiC Workspace - IIIF Authorization Flow 2.0 (roadmap phase 14b, ADR 0014): a file available on request is in
// the manifest with a probe service, so a viewer can tell a reader to sign in, and show it once they may have it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's IiifAuthFlow2Service, with its two departures from the specification put right: the probe reads the
// access token it is sent (Heratio's ignores it and asks only whether the browser is signed in), and the token service
// is a page that posts the token to the viewer's window (Heratio's answers JSON, which a viewer opened in another site
// cannot read). Access is the Workspace's own rule, InstantiationService::deliverable: a file on request opens to an
// approved access request (phase 7) or to who may read it, never by a clearance level, which comes with phase 18. The
// token is a random string kept for an hour against the account; only its hash is stored. The file itself goes by the
// session cookie the sign-in set, as the specification intends for the active profile.

namespace App\Iiif;

use App\Description\InstantiationService;
use App\Models\DigitalFile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Auth
{
    public const CONTEXT = 'http://iiif.io/api/auth/2/context.json';

    public const TOKEN_SECONDS = 3600;

    /** The probe service of a file, with the access service a viewer opens to sign in, and its token service. */
    public function probeService(DigitalFile $file): array
    {
        return ['id' => route('iiif.auth.probe', $file), 'type' => 'AuthProbeService2', 'service' => [
            ['id' => route('iiif.auth.access'), 'type' => 'AuthAccessService2', 'profile' => 'active',
                'label' => ['en' => ['Sign in to see it']], 'heading' => ['en' => ['Available on request']],
                'note' => ['en' => ['Sign in with an account that has an approved access request for it.']],
                'confirmLabel' => ['en' => ['Sign in']],
                'service' => [['id' => route('iiif.auth.token'), 'type' => 'AuthAccessTokenService2']]],
        ]];
    }

    /** What the probe answers for a file and the account a token stands for: 200 with the file, else 401. */
    public function probe(DigitalFile $file, ?User $user): array
    {
        $file->load('instantiation.record', 'instantiation.availability', 'use');
        $may = $user !== null && $file->institution_id === $user->institution_id && app(InstantiationService::class)->deliverable($user, $file);

        return array_filter(['@context' => self::CONTEXT, 'id' => route('iiif.auth.probe', $file), 'type' => 'AuthProbeResult2', 'status' => $may ? 200 : 401,
            'heading' => $may ? null : ['en' => [$user ? 'Not available to you' : 'Sign in to see it']],
            'note' => $may ? null : ['en' => [$user ? 'Ask for access to it from its description, and look again once the request is approved.' : 'It is available on request.']]]);
    }

    /** A new token for an account, kept for an hour. */
    public function issue(User $user): string
    {
        $token = Str::random(48);
        Cache::put($this->key($token), $user->id, self::TOKEN_SECONDS);

        return $token;
    }

    /** The account a token stands for, while it lasts. */
    public function user(?string $token): ?User
    {
        $id = $token ? Cache::get($this->key($token)) : null;

        return $id ? User::find($id) : null;
    }

    private function key(string $token): string
    {
        return 'iiif-auth:'.hash('sha256', $token);
    }
}
