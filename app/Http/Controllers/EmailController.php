<?php

// OpenRiC Workspace - the mail the Workspace sent, and the addresses it holds back (roadmap phase 7).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Heratio keeps the log and the hold (ahg_sent_email, user.email_bounced_at) with no screen
// for them; this is the screen: the institution's sent mail by status, its accounts held
// after a bounce or complaint with the latest report, and lifting a hold
// (EmailSuppressionGate::clear). Needs manage-accounts.

namespace App\Http\Controllers;

use App\Mail\EmailSuppressionGate;
use App\Models\Concept;
use App\Models\EmailBounce;
use App\Models\SentEmail;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailController extends Controller
{
    public function index(Request $request): View
    {
        $institutionId = $request->user()->institution_id;
        $statuses = Concept::choices(Vocab::BASE.'email-status/')->with('labels')->get();
        $status = $statuses->firstWhere('notation', $request->query('status'));
        $held = User::where('institution_id', $institutionId)->whereNotNull('email_bounced_at')->orderByDesc('email_bounced_at')->get();
        $reports = EmailBounce::whereIn('email', $held->map(fn ($u) => strtolower($u->email)))->with('type.labels')->latest('occurred_at')->get()->groupBy('email');

        return view('email.index', [
            'sent' => SentEmail::where('institution_id', $institutionId)->when($status, fn ($q) => $q->where('status_id', $status->id))
                ->with('status.labels')->latest('created_at')->latest('id')->paginate(50)->withQueryString(),
            'statuses' => $statuses, 'status' => $status?->notation, 'held' => $held, 'reports' => $reports,
        ]);
    }

    public function clear(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->institution_id === $request->user()->institution_id, 404);
        EmailSuppressionGate::clear($user->email, $request->user());

        return redirect()->route('email.index')->with('status', EmailSuppressionGate::isSuppressed($user->email)
            ? __('The hold on :email is lifted, but a complaint from it still holds it until it is 12 months old.', ['email' => $user->email])
            : __('Mail goes to :email again.', ['email' => $user->email]));
    }
}
