<?php

// OpenRiC Workspace - encrypt every encrypted field again with the current APP_KEY (roadmap phase 12a): the step of
// rotating the application key after APP_PREVIOUS_KEYS holds the old one (deploy/runbooks/rotating-keys.md). A value
// that neither key opens is reported and left as it is, and the command then exits with failure.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ReencryptFields extends Command
{
    protected $signature = 'workspace:keys:reencrypt';

    protected $description = 'Encrypt every encrypted field again with the current application key, after APP_PREVIOUS_KEYS was set to the old one';

    /** Every encrypted column, by table: the models that cast them as encrypted. */
    public const FIELDS = ['researchers' => ['phone', 'id_number'], 'walk_in_visitors' => ['phone', 'id_number'], 'researcher_orcid_links' => ['access_token', 'refresh_token'],
        'research_service_settings' => ['orcid_client_secret', 'datacite_password'], 'shop_settings' => ['payfast_merchant_key', 'payfast_passphrase'], 'vendors' => ['bank_name', 'bank_branch',
            'bank_account_number', 'bank_branch_code', 'bank_account_type']];

    public function handle(): int
    {
        [$done, $failed] = [0, 0];
        foreach (self::FIELDS as $table => $columns) {
            DB::table($table)->select(['id', ...$columns])->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns, &$done, &$failed) {
                foreach ($rows as $row) {
                    $update = [];
                    foreach ($columns as $column) {
                        if ($row->{$column} === null) {
                            continue;
                        }
                        try {
                            $update[$column] = Crypt::encryptString(Crypt::decryptString($row->{$column}));
                        } catch (DecryptException) {
                            $failed++;
                            $this->error("$table {$row->id} $column: neither key opens it");
                        }
                    }
                    if ($update !== []) {
                        DB::table($table)->where('id', $row->id)->update($update);
                        $done += count($update);
                    }
                }
            });
        }
        $this->line("$done values encrypted again, $failed not opened.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
