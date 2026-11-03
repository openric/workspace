<?php

// OpenRiC Workspace - single sign-on and two-factor sign-in (roadmap phase 12b, ADR 0021). The identity provider is
// played here: it answers discovery, publishes its signing key and issues signed ID tokens, so the client checks the
// signature, issuer, audience, nonce and state as it would against Entra ID or Keycloak. A sign-in nobody knows asks
// for an account and signs in once it is approved; one matched by verified email links its account; a forged or
// replayed answer is refused. TOTP codes are RFC 6238's, never accepted twice; recovery codes work once; and the
// institution's policy sends an administrator to set it up once the grace days are over.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Accounts\RegistrationService;
use App\Accounts\SingleSignOn;
use App\Accounts\TwoFactor;
use App\Models\Institution;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\SignInProvider;
use App\Models\User;
use App\Models\UserIdentity;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ParagonIE\ConstantTime\Base32;
use Tests\TestCase;

class SignOnTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://idp.example.org/realms/archives';

    private Institution $institution;

    private User $admin;

    private SignInProvider $provider;

    private \OpenSSLAsymmetricKey $key;

    /** What the provider puts in the next ID token, beside iss, aud, nonce and the times. */
    private array $claims = [];

    private bool $forge = false;

    private bool $omitNonce = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workspace.webhooks_check_hosts' => false]);
        // The tests sign in many times a minute; the sign-in limit is for people.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->admin = app(AccountService::class)->create($this->institution, 'Admin', 'admin@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->admin, 'administrator');
        $editor = Role::where('institution_id', $this->institution->id)->get()->first(fn ($r) => $r->name() === 'Editor');
        $this->provider = app(SingleSignOn::class)->save($this->institution, ['label' => 'Riverside staff', 'issuer_url' => self::ISSUER, 'client_id' => 'workspace',
            'client_secret' => 's3cret', 'match' => 'subject', 'roles_claim' => 'realm_access.roles', 'role_map' => 'archive-editors = '.$editor->name(),
            'accepts_requests' => 1, 'is_active' => 1], $this->admin);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->fakeProvider();
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function jwt(array $claims, \OpenSSLAsymmetricKey $key): string
    {
        $head = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'k1']));
        $body = self::b64(json_encode($claims));
        openssl_sign("$head.$body", $signature, $key, OPENSSL_ALGO_SHA256);

        return "$head.$body.".self::b64($signature);
    }

    private function fakeProvider(): void
    {
        $rsa = openssl_pkey_get_details($this->key)['rsa'];
        Http::fake(function (HttpRequest $request) use ($rsa) {
            $url = $request->url();
            if (str_ends_with($url, '/.well-known/openid-configuration')) {
                return Http::response(['issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/auth', 'token_endpoint' => self::ISSUER.'/token',
                    'jwks_uri' => self::ISSUER.'/certs', 'userinfo_endpoint' => self::ISSUER.'/userinfo', 'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                    'code_challenge_methods_supported' => ['S256']]);
            }
            if (str_ends_with($url, '/certs')) {
                return Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])]]]);
            }
            if (str_ends_with($url, '/token')) {
                $claims = ['iss' => self::ISSUER, 'aud' => 'workspace', 'iat' => time(), 'exp' => time() + 300] + ($this->omitNonce ? [] : ['nonce' => session('oidc.openid_connect_nonce')]) + $this->claims;
                $signer = $this->forge ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) : $this->key;
                $access = $this->jwt(['iss' => self::ISSUER, 'sub' => $this->claims['sub'], 'realm_access' => ['roles' => $this->claims['roles'] ?? []], 'exp' => time() + 300], $this->key);

                return Http::response(['access_token' => $access, 'token_type' => 'Bearer', 'id_token' => $this->jwt($claims, $signer), 'expires_in' => 300]);
            }

            return Http::response('', 404);
        });
    }

    /** Through the provider and back: the answer to the callback. */
    private function signIn(array $claims)
    {
        $this->claims = $claims;
        $start = $this->get(route('sso.start', $this->provider))->assertRedirect();
        $location = $start->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/auth?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['code', 'S256', 'workspace'], [$query['response_type'], $query['code_challenge_method'], $query['client_id']]);

        return $this->get(route('sso.callback', [$this->provider, 'code' => 'abc', 'state' => $query['state']]));
    }

    public function test_an_unknown_sign_in_asks_for_an_account_and_signs_in_once_it_is_approved(): void
    {
        $this->signIn(['sub' => 'u-123', 'email' => 'Thandi@Example.org', 'email_verified' => true, 'name' => 'Thandi Nkosi', 'roles' => ['archive-editors']])
            ->assertRedirect(route('sign-in'))->assertSessionHas('status');
        $this->assertGuest();
        $request = RegistrationRequest::sole();
        $this->assertSame(['Thandi Nkosi', 'thandi@example.org', $this->provider->id, 'u-123'], [$request->name, $request->email, $request->provider_id, $request->subject]);
        // Asked again before the decision: still one request.
        $this->signIn(['sub' => 'u-123', 'email' => 'thandi@example.org', 'email_verified' => true]);
        $this->assertSame(1, RegistrationRequest::count());

        $account = app(RegistrationService::class)->approve($request, [], $this->admin);
        $this->assertSame($account->id, UserIdentity::where('subject', 'u-123')->value('user_id'));
        $this->signIn(['sub' => 'u-123', 'email' => 'thandi@example.org', 'email_verified' => true, 'roles' => ['archive-editors']])->assertRedirect(route('discover.home'));
        $this->assertAuthenticatedAs($account);
        $this->assertTrue($account->fresh()->roles->contains(fn ($r) => $r->name() === 'Editor'), 'the role the claim maps to');
        $this->assertTrue(DB::table('audit_log')->where('subject_table', 'users')->where('subject_id', $account->id)->whereNotNull('after->provider')->exists());
    }

    public function test_a_verified_email_links_an_existing_account_and_forged_or_replayed_answers_are_refused(): void
    {
        $this->provider->update(['match_id' => DB::table('concepts')->where('iri', 'https://workspace.openric.org/vocab/sign-in-match/email')->value('id')]);
        $editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        // Not verified by the provider: no match, so a request instead.
        $this->signIn(['sub' => 'e-1', 'email' => 'editor@example.org', 'email_verified' => false]);
        $this->assertGuest();
        $this->signIn(['sub' => 'e-1', 'email' => 'editor@example.org', 'email_verified' => true])->assertRedirect(route('discover.home'));
        $this->assertAuthenticatedAs($editor);
        $this->assertSame($editor->id, UserIdentity::where('subject', 'e-1')->value('user_id'));
        auth()->logout();

        // Signed with a key the provider does not publish.
        $this->forge = true;
        $this->signIn(['sub' => 'e-1', 'email' => 'editor@example.org', 'email_verified' => true])->assertRedirect(route('sign-in'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->forge = false;
        // No nonce in the token.
        $this->omitNonce = true;
        $this->signIn(['sub' => 'e-1', 'email' => 'editor@example.org', 'email_verified' => true])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->omitNonce = false;
        // A callback whose state was not the one sent.
        $this->claims = ['sub' => 'e-1'];
        $this->get(route('sso.start', $this->provider));
        $this->get(route('sso.callback', [$this->provider, 'code' => 'abc', 'state' => 'not-the-state']))->assertSessionHasErrors('email');
        $this->assertGuest();
        // A disabled account.
        $editor->update(['disabled_at' => now()]);
        $this->signIn(['sub' => 'e-1', 'email' => 'editor@example.org', 'email_verified' => true])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get(route('sign-in'))->assertOk()->assertSee('Sign in with Riverside staff');
    }

    public function test_totp_codes_are_rfc_6238_and_recovery_codes_work_once(): void
    {
        // RFC 6238 Appendix B: the SHA-1 secret "12345678901234567890" at 59 seconds gives 94287082; six digits, 287082.
        $this->assertSame('287082', TwoFactor::code(Base32::encodeUpperUnpadded('12345678901234567890'), 1));
        $this->assertSame('081804', TwoFactor::code(Base32::encodeUpperUnpadded('12345678901234567890'), intdiv(1111111109, 30)));

        $twoFactor = app(TwoFactor::class);
        $setup = $twoFactor->begin($this->admin);
        $this->assertStringStartsWith('otpauth://totp/', $setup['uri']);
        $this->assertNotSame($setup['secret'], DB::table('users')->where('id', $this->admin->id)->value('two_factor_secret'), 'encrypted');
        $now = fn () => TwoFactor::code($setup['secret'], intdiv(now()->getTimestamp(), 30));
        $codes = $twoFactor->confirm($this->admin->fresh(), $now());
        $this->assertCount(10, $codes);

        // Password, then code: not signed in until the code.
        $this->travel(1)->minutes();
        $this->post(route('sign-in'), ['email' => 'admin@example.org', 'password' => 'correct horse battery staple'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $code = $now();
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect();
        $this->assertAuthenticatedAs($this->admin);
        $this->post(route('sign-out'));

        // The same code again is refused; a recovery code works, once.
        $this->post(route('sign-in'), ['email' => 'admin@example.org', 'password' => 'correct horse battery staple']);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->post(route('two-factor.verify'), ['code' => strtolower(chunk_split($codes[0], 5, ' '))])->assertRedirect();
        $this->assertAuthenticatedAs($this->admin);
        $this->post(route('sign-out'));
        $this->post(route('sign-in'), ['email' => 'admin@example.org', 'password' => 'correct horse battery staple']);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertSame(9, count($this->admin->fresh()->two_factor_recovery_codes));

        // Too long after the password: start again.
        $this->travel(11)->minutes();
        $this->post(route('two-factor.verify'), ['code' => $now()])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_policy_sends_an_administrator_to_set_it_up_after_the_grace_days(): void
    {
        $twoFactor = app(TwoFactor::class);
        $twoFactor->setPolicy($this->institution, 'required-for-administrators', 7, $this->admin);
        $reader = app(AccountService::class)->create($this->institution->fresh(), 'Reader', 'reader@example.org', 'correct horse battery staple');
        $this->assertTrue($twoFactor->required($this->admin->fresh()));
        $this->assertFalse($twoFactor->required($reader));
        $this->actingAs($this->admin->fresh())->get(route('account.profile'))->assertOk();
        $this->travel(8)->days();
        $this->actingAs($this->admin->fresh())->get(route('account.profile'))->assertRedirect(route('two-factor.show'));
        $this->actingAs($this->admin->fresh())->get(route('two-factor.show'))->assertOk()->assertSee('Your institution requires it for your account.');
        $this->actingAs($reader->fresh())->get(route('account.profile'))->assertOk();
        // Signed in through the provider: its own second factor counts.
        $this->actingAs($this->admin->fresh())->withSession(['signed_in_with_provider' => $this->provider->id])->get(route('account.profile'))->assertOk();

        // Set up through the page; an administrator can turn it off for someone who lost their phone.
        $this->actingAs($this->admin->fresh())->post(route('two-factor.begin'))->assertRedirect(route('two-factor.show'));
        $secret = $this->admin->fresh()->two_factor_secret;
        $this->actingAs($this->admin->fresh())->post(route('two-factor.confirm'), ['code' => TwoFactor::code($secret, intdiv(now()->getTimestamp(), 30))])->assertSessionHas('recovery_codes');
        $this->actingAs($this->admin->fresh())->get(route('account.profile'))->assertOk();
        $this->actingAs($this->admin->fresh())->post(route('accounts.two-factor.reset', $this->admin))->assertRedirect();
        $this->assertFalse($twoFactor->enabled($this->admin->fresh()));
        $this->actingAs($this->admin->fresh())->get(route('sign-in-settings.index'))->assertOk()->assertSee('Riverside staff')->assertDontSee('s3cret');
    }
}
