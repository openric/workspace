<?php

/**
 * DbAwareLoader - Heratio. Wraps Laravel's translation loader so the JSON
 * strings of a locale are overlaid with those kept in the database.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-translation/src/Translation/DbAwareLoader.php at commit
 * bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17c). Changed: the overlay is
 * the institution's own wording (interface_strings), for the signed-in account's institution or the public
 * catalogue's, so one institution's wording never shows another's; it is cached by locale and institution.
 */

declare(strict_types=1);

namespace App\Translation;

use App\Vocabulary\Dropdowns;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\DB;
use Throwable;

class OverlayLoader implements Loader
{
    /** @var array<string, array<string, string>> */
    protected array $dbCache = [];

    public function __construct(protected Loader $inner) {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace) ?: [];
        if ($group !== '*' || ($namespace !== null && $namespace !== '*')) {
            return $lines;
        }

        return array_merge($lines, $this->loadDb((string) $locale));
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->inner->namespaces();
    }

    /** @return array<string, string> */
    protected function loadDb(string $locale): array
    {
        $institution = Dropdowns::current();
        if ($institution === null) {
            return [];
        }
        try {
            return $this->dbCache[$locale.'|'.$institution] ??= DB::table('interface_strings')->where(['institution_id' => $institution, 'locale' => $locale])
                ->where('value', '!=', '')->pluck('value', 'key')->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function flush(): void
    {
        $this->dbCache = [];
    }
}
