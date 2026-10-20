<?php

// OpenRiC Workspace - audit every create, change and removal of a model.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Audit;

use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;

trait Audited
{
    /** Columns whose change alone is not worth an audit row. */
    private static array $unaudited = ['updated_at', 'remember_token'];

    public static function bootAudited(): void
    {
        static::created(function (Model $model) {
            if (! $model->isAudited()) {
                return;
            }
            AuditLog::record(Vocab::action('create'), $model, null, $model->attributesToArray());
        });

        static::updated(function (Model $model) {
            if (! $model->isAudited()) {
                return;
            }
            $changed = array_diff_key($model->getChanges(), array_flip(self::$unaudited));
            if ($changed === []) {
                return;
            }
            $before = array_intersect_key($model->getPrevious(), $changed);
            AuditLog::record(Vocab::action('update'), $model, $before, $changed);
        });

        // A removed row is recorded with everything it held, so nothing is lost silently.
        static::deleted(function (Model $model) {
            if (! $model->isAudited()) {
                return;
            }
            AuditLog::record(Vocab::action('delete'), $model, $model->attributesToArray(), null);
        });
    }

    /** Whether this row's changes are audited; a model whose rows are partly system data narrows it. */
    public function isAudited(): bool
    {
        return true;
    }
}
