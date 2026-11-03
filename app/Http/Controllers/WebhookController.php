<?php

// OpenRiC Workspace - webhooks and their deliveries, for who administers the institution (roadmap phase 22c), after
// Heratio's ahg-api WebhookController (list, add, change, a new secret, deliveries).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Vocab;
use App\Webhooks\Webhooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class WebhookController extends Controller
{
    public function index(Request $request): View
    {
        $hooks = Webhook::where('institution_id', $request->user()->institution_id)->orderBy('name')->get();

        return view('webhooks.index', ['hooks' => $hooks, 'events' => Concept::choices(Vocab::BASE.'webhook-event/')->get(), 'kinds' => Webhooks::KINDS,
            'deliveries' => WebhookDelivery::whereIn('webhook_id', $hooks->pluck('id'))->with('webhook', 'event', 'status')->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function save(Request $request, ?Webhook $hook = null): RedirectResponse
    {
        abort_if($hook && $hook->institution_id !== $request->user()->institution_id, 404);
        try {
            [, $secret] = app(Webhooks::class)->save($request->user()->institution_id, $request->user()->id, $request->all(), $hook);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['webhooks' => $e->getMessage()]);
        }

        // The secret is shown once; the receiver checks each delivery's X-OpenRiC-Signature with it.
        return back()->with('status', __('Saved.'))->with('secret', $secret);
    }
}
