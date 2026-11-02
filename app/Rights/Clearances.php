<?php

// OpenRiC Workspace - security clearance: classification levels and compartments, accounts' clearances, records'
// classifications, and access to a classified record above one's clearance (roadmap phase 18c, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's SecurityClearanceService and its share link, IIIF and chatbot checks. Decided by Dr Johan Pieterse on
// 2 November 2026 (option 1A): the Workspace's one read check asks this too, so a classified record, its files and its
// search hits reach only an account cleared to its level, with every compartment it is in, wherever it is read: the
// catalogue, the API, exports, search, IIIF and share links. The public never reads a classified record. A record takes
// the classification of its nearest classified ancestor that passes it down, unless it has its own. An account's
// clearance ends at its expiry date. Above one's clearance, an approved request gives one account one record for a
// time. A level that forbids downloads keeps a cleared reader from downloading, unless a grant to download says
// otherwise. Who administers the institution keeps the levels, compartments and clearances, never their own; only an account cleared to a
// level classifies a record at it, or decides a request for it. A level's two-factor and session requirements are kept
// for phase 12, which brings two-factor sign-in. Levels start as Heratio's six (Public 0 to Top Secret 5).

namespace App\Rights;

use App\Audit\AuditLog;
use App\Models\AccountClearance;
use App\Models\AccountCompartment;
use App\Models\ClearanceGrant;
use App\Models\ClearanceHistoryEntry;
use App\Models\ClearanceRequest;
use App\Models\Concept;
use App\Models\RecordClassification;
use App\Models\RecordCompartment;
use App\Models\RecordResource;
use App\Models\SecurityCompartment;
use App\Models\SecurityLevel;
use App\Models\User;
use App\Search\SearchIndex;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Clearances
{
    /** Heratio's levels: [code, level, name, description, justification, approval, 2fa, watermark, download, print, copy]. */
    private const DEFAULT_LEVELS = [['PUBLIC', 0, 'Public', 'Publicly accessible material', false, false, false, false, true, true, true],
        ['INTERNAL', 1, 'Internal', 'Internal institutional use', false, false, false, false, true, true, true],
        ['RESTRICTED', 2, 'Restricted', 'Restricted material, limited staff', true, false, false, false, true, true, false],
        ['CONFIDENTIAL', 3, 'Confidential', 'Confidential archival material', true, true, false, true, false, false, false],
        ['SECRET', 4, 'Secret', 'Highly sensitive material', true, true, true, true, false, false, false],
        ['TOP_SECRET', 5, 'Top Secret', null, false, false, false, true, true, true, true]];

    /** @var array<string, mixed> what was looked up in this run, cleared whenever a classification, clearance or grant changes */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    // Levels and compartments.

    public function levels(string $institutionId): Collection
    {
        if (! SecurityLevel::where('institution_id', $institutionId)->exists()) {
            foreach (self::DEFAULT_LEVELS as [$code, $level, $name, $description, $justify, $approve, $twoFactor, $watermark, $download, $print, $copy]) {
                SecurityLevel::create(['institution_id' => $institutionId, 'code' => $code, 'level' => $level, 'name' => $name, 'description' => $description,
                    'requires_justification' => $justify, 'requires_approval' => $approve, 'requires_2fa' => $twoFactor, 'watermark_required' => $watermark,
                    'download_allowed' => $download, 'print_allowed' => $print, 'copy_allowed' => $copy]);
            }
        }

        return SecurityLevel::where('institution_id', $institutionId)->orderBy('level')->get();
    }

    public function saveLevel(User $actor, array $data, ?SecurityLevel $level = null): SecurityLevel
    {
        $this->mustAdminister($actor);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '' || trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the level a code and a name.'));
        }
        $number = (int) ($data['level'] ?? -1);
        if ($number < 0 || $number > 100) {
            throw new InvalidArgumentException(__('A level is a number from 0 to 100.'));
        }
        if (SecurityLevel::where('institution_id', $actor->institution_id)->where(fn ($q) => $q->where('code', $code)->orWhere('level', $number))->when($level, fn ($q) => $q->whereKeyNot($level->id))->exists()) {
            throw new InvalidArgumentException(__('Another level has that code or number.'));
        }
        $flags = collect(['requires_justification', 'requires_approval', 'requires_2fa', 'watermark_required', 'download_allowed', 'print_allowed', 'copy_allowed', 'is_active'])
            ->mapWithKeys(fn ($f) => [$f => ! empty($data[$f])])->all();
        $fields = ['code' => mb_substr($code, 0, 20), 'level' => $number, 'name' => mb_substr(trim($data['name']), 0, 100), 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'colour' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['colour'] ?? '')) ? $data['colour'] : null,
            'max_session_hours' => ($data['max_session_hours'] ?? '') !== '' && $data['max_session_hours'] !== null ? max(1, (int) $data['max_session_hours']) : null] + $flags;
        self::flush();

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $level ? tap($level)->update($fields) : SecurityLevel::create($fields + ['institution_id' => $actor->institution_id])));
    }

    public function saveCompartment(User $actor, array $data, ?SecurityCompartment $compartment = null): SecurityCompartment
    {
        $this->mustAdminister($actor);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '' || trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the compartment a code and a name.'));
        }
        $min = SecurityLevel::where('institution_id', $actor->institution_id)->find($data['min_level_id'] ?? null) ?? throw new InvalidArgumentException(__('Choose a level of this institution.'));
        if (SecurityCompartment::where(['institution_id' => $actor->institution_id, 'code' => $code])->when($compartment, fn ($q) => $q->whereKeyNot($compartment->id))->exists()) {
            throw new InvalidArgumentException(__('Another compartment has that code.'));
        }
        $fields = ['code' => mb_substr($code, 0, 50), 'name' => mb_substr(trim($data['name']), 0, 255), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'min_level_id' => $min->id,
            'requires_need_to_know' => ! empty($data['requires_need_to_know']), 'requires_briefing' => ! empty($data['requires_briefing']), 'is_active' => ! empty($data['is_active'])];
        self::flush();

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $compartment ? tap($compartment)->update($fields) : SecurityCompartment::create($fields + ['institution_id' => $actor->institution_id])));
    }

    // Accounts' clearances.

    /** The account's level now: null when it has none, or it has ended. */
    public function levelOf(User $user): ?SecurityLevel
    {
        return $this->remember('user:'.$user->id, function () use ($user) {
            $c = AccountClearance::where('user_id', $user->id)->with('level')->first();

            return $c && (! $c->expires_at || $c->expires_at->isFuture()) && $c->level->is_active ? $c->level : null;
        });
    }

    public function grant(User $actor, User $account, SecurityLevel $level, ?string $expiresOn, ?string $notes, array $compartmentIds = []): AccountClearance
    {
        $this->mustAdminister($actor);
        if ($account->institution_id !== $actor->institution_id || $level->institution_id !== $actor->institution_id) {
            throw new InvalidArgumentException(__('Choose an account and a level of this institution.'));
        }
        if ($account->id === $actor->id) {
            throw new InvalidArgumentException(__('Someone else grants your own clearance.'));
        }
        if ($expiresOn && $expiresOn <= now(config('workspace.timezone'))->toDateString()) {
            throw new InvalidArgumentException(__('A clearance ends after today.'));
        }
        $compartments = SecurityCompartment::where('institution_id', $actor->institution_id)->whereIn('id', $compartmentIds)->with('minLevel')->get();
        if ($compartments->count() !== count(array_unique($compartmentIds))) {
            throw new InvalidArgumentException(__('Choose compartments of this institution.'));
        }
        if ($short = $compartments->first(fn ($c) => $c->minLevel->level > $level->level)) {
            throw new InvalidArgumentException(__(':compartment needs at least :level.', ['compartment' => $short->name, 'level' => $short->minLevel->name]));
        }
        self::flush();

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $account, $level, $expiresOn, $notes, $compartments) {
            $before = AccountClearance::where('user_id', $account->id)->with('level')->first();
            $action = ! $before ? 'granted' : ($level->level > $before->level->level ? 'upgraded' : ($level->level < $before->level->level ? 'downgraded' : 'renewed'));
            $clearance = AccountClearance::updateOrCreate(['user_id' => $account->id], ['level_id' => $level->id, 'granted_by_id' => $actor->id, 'granted_at' => now(),
                'expires_at' => $expiresOn ? now(config('workspace.timezone'))->parse($expiresOn)->endOfDay() : null, 'notes' => trim((string) $notes) ?: null]);
            AccountCompartment::where('user_id', $account->id)->whereNotIn('compartment_id', $compartments->pluck('id'))->delete();
            foreach ($compartments as $c) {
                AccountCompartment::firstOrCreate(['user_id' => $account->id, 'compartment_id' => $c->id], ['granted_by_id' => $actor->id, 'granted_at' => now()]);
            }
            $this->history($account, $before?->level_id, $level->id, $action, $actor, $notes);

            return $clearance;
        }));
    }

    public function revoke(User $actor, User $account, ?string $reason): void
    {
        $this->mustAdminister($actor);
        $before = AccountClearance::where('user_id', $account->id)->first() ?? throw new InvalidArgumentException(__('The account has no clearance.'));
        self::flush();
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $account, $before, $reason) {
            AccountCompartment::where('user_id', $account->id)->delete();
            $before->delete();
            $this->history($account, $before->level_id, null, 'revoked', $actor, $reason);
        }));
    }

    // Records' classifications.

    /** The classification that holds for the record: its own, or its nearest ancestor's that passes down; with its compartments. */
    public function effective(RecordResource $record): ?RecordClassification
    {
        if (! $this->remember('any:'.$record->institution_id, fn () => RecordClassification::whereHas('record', fn ($q) => $q->where('institution_id', $record->institution_id))->exists())) {
            return null;
        }

        return $this->remember('record:'.$record->id, function () use ($record) {
            $own = RecordClassification::where('record_resource_id', $record->id)->with('level')->first();
            if ($own) {
                return $own;
            }
            foreach (array_reverse($record->ancestors()) as $ancestor) {
                $c = RecordClassification::where('record_resource_id', $ancestor->id)->with('level')->first();
                if ($c) {
                    return $c->inherit_to_children ? $c : null;
                }
            }

            return null;
        });
    }

    private function remember(string $key, callable $compute): mixed
    {
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $compute();
        }

        return self::$memo[$key];
    }

    /** May the account (or the public, with none) read the record, or download its files? */
    public function allows(?User $user, RecordResource $record, string $what = 'read'): bool
    {
        $c = $this->effective($record);
        if (! $c || $c->level->level === 0) {
            return true;
        }
        if (! $user || $user->institution_id !== $record->institution_id) {
            return false;
        }
        $grant = ClearanceGrant::where(['user_id' => $user->id, 'record_resource_id' => $record->id])->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->with('requestType')->get();
        if ($grant->contains(fn ($g) => $what === 'read' || $g->requestType->notation === 'download')) {
            return true;
        }
        $mine = $this->levelOf($user);
        if (! $mine || $mine->level < $c->level->level) {
            return false;
        }
        $needed = RecordCompartment::where('record_resource_id', $c->record_resource_id)->pluck('compartment_id');
        $held = AccountCompartment::where('user_id', $user->id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('compartment_id');
        if ($needed->diff($held)->isNotEmpty()) {
            return false;
        }

        return $what !== 'download' || $c->level->download_allowed;
    }

    public function classify(User $actor, RecordResource $record, SecurityLevel $level, array $data = [], array $compartmentIds = []): RecordClassification
    {
        if ($record->institution_id !== $actor->institution_id || $level->institution_id !== $actor->institution_id || ! $actor->may('update', 'description', $record)) {
            throw new InvalidArgumentException(__('You may not classify that record.'));
        }
        $this->mustBeCleared($actor, $level);
        if ($level->requires_justification && trim((string) ($data['justification'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the justification this level requires.'));
        }
        $to = ($data['declassify_to_level_id'] ?? '') ? SecurityLevel::where('institution_id', $actor->institution_id)->find($data['declassify_to_level_id']) : null;
        if ($to && $to->level >= $level->level) {
            throw new InvalidArgumentException(__('Declassify to a lower level.'));
        }
        $compartments = SecurityCompartment::where('institution_id', $actor->institution_id)->whereIn('id', $compartmentIds)->get();
        $fields = ['level_id' => $level->id, 'classified_by_id' => $actor->id, 'classified_at' => now(), 'review_on' => ($data['review_on'] ?? '') ?: null,
            'declassify_on' => ($data['declassify_on'] ?? '') ?: null, 'declassify_to_level_id' => $to?->id, 'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
            'handling_instructions' => trim((string) ($data['handling_instructions'] ?? '')) ?: null, 'inherit_to_children' => ! array_key_exists('inherit_to_children', $data) || ! empty($data['inherit_to_children']),
            'justification' => trim((string) ($data['justification'] ?? '')) ?: null];
        self::flush();

        $classification = DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $record, $fields, $compartments) {
            $c = RecordClassification::updateOrCreate(['record_resource_id' => $record->id], $fields);
            RecordCompartment::where('record_resource_id', $record->id)->whereNotIn('compartment_id', $compartments->pluck('id'))->delete();
            foreach ($compartments as $comp) {
                RecordCompartment::firstOrCreate(['record_resource_id' => $record->id, 'compartment_id' => $comp->id], ['granted_by_id' => $actor->id, 'created_at' => now()]);
            }

            return $c;
        }));
        $this->reindex($record);

        return $classification;
    }

    /** Declassify: to the level it names, or to none. */
    public function declassify(?User $actor, RecordResource $record, ?SecurityLevel $to = null, ?string $reason = null): void
    {
        $c = RecordClassification::where('record_resource_id', $record->id)->with('level')->first() ?? throw new InvalidArgumentException(__('It is not classified.'));
        if ($actor) {
            $this->mustBeCleared($actor, $c->level);
        }
        self::flush();
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($c, $record, $to, $reason) {
            if ($to && $to->level > 0) {
                $c->update(['level_id' => $to->id, 'declassify_on' => null, 'declassify_to_level_id' => null, 'reason' => trim(($c->reason ? $c->reason."\n" : '').(string) $reason) ?: null]);
            } else {
                RecordCompartment::where('record_resource_id', $record->id)->delete();
                $c->delete();
            }
        }));
        $this->reindex($record);
    }

    /** The daily run: declassify what is due; the number declassified. */
    public function declassifyDue(): int
    {
        $due = RecordClassification::whereNotNull('declassify_on')->where('declassify_on', '<=', now(config('workspace.timezone'))->toDateString())->with('record', 'declassifyTo')->get();
        $due->each(fn ($c) => $this->declassify(null, $c->record, $c->declassifyTo, __('Declassified on its date.')));
        // Clearances that ended are recorded as ended.
        AccountClearance::whereNotNull('expires_at')->where('expires_at', '<', now())->with('user')->get()->each(function ($c) {
            if (! ClearanceHistoryEntry::where(['user_id' => $c->user_id, 'new_level_id' => $c->level_id])->whereHas('action', fn ($q) => $q->where('iri', Vocab::BASE.'clearance-action/expired'))->where('created_at', '>', $c->granted_at)->exists()) {
                $this->history($c->user, $c->level_id, $c->level_id, 'expired', null, null);
            }
        });

        return $due->count();
    }

    // Requests for access above one's clearance.

    public function request(User $user, RecordResource $record, string $type, string $justification, string $priority = 'normal'): ClearanceRequest
    {
        if ($record->institution_id !== $user->institution_id || ! $this->effective($record)) {
            throw new InvalidArgumentException(__('That record is not classified.'));
        }
        if (trim($justification) === '') {
            throw new InvalidArgumentException(__('Say why you need it.'));
        }
        if (ClearanceRequest::where(['user_id' => $user->id, 'record_resource_id' => $record->id])->whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'clearance-request-status/pending'))->exists()) {
            throw new InvalidArgumentException(__('You have asked already; it awaits a decision.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => ClearanceRequest::create(['user_id' => $user->id, 'record_resource_id' => $record->id,
            'request_type_id' => $this->concept('clearance-request-type', $type), 'priority_id' => $this->concept('clearance-request-priority', $priority), 'justification' => trim($justification),
            'status_id' => $this->concept('clearance-request-status', 'pending')])));
    }

    public function review(User $actor, ClearanceRequest $request, bool $approve, ?string $notes, ?int $hours = null): void
    {
        $this->mustAdminister($actor);
        $c = $this->effective($request->record) ?? throw new InvalidArgumentException(__('That record is not classified.'));
        $this->mustBeCleared($actor, $c->level);
        if ($request->status->notation !== 'pending') {
            throw new InvalidArgumentException(__('It has been decided.'));
        }
        if ($request->user_id === $actor->id) {
            throw new InvalidArgumentException(__('Someone else decides your own request.'));
        }
        self::flush();
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $request, $approve, $notes, $hours) {
            $request->update(['status_id' => $this->concept('clearance-request-status', $approve ? 'approved' : 'denied'), 'reviewed_by_id' => $actor->id, 'reviewed_at' => now(),
                'review_notes' => trim((string) $notes) ?: null, 'duration_hours' => $approve ? ($hours ?: null) : null]);
            if ($approve) {
                ClearanceGrant::create(['user_id' => $request->user_id, 'record_resource_id' => $request->record_resource_id, 'request_id' => $request->id, 'request_type_id' => $request->request_type_id,
                    'granted_by_id' => $actor->id, 'granted_at' => now(), 'expires_at' => $hours ? now()->addHours($hours) : null]);
            }
        }));
    }

    public function revokeGrant(User $actor, ClearanceGrant $grant): void
    {
        $this->mustAdminister($actor);
        self::flush();
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $grant->update(['revoked_at' => now(), 'revoked_by_id' => $actor->id])));
    }

    private function history(User $account, ?string $from, ?string $to, string $action, ?User $actor, ?string $reason): void
    {
        ClearanceHistoryEntry::create(['user_id' => $account->id, 'previous_level_id' => $from, 'new_level_id' => $to, 'action_id' => $this->concept('clearance-action', $action),
            'changed_by_id' => $actor?->id, 'reason' => trim((string) $reason) ?: null, 'created_at' => now()]);
    }

    /** The record and what is inside it, as the public search sees them now. */
    private function reindex(RecordResource $record): void
    {
        $index = app(SearchIndex::class);
        RecordResource::whereIn('id', [$record->id, ...$record->descendantIds()])->get()->each(fn ($r) => $index->index($r));
    }

    private function mustAdminister(User $actor): void
    {
        if (! $actor->hasPermission(Vocab::permission('manage-institution'))) {
            throw new InvalidArgumentException(__('Only who administers the institution keeps security clearance.'));
        }
    }

    private function mustBeCleared(User $actor, SecurityLevel $level): void
    {
        if ($level->level > 0 && (($mine = $this->levelOf($actor)) === null || $mine->level < $level->level)) {
            throw new InvalidArgumentException(__('You are not cleared to :level.', ['level' => $level->name]));
        }
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
