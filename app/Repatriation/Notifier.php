<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/RepatriationNotifier.php at
 * commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d2): claimRegistered
 * and claimStatusChanged tell staff of a new claim and each change of its status, and the claimant at the claim's
 * email, and never fail what they tell of; the institution may turn it off. Changed: staff are told by mail, each
 * account that may manage repatriation claims but the one who acted (Heratio notifies administrators in the
 * application, and the claim's creator); the email is the claim's own field, not found in its contact text; the
 * namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repatriation;

use App\Mail\Outbox;
use App\Mail\RepatriationClaimantMail;
use App\Mail\RepatriationClaimMail;
use App\Models\Concept;
use App\Models\RepatriationClaim;
use App\Models\RepatriationSetting;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;

class Notifier
{
    public function __construct(private Outbox $outbox) {}

    public function registered(RepatriationClaim $claim, ?User $actor): void
    {
        $this->tell($claim, $actor, null, null);
    }

    public function statusChanged(RepatriationClaim $claim, ?string $fromId, string $toId, ?User $actor): void
    {
        $label = fn (?string $id) => $id ? (Concept::find($id)?->label(Vocab::languages()) ?? '') : __('Registered');
        $this->tell($claim, $actor, $label($fromId), $label($toId));
    }

    /** Those who manage the institution's claims. */
    public function managers(string $institutionId): Collection
    {
        return User::where('institution_id', $institutionId)->whereNull('disabled_at')->get()
            ->filter(fn (User $u) => $u->hasPermission(Vocab::permission('manage-repatriation')))->values();
    }

    private function tell(RepatriationClaim $claim, ?User $actor, ?string $from, ?string $to): void
    {
        if (! RepatriationSetting::for($claim->institution_id)->notify) {
            return;
        }
        rescue(function () use ($claim, $actor, $from, $to) {
            foreach ($this->managers($claim->institution_id) as $user) {
                $user->id === $actor?->id || $this->outbox->send($user, new RepatriationClaimMail($user, $claim, $from, $to));
            }
            $claim->contact_email && $this->outbox->address($claim->contact_email, new RepatriationClaimantMail($claim, $from, $to), $claim->institution_id);
        }, report: false);
    }
}
