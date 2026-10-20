<?php

// OpenRiC Workspace - roles: create, rename, re-parent, remove, members and translation languages (ADR 0005).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace after Heratio's ahg-acl groups (createGroup,
// saveGroupProfile, addUserToGroup, removeUserFromGroup). Every change runs in
// one transaction with its audit rows.

namespace App\Access;

use App\Accounts\AccountException;
use App\Audit\AuditLog;
use App\Description\Bcp47;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleLabel;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RoleService
{
    /** @param  array<string, array{name: string, description?: ?string}>  $labels  language => name and description */
    public function create(Institution $institution, array $labels, ?Role $parent = null, ?string $templateIri = null, ?User $actor = null): Role
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $labels, $parent, $templateIri) {
            $template = $templateIri ? $this->concept($templateIri) : null;
            $parent ??= $this->bySystemTemplate($institution, 'signed-in');
            $role = Role::create([
                'institution_id' => $institution->id,
                'template_id' => $template?->id,
                'parent_id' => $parent?->id,
                'is_system' => in_array($template?->notation, ['public', 'signed-in'], true),
            ]);
            $this->saveLabels($role, $labels);

            return $role;
        }));
    }

    /** @param  array<string, array{name: string, description?: ?string}>  $labels */
    public function update(Role $role, array $labels, ?Role $parent, ?User $actor = null): void
    {
        if ($role->is_system && $parent?->id !== $role->parent_id) {
            throw new AccountException('The parent of a system role cannot change.');
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($role, $labels, $parent) {
            if ($role->parent_id !== $parent?->id) {
                $role->update(['parent_id' => $parent?->id]);
            }
            $this->saveLabels($role, $labels);
        }));
    }

    /** Remove a role the institution made; its members lose it and its permissions go with it. */
    public function remove(Role $role, ?User $actor = null): void
    {
        if ($role->is_system) {
            throw new AccountException('Public and Signed in are system roles and cannot be removed.');
        }
        if ($role->isTemplate('administrator')) {
            throw new AccountException('The Administrator role cannot be removed.');
        }
        if (Role::where('parent_id', $role->id)->exists()) {
            throw new AccountException('Other roles inherit from this role; give them another parent first.');
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($role, $actor) {
            foreach ($role->members as $member) {
                $this->removeMember($role, $member, $actor);
            }
            // Removed one by one, so each permission's removal is audited with what it held.
            $role->permissions->each->delete();
            $role->labels->each->delete();
            DB::table('role_translation_languages')->where('role_id', $role->id)->delete();
            $role->delete();
        }));
    }

    public function addMember(Role $role, User $account, ?User $actor = null): void
    {
        if ($role->is_system) {
            throw new AccountException('Everyone belongs to Public, and every signed-in account to Signed in; they have no members to add.');
        }
        if ($account->institution_id !== $role->institution_id) {
            throw new InvalidArgumentException('a role can only hold accounts of its own institution');
        }
        DB::transaction(function () use ($role, $account, $actor) {
            $added = DB::table('role_members')->insertOrIgnore(['role_id' => $role->id, 'user_id' => $account->id, 'granted_by_id' => $actor?->id]);
            if ($added > 0) {
                AuditLog::record(Vocab::action('grant-role'), $account, null, ['role' => $role->id, 'name' => $role->name()], $actor);
            }
        });
        app(AccessControl::class)->forget($account);
    }

    public function removeMember(Role $role, User $account, ?User $actor = null): void
    {
        DB::transaction(function () use ($role, $account, $actor) {
            if ($role->isTemplate('administrator')) {
                $this->keepAnAdministrator($role, $account);
            }
            $removed = DB::table('role_members')->where(['role_id' => $role->id, 'user_id' => $account->id])->delete();
            if ($removed > 0) {
                AuditLog::record(Vocab::action('revoke-role'), $account, ['role' => $role->id, 'name' => $role->name()], null, $actor);
            }
        });
        app(AccessControl::class)->forget($account);
    }

    /** @param  list<string>  $languages  BCP 47 tags */
    public function setTranslationLanguages(Role $role, array $languages, ?User $actor = null): void
    {
        $languages = array_values(array_unique(array_filter(array_map('trim', $languages))));
        foreach ($languages as $language) {
            if (! Bcp47::valid($language)) {
                throw new InvalidArgumentException("$language is not a BCP 47 language tag");
            }
        }
        DB::transaction(function () use ($role, $languages, $actor) {
            $before = $role->translationLanguages();
            if ($before === $languages) {
                return;
            }
            DB::table('role_translation_languages')->where('role_id', $role->id)->delete();
            foreach ($languages as $language) {
                DB::table('role_translation_languages')->insert(['role_id' => $role->id, 'language' => $language]);
            }
            AuditLog::record(Vocab::action('update'), $role, ['translation_languages' => $before], ['translation_languages' => $languages], $actor);
        });
    }

    /**
     * Create an institution's roles from the templates in workspace.default_roles,
     * with their permissions on everything. Roles that exist already are left alone.
     *
     * @return array<string, Role> template notation => role
     */
    public function seedDefaults(Institution $institution): array
    {
        $roles = [];
        foreach (config('workspace.default_roles') as $notation => $definition) {
            $existing = $this->byTemplate($institution, $notation);
            if ($existing) {
                $roles[$notation] = $existing;

                continue;
            }
            $template = $this->concept(Vocab::role($notation));
            $label = $template->labels()->where('language', 'en')->first();
            $roles[$notation] = $this->create($institution, ['en' => ['name' => $label->pref_label, 'description' => $label->definition]],
                $definition['parent'] ? $roles[$definition['parent']] : null, $template->iri);
            if ($definition['parent'] === null) {
                $roles[$notation]->update(['parent_id' => null]);
            }
            $grants = $definition['grants'] === '*' ? config('workspace.permission_matrix') : $definition['grants'];
            foreach ($grants as $kind => $actions) {
                foreach ($actions as $action) {
                    Permission::create([
                        'institution_id' => $institution->id, 'role_id' => $roles[$notation]->id,
                        'kind_id' => $this->concept(Vocab::kind($kind))->id, 'action_id' => $this->concept(Vocab::permission($action))->id,
                        'is_grant' => true,
                    ]);
                }
            }
        }

        return $roles;
    }

    public function byTemplate(Institution $institution, string $notation): ?Role
    {
        return Role::where('institution_id', $institution->id)
            ->whereHas('template', fn ($q) => $q->where('iri', Vocab::role($notation)))->first();
    }

    private function bySystemTemplate(Institution $institution, string $notation): ?Role
    {
        return $this->byTemplate($institution, $notation);
    }

    /** @param  array<string, array{name: string, description?: ?string}>  $labels */
    private function saveLabels(Role $role, array $labels): void
    {
        foreach ($labels as $language => $label) {
            if (! Bcp47::valid($language)) {
                throw new InvalidArgumentException("$language is not a BCP 47 language tag");
            }
            $name = trim((string) ($label['name'] ?? ''));
            $existing = RoleLabel::where(['role_id' => $role->id, 'language' => $language])->first();
            if ($name === '') {
                $existing?->delete();

                continue;
            }
            $values = ['name' => $name, 'description' => trim((string) ($label['description'] ?? '')) ?: null];
            $existing ? $existing->fill($values)->save() : RoleLabel::create(['role_id' => $role->id, 'language' => $language, ...$values]);
        }
        if (! RoleLabel::where('role_id', $role->id)->exists()) {
            throw new InvalidArgumentException('a role needs a name in at least one language');
        }
        $role->unsetRelation('labels');
    }

    /** The institution must keep at least one active member of its Administrator role. */
    private function keepAnAdministrator(Role $role, User $leaving): void
    {
        $others = DB::table('role_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.role_id', $role->id)->whereNull('u.disabled_at')->where('u.id', '<>', $leaving->id)->exists();
        if (! $others) {
            throw new AccountException('This is the last active administrator of the institution; give another account the Administrator role first.');
        }
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
