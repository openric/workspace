<?php

/**
 * AclService - Service for Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-acl/src/Services/AclService.php
 * (bucketIoPermissions, applyAclForm, applyUserAclForm) at commit
 * a060339c592bfb4d903679899c04bd56c5fe5744 (governance/CODE_PROVENANCE.md, ADR 0005).
 * Changed: one implementation for a role and for one account (Heratio has two copies); scope
 * keys name a repository or record by UUID instead of a slug; the repository scope is a
 * column, not JSON constants; the actions of each kind come from workspace.permission_matrix;
 * every cell is validated against the institution before it is written; changes are audited.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Access;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Permission;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Role;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PermissionMatrix
{
    public const GRANT = 'grant';

    public const DENY = 'deny';

    public const INHERIT = 'inherit';

    /**
     * A holder's permissions on one kind, by scope (Heratio's bucketIoPermissions).
     *
     * @return array{all: array<string, Permission>, repositories: array<string, array<string, Permission>>, records: array<string, array<string, Permission>>}
     */
    public function cells(Role|User $holder, string $kind): array
    {
        $cells = ['all' => [], 'repositories' => [], 'records' => []];
        $permissions = Permission::where($holder instanceof Role ? 'role_id' : 'user_id', $holder->id)
            ->where('kind_id', $this->concept(Vocab::kind($kind))->id)->with('action')->get();
        foreach ($permissions as $permission) {
            $action = $permission->action->notation;
            match ($permission->scope()) {
                'record' => $cells['records'][$permission->subject_id][$action] = $permission,
                'repository' => $cells['repositories'][$permission->repository_id][$action] = $permission,
                default => $cells['all'][$action] = $permission,
            };
        }

        return $cells;
    }

    /**
     * Apply a matrix form (Heratio's applyAclForm): keys "<action>|all",
     * "<action>|repository:<uuid>" or "<action>|record:<uuid>", values grant, deny or
     * inherit. Grant or deny writes the cell; inherit removes it.
     *
     * @param  array<string, string>  $form
     */
    public function apply(Role|User $holder, string $kind, array $form, ?User $actor = null): void
    {
        $actions = config("workspace.permission_matrix.$kind") ?? throw new InvalidArgumentException("$kind is not a kind of record");
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($holder, $kind, $form, $actions) {
            foreach ($form as $key => $value) {
                if (! in_array($value, [self::GRANT, self::DENY, self::INHERIT], true)) {
                    throw new InvalidArgumentException("$value is not grant, deny or inherit");
                }
                [$action, $scope] = array_pad(explode('|', (string) $key, 2), 2, '');
                if (! in_array($action, $actions, true)) {
                    throw new InvalidArgumentException("$action does not apply to $kind");
                }
                $this->write($holder, $kind, $action, $this->scope($holder, $kind, $scope), $value);
            }
        }));
        if ($holder instanceof User) {
            app(AccessControl::class)->forget($holder);
        }
    }

    /** @param  array{repository_id: ?string, subject_table: ?string, subject_id: ?string}  $scope */
    private function write(Role|User $holder, string $kind, string $action, array $scope, string $value): void
    {
        $where = [
            $holder instanceof Role ? 'role_id' : 'user_id' => $holder->id,
            'kind_id' => $this->concept(Vocab::kind($kind))->id,
            'action_id' => $this->concept(Vocab::permission($action))->id,
            'repository_id' => $scope['repository_id'],
            'subject_id' => $scope['subject_id'],
        ];
        $existing = Permission::where($where)->first();
        if ($value === self::INHERIT) {
            $existing?->delete();

            return;
        }
        $isGrant = $value === self::GRANT;
        if ($existing) {
            if ($existing->is_grant !== $isGrant) {
                $existing->update(['is_grant' => $isGrant]);
            }

            return;
        }
        Permission::create([...$where, 'institution_id' => $holder->institution_id, 'subject_table' => $scope['subject_table'], 'is_grant' => $isGrant]);
    }

    /**
     * Resolve "all", "repository:<uuid>" or "record:<uuid>" to columns, refusing a scope
     * outside the holder's institution or one that does not fit the kind.
     *
     * @return array{repository_id: ?string, subject_table: ?string, subject_id: ?string}
     */
    private function scope(Role|User $holder, string $kind, string $scope): array
    {
        if ($scope === 'all') {
            return ['repository_id' => null, 'subject_table' => null, 'subject_id' => null];
        }
        [$type, $id] = array_pad(explode(':', $scope, 2), 2, '');
        $repository = $type === 'repository' && in_array($kind, ['repository', 'description'], true)
            ? Repository::where('institution_id', $holder->institution_id)->find($id) : null;
        if ($repository) {
            return ['repository_id' => $repository->id, 'subject_table' => null, 'subject_id' => null];
        }
        $record = match (true) {
            $type === 'record' && $kind === 'description' => RecordResource::where('institution_id', $holder->institution_id)->find($id),
            $type === 'record' && $kind === 'repository' => Repository::where('institution_id', $holder->institution_id)->find($id),
            default => null,
        };
        if ($record) {
            return ['repository_id' => null, 'subject_table' => $record->getTable(), 'subject_id' => $record->id];
        }
        throw new InvalidArgumentException("$scope is not a scope of $kind in this institution");
    }

    private function concept(string $iri): Concept
    {
        return Concept::where('iri', $iri)->whereNull('deprecated_at')->first()
            ?? throw new InvalidArgumentException("$iri is not a current concept");
    }
}
