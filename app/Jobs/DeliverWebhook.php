<?php

// OpenRiC Workspace - one webhook delivery sent in the background (roadmap phase 22c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Webhooks\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /** The job is not retried by the queue: a failed delivery is tried again on the webhook's own schedule. */
    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(Webhooks $webhooks): void
    {
        $delivery = WebhookDelivery::with('webhook')->find($this->deliveryId);
        $delivery && $webhooks->deliver($delivery);
    }
}
