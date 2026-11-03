<?php

// OpenRiC Workspace - an account's two-factor sign-in, and the institution's sign-in settings: its identity providers and
// who must use a second factor (roadmap phase 12b, ADR 0021).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Accounts\SingleSignOn;
use App\Accounts\TwoFactor;
use App\Models\Concept;
use App\Models\Role;
use App\Models\SignInProvider;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor, private SingleSignOn $sso) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.two-factor', ['enabled' => $this->twoFactor->enabled($user), 'required' => $this->twoFactor->required($user),
            'setup' => session('two_factor_setup'), 'codes' => session('recovery_codes'), 'left' => count($user->two_factor_recovery_codes ?? [])]);
    }

    /** A new secret, shown as a QR code until a code from it confirms it. */
    public function begin(Request $request): RedirectResponse
    {
        $setup = $this->twoFactor->begin($request->user());

        return redirect()->route('two-factor.show')->with('two_factor_setup', $setup);
    }

    public function confirm(Request $request): RedirectResponse
    {
        try {
            $codes = $this->twoFactor->confirm($request->user(), (string) $request->input('code'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->with('two_factor_setup', session('two_factor_setup'));
        }

        return redirect()->route('two-factor.show')->with('recovery_codes', $codes)->with('status', __('Two-factor sign-in is on. Keep the recovery codes somewhere safe: each lets you in once without your phone.'));
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $this->twoFactor->verify($request->user(), (string) $request->input('code')) || throw ValidationException::withMessages(['code' => __('That code is not right.')]);

        return redirect()->route('two-factor.show')->with('recovery_codes', $this->twoFactor->regenerate($request->user()));
    }

    /** Turned off by the account itself with a current code; it is asked for again if the institution requires it. */
    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->twoFactor->verify($user, (string) $request->input('code')) || throw ValidationException::withMessages(['code' => __('That code is not right.')]);
        $this->twoFactor->disable($user, $user);

        return redirect()->route('two-factor.show')->with('status', __('Two-factor sign-in is off.'));
    }

    /** An administrator turns it off for an account that lost its phone and its codes. */
    public function reset(Request $request, User $account): RedirectResponse
    {
        abort_unless($account->institution_id === $request->user()->institution_id, 404);
        $this->twoFactor->disable($account, $request->user());

        return back()->with('status', __('Two-factor sign-in was turned off for :name, who sets it up again at the next sign-in if it is required.', ['name' => $account->name]));
    }

    public function settings(Request $request): View
    {
        $institution = $request->user()->institution;

        return view('sign-in-settings.index', ['institution' => $institution, 'policy' => $this->twoFactor->policy($institution),
            'policies' => Concept::choices(Vocab::BASE.'two-factor-policy/')->get(), 'matches' => Concept::choices(Vocab::BASE.'sign-in-match/')->get(),
            'providers' => SignInProvider::where('institution_id', $institution->id)->with('match')->orderBy('label')->get(),
            'roles' => Role::where('institution_id', $institution->id)->where('is_system', false)->get()]);
    }

    public function savePolicy(Request $request): RedirectResponse
    {
        try {
            $this->twoFactor->setPolicy($request->user()->institution, (string) $request->input('policy'), (int) $request->input('grace_days', 14), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['policy' => $e->getMessage()]);
        }

        return redirect()->route('sign-in-settings.index')->with('status', __('Saved.'));
    }

    public function saveProvider(Request $request, ?SignInProvider $provider = null): RedirectResponse
    {
        abort_if($provider && $provider->institution_id !== $request->user()->institution_id, 404);
        try {
            $this->sso->save($request->user()->institution, $request->all(), $request->user(), $provider);
        } catch (InvalidArgumentException $e) {
            return back()->withInput($request->except('client_secret'))->withErrors(['provider' => $e->getMessage()]);
        }

        return redirect()->route('sign-in-settings.index')->with('status', __('Saved.'));
    }
}
