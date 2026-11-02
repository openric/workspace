<?php

// OpenRiC Workspace - notices to researchers, kept in the Workspace and mailed as each prefers (roadmap phase 19j,
// ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's research_notification and research_notification_preference. Heratio showed notices and kept
// preferences but wrote no notice and read no preference; here every research notice (a ResearchMail) is one. For
// each kind of notice an account chooses whether it shows in the Workspace, whether it is mailed, and how often:
// at once, gathered hourly, daily or weekly into one digest, or never. Without a choice, a notice shows and is
// mailed at once. The digests go out from workspace:research-digests, every hour.

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Concept;
use App\Models\ResearchNotification;
use App\Models\ResearchNotificationPreference;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Notifications
{
    private const PERIODS = ['hourly' => 1, 'daily' => 24, 'weekly' => 168];

    public function __construct(private Outbox $outbox) {}

    /** Keep the notice and mail it as the account prefers; true when it was kept or mailed. */
    public function deliver(User $to, ResearchMail $mail): bool
    {
        if ($to->isDisabled()) {
            return false;
        }
        $type = $this->concept('notification-type', $mail->kind ?? 'system');
        $pref = ResearchNotificationPreference::where(['user_id' => $to->id, 'type_id' => $type])->with('digest')->first();
        $digest = $pref?->digest->notation ?? 'immediate';
        $email = ($pref?->email_enabled ?? true) && $digest !== 'never';
        $locale = $to->preferredLocale();
        $notice = ResearchNotification::create(['user_id' => $to->id, 'type_id' => $type, 'title' => mb_substr(__($mail->subjectLine, $mail->subjectReplace, $locale), 0, 500),
            'message' => collect($mail->lines)->map(fn ($l) => is_array($l) ? __($l[0], $l[1] ?? [], $locale) : __($l, [], $locale))->implode("\n") ?: null,
            'link' => $mail->link, 'in_app' => $pref?->in_app_enabled ?? true, 'email_due' => $email && $digest !== 'immediate', 'created_at' => now()]);
        if ($email && $digest === 'immediate' && $this->outbox->mail($to, $mail)) {
            $notice->update(['emailed_at' => now()]);
        }

        return true;
    }

    public function list(User $user, ?string $type = null): Collection
    {
        return ResearchNotification::where(['user_id' => $user->id, 'in_app' => true])->with('type')
            ->when($type, fn ($q) => $q->whereHas('type', fn ($t) => $t->where('iri', Vocab::BASE.'notification-type/'.$type)))->orderByDesc('created_at')->limit(200)->get();
    }

    public static function unread(User $user): int
    {
        return ResearchNotification::where(['user_id' => $user->id, 'in_app' => true])->whereNull('read_at')->count();
    }

    /** Mark one notice read, or all. */
    public function read(User $user, ?ResearchNotification $notice = null): void
    {
        if ($notice && $notice->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        ResearchNotification::where('user_id', $user->id)->whereNull('read_at')->when($notice, fn ($q) => $q->whereKey($notice->id))->update(['read_at' => now()]);
    }

    /** [type notation => [in_app_enabled, email_enabled, digest]] for every kind. */
    public function preferences(User $user): array
    {
        $kept = ResearchNotificationPreference::where('user_id', $user->id)->with('type', 'digest')->get()->keyBy(fn ($p) => $p->type->notation);

        return Concept::choices(Vocab::BASE.'notification-type/')->get()->mapWithKeys(fn ($c) => [$c->notation => ['concept' => $c,
            'in_app' => $kept[$c->notation]->in_app_enabled ?? true, 'email' => $kept[$c->notation]->email_enabled ?? true, 'digest' => $kept[$c->notation]?->digest->notation ?? 'immediate']])->all();
    }

    /** @param array<string, array{in_app?: mixed, email?: mixed, digest?: string}> $prefs by type notation */
    public function savePreferences(User $user, array $prefs): void
    {
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $prefs) {
            foreach ($prefs as $type => $p) {
                ResearchNotificationPreference::updateOrCreate(['user_id' => $user->id, 'type_id' => $this->concept('notification-type', (string) $type)],
                    ['in_app_enabled' => ! empty($p['in_app']), 'email_enabled' => ! empty($p['email']), 'digest_id' => $this->concept('digest-frequency', (string) ($p['digest'] ?? 'immediate'))]);
            }
        }));
    }

    /** Send each account whose digest is due one mail of the notices held for it; the number of digests sent. */
    public function digests(): int
    {
        $sent = 0;
        $held = ResearchNotification::where('email_due', true)->whereNull('emailed_at')->with('user', 'type')->orderBy('created_at')->get()->groupBy('user_id');
        foreach ($held as $notices) {
            $user = $notices->first()->user;
            $prefs = ResearchNotificationPreference::where('user_id', $user->id)->with('type', 'digest')->get()->keyBy('type_id');
            $due = $notices->filter(function ($n) use ($prefs) {
                $p = $prefs[$n->type_id] ?? null;
                $hours = self::PERIODS[$p?->digest->notation] ?? 0;

                return $hours && (! $p->last_digest_at || $p->last_digest_at->lte(now()->subHours($hours)->addMinutes(5)));
            });
            if ($due->isEmpty()) {
                continue;
            }
            $mail = new ResearchMail($user, 'Your research notices', [], $due->map(fn ($n) => ['- :title', ['title' => $n->title]])->values()->all(), route('research.notifications'), 'Open your notices', 'system');
            if ($this->outbox->mail($user, $mail)) {
                ResearchNotification::whereKey($due->pluck('id'))->update(['emailed_at' => now()]);
                ResearchNotificationPreference::where('user_id', $user->id)->whereIn('type_id', $due->pluck('type_id')->unique())->update(['last_digest_at' => now()]);
                $sent++;
            }
        }

        return $sent;
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
