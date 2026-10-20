<?php

// OpenRiC Workspace - IRIs of the concepts the code itself refers to.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// These are references to concepts in the vocabulary tables, not a list of
// allowed values: the vocabularies (resources/vocabularies/*.ttl) hold the
// values, and which role has which permission is data in role_grants.

namespace App\Support;

final class Vocab
{
    public const BASE = 'https://workspace.openric.org/vocab/';

    public const ACCOUNT_ROLES = self::BASE.'account-role/';

    public const PERMISSIONS = self::BASE.'permission/';

    public const AUDIT_ACTIONS = self::BASE.'audit-action/';

    public const DESCRIPTION_ELEMENTS = self::BASE.'description-element/';

    public const VALUE_STATES = self::BASE.'language-value-state/';

    public const DESCRIPTION_STATUSES = self::BASE.'description-status/';

    public const DESCRIPTION_DETAILS = self::BASE.'description-detail/';

    public static function role(string $notation): string
    {
        return self::ACCOUNT_ROLES.$notation;
    }

    public static function permission(string $notation): string
    {
        return self::PERMISSIONS.$notation;
    }

    public static function action(string $notation): string
    {
        return self::AUDIT_ACTIONS.$notation;
    }

    public static function element(string $notation): string
    {
        return self::DESCRIPTION_ELEMENTS.$notation;
    }

    public static function valueState(string $notation): string
    {
        return self::VALUE_STATES.$notation;
    }
}
