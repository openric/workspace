<?php

// OpenRiC Workspace - every encrypted field is one key rotation re-encrypts (roadmap phase 12a, runbook rotating-keys):
// a field cast as encrypted on a model and missing from ReencryptFields::FIELDS could not be read after a rotation.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit;

use App\Console\Commands\ReencryptFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class EncryptedFieldsTest extends TestCase
{
    public function test_every_encrypted_cast_is_re_encrypted_by_key_rotation(): void
    {
        $missing = $found = [];
        foreach (glob(dirname(__DIR__, 2).'/app/Models/*.php') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $casts = (fn () => $this->casts())->call($model);
            foreach (array_keys(array_filter($casts, fn ($cast) => $cast === 'encrypted')) as $column) {
                $table = (fn () => $this->table)->call($model) ?? Str::snake(Str::pluralStudly(class_basename($class)));
                $found[] = "$table.$column";
                in_array($column, ReencryptFields::FIELDS[$table] ?? [], true) || $missing[] = "$table.$column";
            }
        }
        $this->assertContains('users.two_factor_secret', $found, 'the casts are read');
        $this->assertSame([], $missing, 'encrypted fields key rotation leaves out');
    }
}
