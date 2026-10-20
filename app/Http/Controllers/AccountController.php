<?php

// OpenRiC Workspace - administrators manage the accounts of their institution.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace (G1 audit: user management is "write fresh"). Which
// fields and actions an archive needs was read from Heratio's ahg-user-manage;
// no code was taken from it. Every route here needs manage-accounts, and an
// administrator only ever sees accounts of their own institution.

namespace App\Http\Controllers;

use App\Accounts\AccountException;
use App\Accounts\AccountService;
use App\Models\Concept;
use App\Models\Role;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(Request $request): View
    {
        $users = User::where('institution_id', $request->user()->institution_id)
            ->with('roles.labels')->orderBy('name')->get();

        return view('accounts.index', ['users' => $users]);
    }

    public function create(Request $request): View
    {
        return view('accounts.create', ['roles' => $this->roles($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'roles' => ['array'],
            'roles.*' => ['uuid', Rule::in($this->roles($request)->pluck('id'))],
        ]);
        $actor = $request->user();
        // The account starts with a long random password nobody knows; its owner sets one from the link.
        $account = $this->accounts->create($actor->institution, $data['name'], $data['email'], Str::password(64), $actor);
        $this->accounts->setRoles($account, $data['roles'] ?? [], $actor);
        $link = $this->accounts->issuePasswordLink($account, $actor);

        return redirect()->route('accounts.show', $account)->with('password_link', $link);
    }

    public function show(Request $request, User $account): View
    {
        $this->sameInstitution($request, $account);

        return view('accounts.show', ['account' => $account->load('roles.labels'), 'roles' => $this->roles($request),
            'kinds' => collect(array_keys(config('workspace.permission_matrix')))->map(fn ($n) => Concept::where('iri', Vocab::kind($n))->with('labels')->firstOrFail())]);
    }

    public function updateRoles(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);
        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['uuid', Rule::in($this->roles($request)->pluck('id'))],
        ]);

        return $this->attempt(fn () => $this->accounts->setRoles($account, $data['roles'] ?? [], $request->user()), $account, 'Roles saved.');
    }

    public function disable(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);

        return $this->attempt(fn () => $this->accounts->disable($account, $request->user()), $account, 'Account disabled.');
    }

    public function enable(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);
        $this->accounts->enable($account, $request->user());

        return redirect()->route('accounts.show', $account)->with('status', 'Account enabled.');
    }

    public function passwordLink(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);
        $link = $this->accounts->issuePasswordLink($account, $request->user());

        return redirect()->route('accounts.show', $account)->with('password_link', $link);
    }

    private function attempt(callable $change, User $account, string $done): RedirectResponse
    {
        try {
            $change();
        } catch (AccountException $e) {
            return redirect()->route('accounts.show', $account)->withErrors(['account' => $e->getMessage()]);
        }

        return redirect()->route('accounts.show', $account)->with('status', $done);
    }

    /** An account of another institution does not exist, as far as this one can tell. */
    private function sameInstitution(Request $request, User $account): void
    {
        abort_unless($account->institution_id === $request->user()->institution_id, 404);
    }

    /** The institution's roles an account can be a member of: all but Public and Signed in. */
    private function roles(Request $request)
    {
        return Role::where('institution_id', $request->user()->institution_id)->where('is_system', false)
            ->with('labels')->get()->sortBy(fn ($r) => mb_strtolower($r->name()))->values();
    }
}
