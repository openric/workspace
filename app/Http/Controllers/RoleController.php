<?php

// OpenRiC Workspace - roles and the permission matrix (ADR 0005).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace after Heratio's ahg-acl screens (groups, edit group,
// members, translate permission, the per-class ACL editors and the user ACL
// editor); none of Heratio's views were copied. Every route needs manage-roles,
// and only the institution's own roles and accounts exist here.

namespace App\Http\Controllers;

use App\Access\PermissionMatrix;
use App\Access\RoleService;
use App\Accounts\AccountException;
use App\Description\Bcp47;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class RoleController extends Controller
{
    public function __construct(private RoleService $roles, private PermissionMatrix $matrix) {}

    public function index(Request $request): View
    {
        $roles = $this->institutionRoles($request)->load('labels', 'parent.labels', 'members');

        return view('roles.index', ['roles' => $roles]);
    }

    public function create(Request $request): View
    {
        return view('roles.create', ['parents' => $this->institutionRoles($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProfile($request);
        $parent = ($data['parent'] ?? null) ? $this->find($request, $data['parent']) : null;
        try {
            $role = $this->roles->create($request->user()->institution, [$data['language'] => ['name' => $data['name'], 'description' => $data['description'] ?? null]], $parent, null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('roles.show', $role)->with('status', 'Role created. Set its permissions below.');
    }

    public function show(Request $request, Role $role): View
    {
        $this->own($request, $role);
        $role->load('labels', 'parent.labels', 'members', 'template');
        $descendants = $this->descendantIds($role);

        return view('roles.show', [
            'role' => $role,
            'parents' => $this->institutionRoles($request)->reject(fn ($r) => $r->id === $role->id || in_array($r->id, $descendants, true)),
            'kinds' => $this->kinds(),
            'candidates' => User::where('institution_id', $role->institution_id)->whereNotIn('id', $role->members->pluck('id'))->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->own($request, $role);
        $data = $this->validateProfile($request);
        $parent = ($data['parent'] ?? null) ? $this->find($request, $data['parent']) : null;
        try {
            $this->roles->update($role, [$data['language'] => ['name' => $data['name'], 'description' => $data['description'] ?? null]], $role->is_system ? $role->parent : $parent, $request->user());
        } catch (AccountException|InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        } catch (QueryException) {
            return back()->withInput()->withErrors(['parent' => 'A role cannot inherit from itself or from a role that inherits from it.']);
        }

        return redirect()->route('roles.show', $role)->with('status', 'Role saved.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->own($request, $role);
        try {
            $this->roles->remove($role, $request->user());
        } catch (AccountException $e) {
            return redirect()->route('roles.show', $role)->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('roles.index')->with('status', 'Role removed.');
    }

    public function addMember(Request $request, Role $role): RedirectResponse
    {
        $this->own($request, $role);
        $data = $request->validate(['account' => ['required', 'uuid']]);
        $account = User::where('institution_id', $role->institution_id)->findOrFail($data['account']);
        try {
            $this->roles->addMember($role, $account, $request->user());
        } catch (AccountException $e) {
            return redirect()->route('roles.show', $role)->withErrors(['members' => $e->getMessage()]);
        }

        return redirect()->route('roles.show', $role)->with('status', "{$account->name} added.");
    }

    public function removeMember(Request $request, Role $role, User $account): RedirectResponse
    {
        $this->own($request, $role);
        abort_unless($account->institution_id === $role->institution_id, 404);
        try {
            $this->roles->removeMember($role, $account, $request->user());
        } catch (AccountException $e) {
            return redirect()->route('roles.show', $role)->withErrors(['members' => $e->getMessage()]);
        }

        return redirect()->route('roles.show', $role)->with('status', "{$account->name} removed.");
    }

    public function languages(Request $request, Role $role): RedirectResponse
    {
        $this->own($request, $role);
        $data = $request->validate(['languages' => ['nullable', 'string', 'max:500']]);
        try {
            $this->roles->setTranslationLanguages($role, preg_split('/[\s,]+/', (string) ($data['languages'] ?? ''), -1, PREG_SPLIT_NO_EMPTY), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['languages' => $e->getMessage()]);
        }

        return redirect()->route('roles.show', $role)->with('status', 'Translation languages saved.');
    }

    /** The matrix of one kind for a role (Heratio's editInformationObjectAcl and its siblings). */
    public function permissions(Request $request, Role $role, string $kind): View
    {
        $this->own($request, $role);

        return $this->matrixView($request, $role, $kind, route('roles.permissions.update', [$role, $kind]), route('roles.show', $role), $role->name());
    }

    public function updatePermissions(Request $request, Role $role, string $kind): RedirectResponse
    {
        $this->own($request, $role);

        return $this->applyMatrix($request, $role, $kind, route('roles.permissions', [$role, $kind]));
    }

    /** The matrix of one kind for one account (Heratio's user ACL editor). */
    public function accountPermissions(Request $request, User $account, string $kind): View
    {
        abort_unless($account->institution_id === $request->user()->institution_id, 404);

        return $this->matrixView($request, $account, $kind, route('accounts.permissions.update', [$account, $kind]), route('accounts.show', $account), $account->name);
    }

    public function updateAccountPermissions(Request $request, User $account, string $kind): RedirectResponse
    {
        abort_unless($account->institution_id === $request->user()->institution_id, 404);

        return $this->applyMatrix($request, $account, $kind, route('accounts.permissions', [$account, $kind]));
    }

    private function matrixView(Request $request, Role|User $holder, string $kind, string $action, string $back, string $holderName): View
    {
        $notations = config("workspace.permission_matrix.$kind");
        abort_unless(is_array($notations), 404);
        $cells = $this->matrix->cells($holder, $kind);
        $institutionId = $holder->institution_id;
        $repositories = in_array($kind, ['repository', 'description'], true)
            ? Repository::where('institution_id', $institutionId)->whereNull('deleted_at')->with('descriptionValues.element', 'names.nameType', 'identifiers.identifierType')->get() : collect();
        $records = $kind === 'description'
            ? RecordResource::where('institution_id', $institutionId)->whereNull('deleted_at')->with('descriptionValues.element', 'names.nameType', 'identifiers.identifierType')->get()
                ->sortBy(fn ($r) => mb_strtolower((string) $r->described('title', ['en']))) : collect();
        // Scopes shown: those with cells, plus one being added from the form below.
        $shownRepositories = array_unique(array_filter([...array_keys($cells['repositories']), $request->query('repository')]));
        $shownRecords = array_unique(array_filter([...array_keys($cells['records']), $request->query('record')]));

        return view('roles.matrix', [
            'holderName' => $holderName, 'kind' => Concept::where('iri', Vocab::kind($kind))->with('labels')->firstOrFail(), 'kindNotation' => $kind,
            'actions' => collect($notations)->map(fn ($n) => Concept::where('iri', Vocab::permission($n))->with('labels')->firstOrFail()),
            'cells' => $cells, 'repositories' => $repositories, 'records' => $records,
            'shownRepositories' => $repositories->whereIn('id', $shownRepositories), 'shownRecords' => $records->whereIn('id', $shownRecords),
            'action' => $action, 'back' => $back,
        ]);
    }

    private function applyMatrix(Request $request, Role|User $holder, string $kind, string $back): RedirectResponse
    {
        abort_unless(is_array(config("workspace.permission_matrix.$kind")), 404);
        $data = $request->validate(['cell' => ['array'], 'cell.*' => ['string', Rule::in([PermissionMatrix::GRANT, PermissionMatrix::DENY, PermissionMatrix::INHERIT])]]);
        try {
            $this->matrix->apply($holder, $kind, $data['cell'] ?? [], $request->user());
        } catch (InvalidArgumentException $e) {
            return redirect($back)->withErrors(['cell' => $e->getMessage()]);
        }

        return redirect($back)->with('status', 'Permissions saved.');
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail('Give a BCP 47 language tag, such as en or af.')],
            'parent' => ['nullable', 'uuid'],
        ]);
    }

    private function institutionRoles(Request $request)
    {
        return Role::where('institution_id', $request->user()->institution_id)->with('labels')->get()->sortBy(fn ($r) => mb_strtolower($r->name()))->values();
    }

    private function find(Request $request, string $id): Role
    {
        $role = Role::where('institution_id', $request->user()->institution_id)->find($id);
        abort_unless($role !== null, 404);

        return $role;
    }

    private function own(Request $request, Role $role): void
    {
        abort_unless($role->institution_id === $request->user()->institution_id, 404);
    }

    private function descendantIds(Role $role): array
    {
        $ids = [];
        $frontier = [$role->id];
        while ($frontier !== []) {
            $frontier = DB::table('roles')->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    private function kinds()
    {
        return collect(array_keys(config('workspace.permission_matrix')))
            ->map(fn ($n) => Concept::where('iri', Vocab::kind($n))->with('labels')->firstOrFail());
    }
}
