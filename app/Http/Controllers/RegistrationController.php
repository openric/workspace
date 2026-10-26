<?php

// OpenRiC Workspace - the request-an-account form, and the administrators' list of requests (Heratio's registration).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Accounts\AccountException;
use App\Accounts\RegistrationService;
use App\Models\Institution;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Support\HostInstitution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function __construct(private RegistrationService $registrations) {}

    public function create(Request $request): View
    {
        return view('auth.register', ['institution' => $this->institution($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $institution = $this->institution($request);
        // A field people never see: a form that fills it was not filled by a person.
        if (filled($request->input('website'))) {
            return redirect()->route('sign-in')->with('status', __('Thank you: an administrator will look at your request.'));
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $this->registrations->request($institution, $data['name'], $data['email'], $data['password'], $data['reason'] ?? null);
        } catch (AccountException $e) {
            return back()->withInput($request->except('password', 'password_confirmation'))->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('sign-in')->with('status', __('Thank you: an administrator will look at your request. You can sign in once it is approved.'));
    }

    public function index(Request $request): View
    {
        $requests = RegistrationRequest::where('institution_id', $request->user()->institution_id)->with('decidedBy')->latest()->get();

        return view('accounts.registrations', ['pending' => $requests->filter->isPending(), 'decided' => $requests->reject->isPending()->take(50),
            'roles' => Role::where('institution_id', $request->user()->institution_id)->where('is_system', false)->with('labels')->get()]);
    }

    public function approve(Request $request, RegistrationRequest $registration): RedirectResponse
    {
        abort_unless($registration->institution_id === $request->user()->institution_id, 404);
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['uuid', Rule::exists('roles', 'id')->where('institution_id', $request->user()->institution_id)->where('is_system', false)]]);
        try {
            $account = $this->registrations->approve($registration, $data['roles'] ?? [], $request->user());
        } catch (AccountException $e) {
            return redirect()->route('registrations.index')->withErrors(['registration' => $e->getMessage()]);
        }

        return redirect()->route('accounts.show', $account)->with('status', __('Request approved: :name can sign in with the password they chose.', ['name' => $account->name]));
    }

    public function reject(Request $request, RegistrationRequest $registration): RedirectResponse
    {
        abort_unless($registration->institution_id === $request->user()->institution_id, 404);
        try {
            $this->registrations->reject($registration, $request->user());
        } catch (AccountException $e) {
            return redirect()->route('registrations.index')->withErrors(['registration' => $e->getMessage()]);
        }

        return redirect()->route('registrations.index')->with('status', __('Request rejected.'));
    }

    private function institution(Request $request): Institution
    {
        $institution = HostInstitution::for($request) ?? abort(404);
        abort_unless($institution->registration_open, 404);

        return $institution;
    }
}
