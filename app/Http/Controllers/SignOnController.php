<?php

// OpenRiC Workspace - signing in through the institution's identity provider, and the second factor after a password
// (roadmap phase 12b, ADR 0021).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Accounts\AccountException;
use App\Accounts\Oidc\Redirect;
use App\Accounts\SingleSignOn;
use App\Accounts\TwoFactor;
use App\Audit\AuditLog;
use App\Models\SignInProvider;
use App\Models\User;
use App\Support\HostInstitution;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Jumbojett\OpenIDConnectClientException;

class SignOnController extends Controller
{
    /** Minutes the second factor may wait after the password before signing in starts again. */
    private const PENDING_MINUTES = 10;

    public function __construct(private SingleSignOn $sso, private TwoFactor $twoFactor) {}

    /** Off to the provider, with state, nonce and PKCE kept in the session. */
    public function start(Request $request, SignInProvider $provider): RedirectResponse
    {
        abort_unless($provider->is_active && $provider->institution_id === HostInstitution::for($request)?->id, 404);
        try {
            $this->sso->client($provider)->authenticate();
        } catch (Redirect $redirect) {
            return redirect()->away($redirect->url);
        } catch (OpenIDConnectClientException $e) {
            return $this->failed($e->getMessage());
        }

        return $this->failed(__('The provider did not answer.'));
    }

    public function callback(Request $request, SignInProvider $provider): RedirectResponse
    {
        abort_unless($provider->institution_id === HostInstitution::for($request)?->id, 404);
        try {
            $user = $this->sso->finish($provider, $request->query());
        } catch (Redirect $redirect) {
            return redirect()->away($redirect->url);
        } catch (AccountException|OpenIDConnectClientException $e) {
            AuditLog::record(Vocab::action('sign-in-failed'), null, null, ['provider' => $provider->label, 'reason' => mb_substr($e->getMessage(), 0, 500)], null, $provider->institution_id);

            return $this->failed($e->getMessage());
        }
        if ($user === null) {
            return redirect()->route('sign-in')->with('status', __('Thank you. Nobody here has an account linked to that sign-in yet, so an administrator has been asked to make one; you can sign in when it is approved.'));
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('signed_in_with_provider', $provider->id);
        AuditLog::record(Vocab::action('sign-in'), $user, null, ['provider' => $provider->label]);

        return redirect()->intended(route('discover.home'));
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        return $this->pending($request) ? view('auth.two-factor-challenge') : redirect()->route('sign-in');
    }

    /** The code from the app, or a recovery code: then the account is signed in. */
    public function verify(Request $request): RedirectResponse
    {
        $user = $this->pending($request) ?? throw ValidationException::withMessages(['email' => __('Sign in again: too long passed since the password.')]);
        if (! $this->twoFactor->verify($user, (string) $request->input('code'))) {
            AuditLog::record(Vocab::action('sign-in-failed'), $user, null, ['email' => $user->email, 'reason' => 'second factor'], null, $user->institution_id);

            return back()->withErrors(['code' => __('That code is not right.')]);
        }
        $request->session()->forget('two_factor.pending');
        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record(Vocab::action('sign-in'), $user);

        return redirect()->intended(route('discover.home'));
    }

    private function pending(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor.pending');
        if (! is_array($pending) || now()->getTimestamp() - (int) $pending['at'] > self::PENDING_MINUTES * 60) {
            return null;
        }
        $user = User::find($pending['id']);

        return $user && ! $user->isDisabled() ? $user : null;
    }

    private function failed(string $why): RedirectResponse
    {
        return redirect()->route('sign-in')->withErrors(['email' => __('Signing in with the provider did not work: :why', ['why' => $why])]);
    }
}
