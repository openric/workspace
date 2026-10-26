<?php

// OpenRiC Workspace - administrators manage the accounts of their institution.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace (G1 audit: user management is "write fresh"). Which
// fields and actions an archive needs was read from Heratio's ahg-user-manage;
// editing follows its UserController::update and profile/profileEdit (one form,
// for an administrator or for the account's owner). The administrator routes
// need manage-accounts and see only accounts of their own institution; the
// owner edits their own name, email and contact details, never their roles,
// translation languages or whether the account is enabled.

namespace App\Http\Controllers;

use App\Access\AccessControl;
use App\Accounts\AccountException;
use App\Accounts\AccountService;
use App\Description\ContactPointService;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\ContactPoint;
use App\Models\Container;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return view('accounts.show', [...app(ApiKeyController::class)->managerData($account), 'account' => $account->load('roles.labels'), 'roles' => $this->roles($request),
            'kinds' => collect(array_keys(config('workspace.permission_matrix')))->map(fn ($n) => Concept::where('iri', Vocab::kind($n))->with('labels')->firstOrFail())]);
    }

    public function edit(Request $request, User $account): View
    {
        $this->sameInstitution($request, $account);

        return $this->editForm($account, false);
    }

    public function update(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);

        return $this->saveAccount($request, $account, false);
    }

    public function updateContact(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);

        return $this->saveContact($request, $account, false);
    }

    /** Heratio's profile: the signed-in account, as itself. */
    public function profile(Request $request): View
    {
        $account = $request->user()->load('roles.labels');

        return view('accounts.profile', ['account' => $account, 'contact' => $this->contact($account),
            'languages' => app(AccessControl::class)->translationLanguages($account)]);
    }

    public function editProfile(Request $request): View
    {
        return $this->editForm($request->user(), true);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        return $this->saveAccount($request, $request->user(), true);
    }

    public function updateProfileContact(Request $request): RedirectResponse
    {
        return $this->saveContact($request, $request->user(), true);
    }

    private function editForm(User $account, bool $own): View
    {
        return view('accounts.edit', ['account' => $account, 'own' => $own, 'contact' => $this->contact($account),
            'languages' => $this->accounts->translationLanguages($account)]);
    }

    /** Name and sign-in email (Heratio's rules: name required, at most 1,024 characters; email unique); translation languages for an administrator. */
    private function saveAccount(Request $request, User $account, bool $own): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1024'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account->id)],
            'translation_languages' => ['nullable', 'string', 'max:500'],
        ]);
        $back = $own ? redirect()->route('account.edit') : redirect()->route('accounts.edit', $account);
        try {
            $this->accounts->update($account, $data['name'], $data['email'], $request->user());
            if (! $own) {
                $this->accounts->setTranslationLanguages($account, preg_split('/[\s,]+/', (string) ($data['translation_languages'] ?? '')), $request->user());
            }
        } catch (\InvalidArgumentException $e) {
            return $back->withInput()->withErrors(['account' => $e->getMessage()]);
        }

        return $back->with('status', __('Account saved.'));
    }

    /** The account's contact details: one contact point, as a repository's (ISDIAH 5.2), kept with the account. */
    private function saveContact(Request $request, User $account, bool $own): RedirectResponse
    {
        app(ContactPointService::class)->save($account, [...app(ContactPointService::class)->fieldsFrom($request), 'is_primary' => true],
            $this->contact($account), $request->user());

        return ($own ? redirect()->route('account.edit') : redirect()->route('accounts.edit', $account))->with('status', __('Contact details saved.'));
    }

    private function contact(User $account): ?ContactPoint
    {
        return ContactPoint::where(['subject_table' => $account->getTable(), 'subject_id' => $account->id])->orderByDesc('is_primary')->first();
    }

    /** What an account may do, kind by kind: anywhere or not, and every rule from its roles or itself that grants or refuses it (Heratio's ACL index pages, read-only). */
    public function access(Request $request, User $account): View
    {
        $this->sameInstitution($request, $account);

        return $this->accessView($account, false);
    }

    public function profileAccess(Request $request): View
    {
        return $this->accessView($request->user(), true);
    }

    private function accessView(User $account, bool $own): View
    {
        $control = app(AccessControl::class);
        $roleIds = $control->effectiveRoleIds($account);
        $roles = Role::whereIn('id', $roleIds)->get()->keyBy('id');
        $concept = fn (string $iri) => Concept::where('iri', $iri)->value('id');
        $tables = ['description' => RecordResource::class, 'authority' => Agent::class, 'repository' => Repository::class, 'storage' => Container::class];
        $kinds = [];
        foreach (config('workspace.permission_matrix') as $kind => $actions) {
            foreach ($actions as $action) {
                $rules = DB::table('permissions')->where('institution_id', $account->institution_id)
                    ->where('kind_id', $concept(Vocab::kind($kind)))->where('action_id', $concept(Vocab::permission($action)))
                    ->where(fn ($q) => $q->whereIn('role_id', $roleIds)->orWhere('user_id', $account->id))->get()
                    ->map(fn ($r) => ['grant' => (bool) $r->is_grant, 'from' => $r->user_id ? 'this account' : ($roles[$r->role_id]?->name() ?? 'a role'),
                        'scope' => match (true) {
                            $r->subject_id !== null => EntityKinds::label(($tables[$kind] ?? RecordResource::class)::find($r->subject_id)) ?? 'one entry',
                            $r->repository_id !== null => 'in '.(EntityKinds::label(Repository::find($r->repository_id)) ?? 'one repository'),
                            default => 'everywhere',
                        }]);
                $kinds[$kind][$action] = ['anywhere' => $control->allowsAnywhere($account, $action, $kind), 'rules' => $rules];
            }
        }

        return view('accounts.access', ['account' => $account, 'own' => $own, 'kinds' => $kinds, 'roles' => $roles]);
    }

    public function confirmDelete(Request $request, User $account): View
    {
        $this->sameInstitution($request, $account);

        return view('accounts.delete', ['account' => $account]);
    }

    public function destroy(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);
        try {
            $this->accounts->delete($account, $request->user());
        } catch (AccountException $e) {
            return redirect()->route('accounts.show', $account)->withErrors(['account' => $e->getMessage()]);
        }

        return redirect()->route('accounts.index')->with('status', __('Account deleted.'));
    }

    public function updateRoles(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);
        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['uuid', Rule::in($this->roles($request)->pluck('id'))],
        ]);

        return $this->attempt(fn () => $this->accounts->setRoles($account, $data['roles'] ?? [], $request->user()), $account, __('Roles saved.'));
    }

    public function disable(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);

        return $this->attempt(fn () => $this->accounts->disable($account, $request->user()), $account, __('Account disabled.'));
    }

    public function enable(Request $request, User $account): RedirectResponse
    {
        $this->sameInstitution($request, $account);
        $this->accounts->enable($account, $request->user());

        return redirect()->route('accounts.show', $account)->with('status', __('Account enabled.'));
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
