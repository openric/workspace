<?php

// OpenRiC Workspace - single sign-on through the institution's identity providers (roadmap phase 12b, ADR 0021).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After AtoM's arOidcPlugin, by OpenID Connect's authorisation code flow with PKCE. A sign-in finds its account by the
// provider's subject, once linked; before that, by email where the provider is set to match by verified email. A
// sign-in that finds no account becomes a request for one, which an administrator approves or rejects as any request
// is, on Dr Johan Pieterse's choice of 3 November 2026; AtoM made the account at once. Roles a claim names are added
// as the provider maps them, never taken away. A disabled account is refused.

namespace App\Accounts;

use App\Access\RoleService;
use App\Accounts\Oidc\Client;
use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\SignInProvider;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Vocab;
use App\Webhooks\Webhooks;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SingleSignOn
{
    public function __construct(private AccountService $accounts, private RoleService $roles) {}

    public function client(SignInProvider $provider): Client
    {
        $client = new Client($provider->issuer_url, $provider->client_id, $provider->client_secret);
        $client->setRedirectURL(route('sso.callback', $provider));
        $client->addScope($provider->scopes ?: ['openid', 'email', 'profile']);
        $client->setCodeChallengeMethod('S256');

        return $client;
    }

    /**
     * Finish a sign-in the provider sent back: the account to sign in, or null when the sign-in became a request for one.
     *
     * @param  array<string, mixed>  $answer  the query the provider sent back (code, state, or error)
     */
    public function finish(SignInProvider $provider, array $answer): ?User
    {
        $provider->is_active || throw new AccountException(__('This way of signing in is switched off.'));
        $client = $this->client($provider);
        $previous = $_REQUEST;
        $_REQUEST = $answer;
        try {
            $client->authenticate();
        } finally {
            $_REQUEST = $previous;
        }
        $claims = json_decode(json_encode($client->getVerifiedClaims()), true) ?: [];
        // The library checks a nonce and an expiry only when the token has them; a token here must have both.
        (isset($claims['nonce'], $claims['exp'])) || throw new AccountException(__('The provider\'s token had no nonce or no expiry, so it was not accepted.'));
        $subject = (string) ($claims['sub'] ?? throw new AccountException(__('The provider did not say who signed in.')));
        $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $identity = UserIdentity::where(['provider_id' => $provider->id, 'subject' => $subject])->with('user')->first();
        $user = $identity?->user;
        if ($user === null && $provider->match->notation === 'email' && $verified && $email !== '') {
            $user = User::where(['institution_id' => $provider->institution_id, 'email' => $email])->first();
        }
        if ($user === null) {
            $this->request($provider, $subject, $email, (string) ($claims['name'] ?? trim(($claims['given_name'] ?? '').' '.($claims['family_name'] ?? ''))));

            return null;
        }
        $user->isDisabled() && throw new AccountException(__('This account is disabled.'));
        $user->institution_id === $provider->institution_id || throw new AccountException(__('This account belongs to another institution.'));
        UserIdentity::updateOrCreate(['provider_id' => $provider->id, 'subject' => $subject], ['user_id' => $user->id, 'email' => $email ?: null, 'last_signed_in_at' => now()]);
        $this->addRoles($provider, $user, $claims, json_decode(json_encode($client->getAccessTokenPayload()), true) ?: []);

        return $user;
    }

    /** A sign-in nobody knows: a request for an account, once, for an administrator to decide. */
    private function request(SignInProvider $provider, string $subject, string $email, string $name): void
    {
        $provider->accepts_requests || throw new AccountException(__('No account here is linked to that sign-in. Ask an administrator to make one.'));
        $email !== '' || throw new AccountException(__('The provider did not give an email address, which a request for an account needs.'));
        $pending = RegistrationRequest::where(['provider_id' => $provider->id, 'subject' => $subject])->whereNull('approved_at')->whereNull('rejected_at')->exists();
        $pending || RegistrationRequest::create(['institution_id' => $provider->institution_id, 'name' => trim($name) ?: $email, 'email' => $email,
            // Never used: the account signs in through the provider. A password can be set later by the usual link.
            'password' => Hash::make(Str::random(64)), 'reason' => __('Signed in with :provider.', ['provider' => $provider->label]),
            'provider_id' => $provider->id, 'subject' => $subject]);
    }

    /** The roles a claim names, added as the provider maps them (AtoM's set_groups_from_attributes); none taken away. */
    private function addRoles(SignInProvider $provider, User $user, array $idClaims, array $accessClaims): void
    {
        if (! $provider->roles_claim || ! $provider->role_map) {
            return;
        }
        $values = (array) (Arr::get($accessClaims, $provider->roles_claim) ?? Arr::get($idClaims, $provider->roles_claim) ?? []);
        $roleIds = array_values(array_intersect_key($provider->role_map, array_flip(array_map('strval', $values))));
        foreach (Role::where('institution_id', $user->institution_id)->where('is_system', false)->whereIn('id', $roleIds)->get() as $role) {
            $user->roles()->where('roles.id', $role->id)->exists() || $this->roles->addMember($role, $user);
        }
    }

    /** Link the identity a request was made from to the account its approval made. */
    public function linkApproved(RegistrationRequest $request, User $account): void
    {
        if ($request->provider_id && $request->subject) {
            UserIdentity::updateOrCreate(['provider_id' => $request->provider_id, 'subject' => $request->subject], ['user_id' => $account->id, 'email' => $request->email]);
        }
    }

    public function save(Institution $institution, array $data, User $actor, ?SignInProvider $provider = null): SignInProvider
    {
        $label = trim((string) ($data['label'] ?? '')) ?: throw new InvalidArgumentException(__('Give the provider a name for its button.'));
        $issuer = rtrim(trim((string) ($data['issuer_url'] ?? '')), '/');
        (str_starts_with($issuer, 'https://') && Webhooks::publicUrl($issuer))
            || throw new InvalidArgumentException(__('The issuer is the provider\'s public https address, such as https://login.microsoftonline.com/<tenant>/v2.0.'));
        $clientId = trim((string) ($data['client_id'] ?? '')) ?: throw new InvalidArgumentException(__('Give the client ID the provider issued.'));
        $secret = trim((string) ($data['client_secret'] ?? ''));
        ($secret !== '' || $provider) || throw new InvalidArgumentException(__('Give the client secret the provider issued.'));
        $match = Concept::choices(Vocab::BASE.'sign-in-match/')->where('notation', (string) ($data['match'] ?? 'subject'))->first() ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $data['match'] ?? '']));
        $map = [];
        foreach (preg_split('/\R/', (string) ($data['role_map'] ?? '')) as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$value, $role] = array_map('trim', explode('=', $line, 2) + [1 => '']);
            $roleId = Role::where('institution_id', $institution->id)->where('is_system', false)->get()->first(fn ($r) => $r->id === $role || mb_strtolower((string) $r->name()) === mb_strtolower($role))?->id
                ?? throw new InvalidArgumentException(__('No role here is called :role.', ['role' => $role]));
            $map[$value] = $roleId;
        }
        $scopes = array_values(array_unique(['openid', ...array_filter(preg_split('/[\s,]+/', (string) ($data['scopes'] ?? 'email profile')))]));
        $fields = ['label' => $label, 'issuer_url' => $issuer, 'client_id' => $clientId, 'scopes' => $scopes, 'match_id' => $match->id,
            'roles_claim' => trim((string) ($data['roles_claim'] ?? '')) ?: null, 'role_map' => $map ?: null,
            'accepts_requests' => ! empty($data['accepts_requests']), 'is_active' => ! empty($data['is_active'])] + ($secret !== '' ? ['client_secret' => $secret] : []);

        return AuditLog::actingAs($actor, fn () => $provider ? tap($provider)->update($fields)
            : SignInProvider::create($fields + ['institution_id' => $institution->id, 'created_by_id' => $actor->id]));
    }
}
