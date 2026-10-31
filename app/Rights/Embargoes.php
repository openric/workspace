<?php

// OpenRiC Workspace - embargoes on units of description and their files (roadmap phase 13a, ADR 0015), after Heratio's
// EmbargoService (getActiveEmbargo, checkAccess, canViewMetadata, canViewThumbnail, canViewDigitalObject, canDownload,
// createEmbargoWithPropagation, liftEmbargo, the exceptions, getExpiringEmbargoes, getStatistics) and EmbargoProcessCommand
// with EmbargoNotificationService (the warnings before an embargo ends, and the notice when it has).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// What an embargo withholds is its type: a full embargo the record itself, description only its files, files
// withheld its reference copy and master, no master its master. It binds who may not change the record, as Heratio's
// binds who is not an editor or administrator, unless an exception opens it to them: their account, one of their roles,
// or the address they come from. Changed: an embargo is in force by its dates, so nothing has to run for it to start
// or end; the daily run only tells people. Heratio's notices go to the donor's and repository's addresses too; here
// they go to accounts of the institution only, through the Outbox, as every notice of the Workspace does.

namespace App\Rights;

use App\Audit\AuditLog;
use App\Mail\EmbargoMail;
use App\Mail\Outbox;
use App\Models\Concept;
use App\Models\Embargo;
use App\Models\EmbargoException;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Embargoes
{
    public const TYPES = Vocab::BASE.'embargo-type/';

    public const REASONS = Vocab::BASE.'embargo-reason/';

    /** What each type still lets through, of the record, thumbnail, reference copy and master. */
    private const ALLOWS = [
        'full' => [],
        'metadata-only' => ['record'],
        'digital-only' => ['record', 'thumbnail'],
        'partial' => ['record', 'thumbnail', 'reference'],
    ];

    /** The embargo in force on a record today, the strictest when there are several. */
    public function inForce(RecordResource $unit): ?Embargo
    {
        return Embargo::where('record_resource_id', $unit->id)->inForce()->with('type', 'exceptions.user', 'exceptions.role')->get()
            ->sortBy(fn (Embargo $e) => count(self::ALLOWS[$e->type->notation] ?? []))->first();
    }

    /**
     * May this account (null: a visitor) have the record (record) or one of its files (thumbnail, reference, master)?
     * Who may change the record is not bound; an exception in force opens the embargo.
     */
    public function allows(?User $user, RecordResource $unit, string $what): bool
    {
        $embargo = $this->inForce($unit);
        if ($embargo === null || in_array($what, self::ALLOWS[$embargo->type->notation] ?? [], true)) {
            return true;
        }
        if ($user !== null && $user->institution_id === $unit->institution_id && $user->may('update', 'description', $unit)) {
            return true;
        }

        return $this->excepted($embargo, $user, request()->ip());
    }

    /**
     * Put a record under embargo, and with $inside everything inside it, each its own embargo, as Heratio does.
     *
     * @param  array{type: string, reason?: ?string, note?: ?string, public_message?: ?string, starts_on: string, ends_on?: ?string, notify_days_before?: ?int}  $data
     * @return list<Embargo>
     */
    public function impose(RecordResource $unit, array $data, bool $inside, User $actor): array
    {
        $fields = $this->fields($data);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($unit, $fields, $inside, $actor) {
            $units = [$unit, ...($inside ? $this->descendants($unit) : [])];

            return array_map(fn (RecordResource $u) => Embargo::create([...$fields, 'institution_id' => $u->institution_id, 'record_resource_id' => $u->id,
                'created_by_id' => $actor->id]), $units);
        }));
    }

    public function change(Embargo $embargo, array $data, User $actor): void
    {
        $fields = $this->fields($data);
        // A new end may need a new warning.
        if (($fields['ends_on'] ?? null) !== $embargo->ends_on?->toDateString()) {
            $fields['warned_on'] = null;
            $fields['ended_notice_at'] = null;
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $embargo->update($fields)));
    }

    /** Lift it now, with the reason, and tell who should know. */
    public function lift(Embargo $embargo, ?string $reason, User $actor): void
    {
        if ($embargo->lifted_at !== null) {
            return;
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $embargo->update(['lifted_at' => now(), 'lifted_by_id' => $actor->id,
            'lift_reason' => trim((string) $reason) ?: null, 'ended_notice_at' => now()])));
        $this->tell($embargo->fresh(), 'lifted');
    }

    /** @param array{user_id?: ?string, role_id?: ?string, ip_from?: ?string, ip_to?: ?string, valid_from?: ?string, valid_until?: ?string, note?: ?string} $data */
    public function except(Embargo $embargo, array $data, User $actor): EmbargoException
    {
        $ipFrom = trim((string) ($data['ip_from'] ?? '')) ?: null;
        $ipTo = trim((string) ($data['ip_to'] ?? '')) ?: $ipFrom;
        foreach (array_filter([$ipFrom, $ipTo]) as $ip) {
            filter_var($ip, FILTER_VALIDATE_IP) || throw new InvalidArgumentException(__(':value is not an IP address', ['value' => $ip]));
        }
        if ($ipFrom && $ipTo && (str_contains($ipFrom, ':') !== str_contains($ipTo, ':') || inet_pton($ipFrom) > inet_pton($ipTo))) {
            throw new InvalidArgumentException(__('An IP range runs from the lower address to the higher, in one family.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => EmbargoException::create([
            'embargo_id' => $embargo->id, 'user_id' => ($data['user_id'] ?? '') ?: null, 'role_id' => ($data['role_id'] ?? '') ?: null,
            'ip_from' => $ipFrom, 'ip_to' => $ipFrom ? $ipTo : null, 'valid_from' => ($data['valid_from'] ?? '') ?: null,
            'valid_until' => ($data['valid_until'] ?? '') ?: null, 'note' => trim((string) ($data['note'] ?? '')) ?: null, 'granted_by_id' => $actor->id])));
    }

    public function removeException(EmbargoException $exception, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $exception->delete()));
    }

    /**
     * The daily run: a warning to who should know when an embargo ends within its warning days, once; and a notice once
     * an embargo has ended by its date.
     *
     * @return array{warned: int, ended: int}
     */
    public function process(?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now(config('workspace.timezone')))->toDateString();
        $counts = ['warned' => 0, 'ended' => 0];
        $warn = Embargo::whereNull('lifted_at')->whereNull('warned_on')->whereNotNull('ends_on')->whereNotNull('notify_days_before')
            ->where('ends_on', '>=', $today)->whereRaw('ends_on - notify_days_before <= ?::date', [$today])->get();
        foreach ($warn as $embargo) {
            $embargo->update(['warned_on' => $today]);
            $this->tell($embargo, 'ending');
            $counts['warned']++;
        }
        foreach (Embargo::whereNull('lifted_at')->whereNull('ended_notice_at')->where('ends_on', '<', $today)->get() as $embargo) {
            $embargo->update(['ended_notice_at' => now()]);
            $this->tell($embargo, 'ended');
            $counts['ended']++;
        }

        return $counts;
    }

    /**
     * Heratio's statistics: in force by type, ending within the days given, and ended but not yet told.
     *
     * @return array{in_force: int, by_type: array<string, int>, ending: int, total: int}
     */
    public function statistics(string $institutionId, int $days = 30): array
    {
        $inForce = Embargo::where('institution_id', $institutionId)->inForce()->with('type')->get();

        return ['total' => Embargo::where('institution_id', $institutionId)->count(), 'in_force' => $inForce->count(),
            'by_type' => $inForce->countBy(fn ($e) => $e->type->notation)->all(), 'ending' => $this->ending($institutionId, $days)->count()];
    }

    /** In force, and ending within the days given. */
    public function ending(string $institutionId, int $days = 30): Collection
    {
        $today = CarbonImmutable::now(config('workspace.timezone'));

        return Embargo::where('institution_id', $institutionId)->inForce()->whereNotNull('ends_on')->where('ends_on', '<=', $today->addDays($days)->toDateString())
            ->with('record', 'type')->orderBy('ends_on')->get();
    }

    private function excepted(Embargo $embargo, ?User $user, ?string $ip): bool
    {
        $today = CarbonImmutable::now(config('workspace.timezone'))->toDateString();
        $roles = $user ? $user->roles()->pluck('roles.id')->all() : [];
        foreach ($embargo->exceptions as $exception) {
            if (($exception->valid_from && $exception->valid_from->toDateString() > $today) || ($exception->valid_until && $exception->valid_until->toDateString() < $today)) {
                continue;
            }
            if (($user && $exception->user_id === $user->id) || ($exception->role_id && in_array($exception->role_id, $roles, true))
                || ($ip && $exception->ip_from && $this->inRange($ip, $exception->ip_from, $exception->ip_to))) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $ip, string $from, string $to): bool
    {
        [$ip, $from, $to] = [@inet_pton($ip), @inet_pton($from), @inet_pton($to)];

        return $ip !== false && $from !== false && strlen($ip) === strlen($from) && $ip >= $from && $ip <= $to;
    }

    /** Tell who should know: who imposed it, else who may administer the institution. */
    private function tell(Embargo $embargo, string $event): void
    {
        $embargo->loadMissing('record', 'type', 'createdBy');
        $recipients = $embargo->createdBy && $embargo->createdBy->disabled_at === null ? collect([$embargo->createdBy])
            : User::where('institution_id', $embargo->institution_id)->whereNull('disabled_at')->get()->filter(fn (User $u) => $u->hasPermission(Vocab::permission('manage-institution')));
        foreach ($recipients as $to) {
            app(Outbox::class)->send($to, new EmbargoMail($to, $embargo, $event));
        }
    }

    private function fields(array $data): array
    {
        $type = Concept::where('iri', self::TYPES.($data['type'] ?? ''))->value('id') ?? throw new InvalidArgumentException(__('Choose what the embargo withholds.'));
        $reason = ($data['reason'] ?? '') ? (Concept::where('iri', self::REASONS.$data['reason'])->value('id') ?? throw new InvalidArgumentException(__('Choose a reason from the list.'))) : null;
        $starts = ($data['starts_on'] ?? '') ?: CarbonImmutable::now(config('workspace.timezone'))->toDateString();
        $ends = ($data['ends_on'] ?? '') ?: null;
        if ($ends !== null && $ends < $starts) {
            throw new InvalidArgumentException(__('An embargo ends on or after the day it begins.'));
        }

        return ['type_id' => $type, 'reason_id' => $reason, 'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'public_message' => trim((string) ($data['public_message'] ?? '')) ?: null, 'starts_on' => $starts, 'ends_on' => $ends,
            'notify_days_before' => ($data['notify_days_before'] ?? '') !== '' && $data['notify_days_before'] !== null ? (int) $data['notify_days_before'] : null];
    }

    /** @return list<RecordResource> every unit inside it, at any depth */
    private function descendants(RecordResource $unit): array
    {
        $out = [];
        foreach (RecordResource::where('parent_id', $unit->id)->whereNull('deleted_at')->get() as $child) {
            $out = [...$out, $child, ...$this->descendants($child)];
        }

        return $out;
    }
}
