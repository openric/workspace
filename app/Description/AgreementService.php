<?php

// OpenRiC Workspace - donor agreements (Heratio's ahg-donor-manage DonorController agreement actions and its
// DonorRemindersCommand, AtoM's ahgDonorAgreementPlugin; roadmap phase 9b, ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An agreement keeps its number (its local identifier, unique in the institution), its texts in each language
// (agreement elements), and Heratio's columns. Its donor, the accessions it documents and the units of description it
// covers are relations (RelationService); its rights, restrictions, reminders and documents are kept here. A reminder
// is sent to its accounts advance_days before its date, once for each date, and comes again if it recurs.

namespace App\Description;

use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Mail\AgreementReminderMail;
use App\Mail\Outbox;
use App\Models\Agent;
use App\Models\Agreement;
use App\Models\AgreementDocument;
use App\Models\AgreementReminder;
use App\Models\AgreementRestriction;
use App\Models\AgreementRight;
use App\Models\Concept;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgreementService
{
    /** Columns that hold a date. */
    private const DAYS = Agreement::DATES;

    /** Columns that hold free text, as Heratio has them. */
    private const TEXTS = ['transfer_method', 'received_by', 'legal_representative', 'legal_representative_title', 'repository_representative',
        'repository_representative_title', 'donor_signature_name', 'repository_signature_name', 'witness_name'];

    /** The rows of an agreement: model, each concept field's vocabulary, its text, day, flag and number fields. */
    private const ROWS = [
        'rights' => [AgreementRight::class, ['right' => 'agreement-right', 'permission' => 'agreement-permission'], ['conditions', 'fee_structure', 'note'],
            ['starts_on', 'ends_on'], ['applies_to_digital', 'applies_to_physical', 'applies_to_metadata', 'requires_donor_approval', 'requires_fee']],
        'restrictions' => [AgreementRestriction::class, ['restriction_type' => 'agreement-restriction-type', 'popia_category' => 'popia-category'],
            ['specific_materials', 'box_list', 'release_trigger', 'override_authority', 'reason', 'legal_basis', 'note'],
            ['starts_on', 'ends_on', 'release_on'], ['applies_to_all', 'auto_release', 'can_be_overridden', 'is_active']],
    ];

    /** How far each recurrence moves a reminder on. */
    private const RECURRENCES = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month', 'quarterly' => '+3 months', 'yearly' => '+1 year'];

    public function __construct(private DescriptionService $descriptions, private IdentifierService $identifiers, private FileStore $store, private Outbox $outbox) {}

    /** A new agreement, in draft, with its number (required, unique in the institution), its type and, optionally, a title. */
    public function create(Institution $institution, string $number, string $type, ?string $title, string $language, ?User $actor = null): Agreement
    {
        $number = $this->freeNumber($institution->id, $number);
        $type = $this->term('agreement-type', $type) ?? throw new InvalidArgumentException(__('choose the type of agreement'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $number, $type, $title, $language) {
            $id = (string) Str::uuid7();
            $agreement = new Agreement(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Agreement::IRI_SEGMENT.'/'.$id,
                'type_id' => $type->id, 'status_id' => $this->term('agreement-status', 'draft')->id]);
            $agreement->id = $id;
            $agreement->save();
            $this->identifiers->setLocal($agreement, $number);
            if (trim((string) $title) !== '') {
                $this->describe($agreement, ['agreement-title' => $title], $language);
            }

            return $agreement;
        }));
    }

    /** @param  array<string, ?string>  $values  agreement element notation => text */
    public function describe(Agreement $agreement, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($agreement, Elements::AGREEMENT, $values, $language, null, $actor);
    }

    /**
     * Heratio's agreement columns. Fields: number, type, status (notations), repository, parent, supersedes (ids), the
     * dates (YYYY-MM-DD), the parties' names, has_financial_terms, purchase_amount, currency and is_template.
     */
    public function update(Agreement $agreement, array $fields, ?User $actor = null): void
    {
        $number = $this->freeNumber($agreement->institution_id, (string) ($fields['number'] ?? ''), $agreement);
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $columns = [
            'type_id' => ($this->term('agreement-type', $fields['type'] ?? null) ?? throw new InvalidArgumentException(__('choose the type of agreement')))->id,
            'status_id' => ($this->term('agreement-status', $fields['status'] ?? null) ?? throw new InvalidArgumentException(__('choose the status')))->id,
            'repository_id' => $text('repository') === null ? null : (Repository::where('institution_id', $agreement->institution_id)->whereNull('deleted_at')->find($fields['repository'])
                ?? throw new InvalidArgumentException(__('the repository is one of the institution\'s')))->id,
            'parent_id' => $this->other($agreement, $text('parent')),
            'supersedes_id' => $this->other($agreement, $text('supersedes')),
            'has_financial_terms' => (bool) ($fields['has_financial_terms'] ?? false),
            'purchase_amount' => $this->amount($text('purchase_amount')),
            'currency' => strtoupper($text('currency') ?? 'ZAR'),
            'is_template' => (bool) ($fields['is_template'] ?? false),
        ];
        preg_match('/^[A-Z]{3}$/', $columns['currency']) || throw new InvalidArgumentException(__('give the currency as an ISO 4217 code, such as ZAR'));
        foreach (self::DAYS as $day) {
            $columns[$day] = $this->day($text($day));
        }
        foreach (self::TEXTS as $field) {
            $columns[$field] = $text($field);
        }
        if ($columns['effective_on'] && $columns['expires_on'] && $columns['effective_on'] > $columns['expires_on']) {
            throw new InvalidArgumentException(__('an agreement cannot expire before it takes effect'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($agreement, $number, $columns) {
            $agreement->update($columns);
            $this->identifiers->setLocal($agreement, $number);
        }));
    }

    /** Add a right (right, permission, ...) or a restriction (restriction_type, popia_category, ...). */
    public function addRow(Agreement $agreement, string $kind, array $fields, ?User $actor = null): Model
    {
        [$class, $terms, $texts, $days, $flags] = self::ROWS[$kind] ?? throw new InvalidArgumentException("$kind is not a kind of agreement row");
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $values = [];
        foreach ($terms as $field => $vocabulary) {
            $values[$field.'_id'] = $this->term($vocabulary, $fields[$field] ?? null)?->id;
        }
        foreach ($texts as $field) {
            $values[$field] = $text($field);
        }
        foreach ($days as $field) {
            $values[$field] = $this->day($text($field));
        }
        foreach ($flags as $field) {
            $values[$field] = (bool) ($fields[$field] ?? false);
        }
        if (($values['starts_on'] ?? null) && ($values['ends_on'] ?? null) && $values['starts_on'] > $values['ends_on']) {
            throw new InvalidArgumentException(__('it cannot end before it starts'));
        }
        if ($kind === 'rights') {
            $values['right_id'] ?? throw new InvalidArgumentException(__('choose the right'));
            $values['permission_id'] ?? throw new InvalidArgumentException(__('choose whether it is granted'));
        } else {
            $values['restriction_type_id'] ?? throw new InvalidArgumentException(__('choose the kind of restriction'));
            $consent = $text('data_subject_consent');
            $values['data_subject_consent'] = $consent === null ? null : $consent === '1';
            $level = $text('security_clearance_level');
            $values['security_clearance_level'] = $level === null ? null
                : (ctype_digit($level) && (int) $level <= 9 ? (int) $level : throw new InvalidArgumentException(__('give the clearance level as a number from 0 to 9')));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $class::create($values + ['institution_id' => $agreement->institution_id, 'agreement_id' => $agreement->id])));
    }

    public function removeRow(Agreement $agreement, string $kind, string $id, ?User $actor = null): void
    {
        $class = match ($kind) {
            'rights', 'restrictions' => self::ROWS[$kind][0],
            'reminders' => AgreementReminder::class,
            default => throw new InvalidArgumentException("$kind is not a kind of agreement row"),
        };
        $row = $class::where('agreement_id', $agreement->id)->findOrFail($id);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $row->delete()));
    }

    /**
     * A reminder. Fields: type, priority, recurrence (notations), subject, description, action_required, remind_on,
     * advance_days, recurrence_ends_on, notify_email, recipients (account ids of the institution).
     */
    public function addReminder(Agreement $agreement, array $fields, User $actor): AgreementReminder
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $advance = $text('advance_days') ?? '30';
        ctype_digit($advance) && (int) $advance <= 366 || throw new InvalidArgumentException(__('give the days ahead as a number from 0 to 366'));
        $recipients = User::where('institution_id', $agreement->institution_id)->whereIn('id', array_filter((array) ($fields['recipients'] ?? []), 'is_string'))->get();
        count($recipients) === count(array_unique(array_filter((array) ($fields['recipients'] ?? []), 'is_string'))) || throw new InvalidArgumentException(__('send reminders only to accounts of the institution'));
        $values = [
            'type_id' => ($this->term('reminder-type', $fields['type'] ?? null) ?? throw new InvalidArgumentException(__('choose the kind of reminder')))->id,
            'priority_id' => ($this->term('reminder-priority', $fields['priority'] ?? null) ?? $this->term('reminder-priority', 'normal'))->id,
            'recurrence_id' => $this->term('reminder-recurrence', $fields['recurrence'] ?? null)?->id,
            'status_id' => $this->term('reminder-status', 'active')->id,
            'subject' => $text('subject') ?? throw new InvalidArgumentException(__('give the reminder a subject')),
            'description' => $text('description'), 'action_required' => $text('action_required'),
            'remind_on' => $this->day($text('remind_on')) ?? throw new InvalidArgumentException(__('give the date of the reminder')),
            'advance_days' => (int) $advance, 'recurrence_ends_on' => $this->day($text('recurrence_ends_on')),
            'notify_email' => (bool) ($fields['notify_email'] ?? false), 'created_by_id' => $actor->id,
        ];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($agreement, $values, $recipients) {
            $reminder = AgreementReminder::create($values + ['institution_id' => $agreement->institution_id, 'agreement_id' => $agreement->id]);
            $reminder->recipients()->sync($recipients->pluck('id'));

            return $reminder;
        }));
    }

    /** Heratio's reminder statuses: complete it (with a note), snooze it until a date, cancel it, or make it active again. */
    public function setReminderStatus(AgreementReminder $reminder, string $status, array $fields, User $actor): void
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $values = ['status_id' => ($this->term('reminder-status', $status) ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $status])))->id,
            'snoozed_until' => null, 'completed_at' => null, 'completed_by_id' => null, 'completion_note' => null];
        match ($status) {
            'completed' => $values = [...$values, 'completed_at' => now(), 'completed_by_id' => $actor->id, 'completion_note' => $text('completion_note')],
            'snoozed' => $values['snoozed_until'] = ($day = $this->day($text('snoozed_until'))) !== null && $day > now()->toDateString()
                ? $day : throw new InvalidArgumentException(__('give a later date to snooze it until')),
            default => null,
        };
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $reminder->update($values)));
    }

    /** A document of the agreement: the file is checked and kept, then described. */
    public function addDocument(Agreement $agreement, string $path, string $originalName, array $fields, User $actor): AgreementDocument
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $values = [
            'document_type_id' => ($this->term('agreement-document-type', $fields['document_type'] ?? null) ?? throw new InvalidArgumentException(__('choose the kind of document')))->id,
            'title' => $text('title'), 'description' => $text('description'), 'version' => $text('version'), 'signed_by' => $text('signed_by'),
            'access_restriction' => $text('access_restriction'), 'document_date' => $this->day($text('document_date')), 'signed_on' => $this->day($text('signed_on')),
            'is_signed' => (bool) ($fields['is_signed'] ?? false), 'is_confidential' => (bool) ($fields['is_confidential'] ?? false),
        ];
        // ponytail: an agreement's documents count towards no repository's upload limit; add one when agreements fill disks.
        $file = $this->store->ingest($agreement->institution_id, $path, $text('sha256'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => AgreementDocument::create($values + [
            'institution_id' => $agreement->institution_id, 'agreement_id' => $agreement->id, 'original_name' => mb_substr($originalName, 0, 255),
            'storage_path' => $file['storage_path'], 'mime_type' => $file['mime_type'], 'size_bytes' => $file['size_bytes'], 'sha256' => $file['sha256'],
            'scanned_with' => $file['scanned_with'], 'scanned_at' => $file['scanned_at'], 'uploaded_by_id' => $actor->id])));
    }

    public function removeDocument(Agreement $agreement, string $id, ?User $actor = null): void
    {
        $document = AgreementDocument::where('agreement_id', $agreement->id)->findOrFail($id);
        // The file stays: it is kept by its checksum, and another document or instantiation may hold the same one.
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $document->delete()));
    }

    /** The donors of the agreement: the agents that created it in the donor role. */
    public function donors(Agreement $agreement): Collection
    {
        $ids = Relation::where(['source_table' => 'agreements', 'source_id' => $agreement->id, 'target_table' => 'agents'])
            ->whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/creation'))
            ->whereHas('role', fn ($q) => $q->where('iri', 'https://openric.org/vocab/role-type/donor'))->pluck('target_id');

        return Agent::whereIn('id', $ids)->whereNull('deleted_at')->with('names.nameType', 'descriptionValues.element')->get();
    }

    /** The reminders due on the day: active, or snoozed until then, to be emailed, their warning period begun, not yet sent for their date. */
    public function due(CarbonImmutable $today): Collection
    {
        $day = $today->toDateString();

        return AgreementReminder::where('notify_email', true)
            ->whereHas('status', fn ($q) => $q->whereIn('notation', ['active', 'snoozed']))
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $day))
            ->whereRaw('remind_on - advance_days <= ?::date', [$day])
            ->where(fn ($q) => $q->whereNull('sent_for')->orWhereColumn('sent_for', '<', 'remind_on'))
            ->whereHas('agreement', fn ($q) => $q->whereNull('deleted_at'))
            ->with('agreement', 'recipients', 'createdBy', 'type.labels', 'priority.labels', 'recurrence', 'status')->get();
    }

    /**
     * Send the reminders due today, as Heratio's ahg:donor-reminders: each to its accounts that may read agreements (else
     * to the account that made it), through the Outbox. A reminder that recurs moves on to its next date; one whose
     * recurrence has ended is completed. A reminder no account could be sent stays due, so the next run tries again.
     *
     * @return array{sent: int, unsent: int}
     */
    public function sendDue(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $result = ['sent' => 0, 'unsent' => 0];
        foreach ($this->due($today) as $reminder) {
            $to = $reminder->recipients->isNotEmpty() ? $reminder->recipients : collect([$reminder->createdBy])->filter();
            $sent = $to->filter(fn (User $user) => $user->may('read', 'agreement', $reminder->agreement))
                ->filter(fn (User $user) => $this->outbox->send($user, new AgreementReminderMail($user, $reminder)))->count();
            if ($sent === 0) {
                $result['unsent']++;

                continue;
            }
            $values = ['sent_for' => $reminder->remind_on, 'sent_at' => now(), 'snoozed_until' => null, 'status_id' => $this->term('reminder-status', 'active')->id];
            if ($step = self::RECURRENCES[$reminder->recurrence?->notation] ?? null) {
                $next = $reminder->remind_on->modify($step);
                while ($next <= $today) {
                    $next = $next->modify($step);
                }
                $ended = $reminder->recurrence_ends_on !== null && $next > $reminder->recurrence_ends_on;
                $values = $ended ? [...$values, 'status_id' => $this->term('reminder-status', 'completed')->id, 'completed_at' => now()] : [...$values, 'remind_on' => $next];
            }
            DB::transaction(fn () => AuditLog::actingAs(null, fn () => $reminder->update($values)));
            $result['sent']++;
        }

        return $result;
    }

    private function other(Agreement $agreement, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $id !== $agreement->id || throw new InvalidArgumentException(__('an agreement cannot be part of or supersede itself'));

        return (Agreement::where('institution_id', $agreement->institution_id)->whereNull('deleted_at')->find($id)
            ?? throw new InvalidArgumentException(__('the agreement is one of the institution\'s')))->id;
    }

    private function freeNumber(string $institutionId, string $number, ?Agreement $except = null): string
    {
        $number = trim($number);
        $number !== '' || throw new InvalidArgumentException(__('an agreement has a number'));
        $taken = Identifier::where(['institution_id' => $institutionId, 'subject_table' => 'agreements', 'value' => $number])
            ->whereHas('identifierType', fn ($q) => $q->where('iri', Vocab::IDENTIFIER_TYPES.'local'))
            ->when($except, fn ($q) => $q->where('subject_id', '<>', $except->id))
            ->whereIn('subject_id', Agreement::whereNull('deleted_at')->select('id'))->exists();
        ! $taken || throw new InvalidArgumentException(__('another agreement has the number :number', ['number' => $number]));

        return $number;
    }

    private function term(string $vocabulary, ?string $notation): ?Concept
    {
        if ($notation === null || $notation === '') {
            return null;
        }

        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }

    private function day(?string $day): ?string
    {
        if ($day === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date && $date->format('Y-m-d') === $day ? $day : throw new InvalidArgumentException(__('give the date as YYYY-MM-DD'));
    }

    private function amount(?string $amount): ?string
    {
        return $amount === null ? null : (is_numeric($amount) && $amount >= 0 ? $amount : throw new InvalidArgumentException(__('give the amount as a number')));
    }
}
