<?php

// OpenRiC Workspace - convert an installation's fixed roles to roles and permissions (ADR 0005).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Run once by migration 2026_10_20_000500, while account_roles and role_grants
// still exist. For each institution: the default roles are created; each old
// role someone holds becomes a role with the permissions it stood for; its
// holders become members. An institution's first administrators stay
// administrators. Every change is audited.

namespace App\Access;

use App\Audit\AuditLog;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Vocab;
use App\Vocabulary\SkosImporter;
use Illuminate\Support\Facades\DB;

final class AccessMigration
{
    /** What the old fixed roles allowed, as permissions on everything. */
    private const OLD_ROLES = [
        'archivist' => ['repository' => ['read', 'create', 'update'], 'description' => ['read', 'create', 'update', 'delete', 'view-draft', 'read-master', 'read-reference', 'read-thumbnail']],
        'reviewer' => ['repository' => ['read'], 'description' => ['read', 'view-draft', 'publish', 'read-reference', 'read-thumbnail']],
    ];

    public function __construct(private SkosImporter $importer, private RoleService $roles) {}

    public function run(): void
    {
        // The concepts the new roles and permissions cite must exist first.
        foreach (config('workspace.bundled_vocabularies') as $file) {
            $this->importer->import(file_get_contents(resource_path("vocabularies/$file")));
        }
        foreach (Institution::all() as $institution) {
            DB::transaction(fn () => $this->convert($institution));
        }
    }

    private function convert(Institution $institution): void
    {
        $defaults = $this->roles->seedDefaults($institution);
        $held = DB::table('account_roles as ar')->join('concepts as c', 'c.id', '=', 'ar.role_id')
            ->join('users as u', 'u.id', '=', 'ar.user_id')->where('u.institution_id', $institution->id)
            ->get(['ar.user_id', 'ar.granted_by_id', 'c.notation', 'c.id as concept_id']);

        foreach ($held->groupBy('notation') as $notation => $rows) {
            $role = $defaults[$notation] ?? $this->oldRole($institution, $notation, $rows->first()->concept_id);
            foreach ($rows as $row) {
                DB::table('role_members')->insertOrIgnore(['role_id' => $role->id, 'user_id' => $row->user_id, 'granted_by_id' => $row->granted_by_id]);
            }
        }
        AuditLog::record(Vocab::action('update'), $institution, null,
            ['access' => 'roles and permissions (ADR 0005)', 'members' => $held->map(fn ($r) => $r->notation)->countBy()->all()], null, $institution->id);
    }

    /** A role for an old fixed role that is no longer a template, keeping its name and what it allowed. */
    private function oldRole(Institution $institution, string $notation, string $conceptId): Role
    {
        $label = DB::table('concept_labels')->where(['concept_id' => $conceptId, 'language' => 'en'])->first();
        $role = $this->roles->create($institution, ['en' => ['name' => $label->pref_label ?? ucfirst($notation), 'description' => $label->definition ?? null]], null, Vocab::role($notation));
        foreach (self::OLD_ROLES[$notation] ?? [] as $kind => $actions) {
            foreach ($actions as $action) {
                Permission::create([
                    'institution_id' => $institution->id, 'role_id' => $role->id,
                    'kind_id' => DB::table('concepts')->where('iri', Vocab::kind($kind))->value('id'),
                    'action_id' => DB::table('concepts')->where('iri', Vocab::permission($action))->value('id'),
                    'is_grant' => true,
                ]);
            }
        }

        return $role;
    }
}
