<?php

// OpenRiC Workspace - the OpenID Connect client's redirect to the provider, answered by the controller (roadmap phase 12b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Accounts\Oidc;

use RuntimeException;

class Redirect extends RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('Redirect to the identity provider');
    }
}
