<?php

// OpenRiC Workspace - researchers registered with the institution (roadmap phase 19b, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchService (registerResearcher, updateResearcher, approveResearcher), its
// ResearchRegistrationController (register, re-register after a rejection, renewal) and ResearchAdminController
// (approve, verify, reject, suspend): an account registers as a researcher with its affiliation, research and
// identity; staff who may manage the reading room approve it (for the researcher type's months, else a year, with
// a reader's card number), reject it with a reason (the researcher may register again), suspend it, record the
// checks of identity and affiliation, and renew it; a daily run warns before a registration ends and ends it.
// A researcher type that approves by itself does so. The phone and identity number are encrypted, as Heratio
// encrypts them. Changed: Heratio files an access request for the approval; here the staff list holds the
// registrations waiting, and the researcher is told of each decision through the Outbox.

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Concept;
use App\Models\Researcher;
use App\Models\ResearcherType;
use App\Models\ResearcherVerification;
use App\Models\User;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Researchers
{
    public const STATUSES = Vocab::BASE.'researcher-status/';

    public const REMIND_DAYS = 30;

    /** The researcher's own fields, which they keep up; status and checks are staff's. */
    private const OWN = ['title', 'first_name', 'last_name', 'phone', 'institution', 'department', 'position', 'student_id', 'research_interests', 'current_project',
        'orcid_id', 'researcher_id_wos', 'scopus_id', 'isni', 'id_number'];

    public function __construct(private Outbox $outbox, private ResearchFiles $files) {}

    public function of(User $user): ?Researcher
    {
        return Researcher::where('user_id', $user->id)->with('status', 'type', 'affiliation')->first();
    }

    /** May the account use the reading room now: approved, and not past its date. */
    public function active(?Researcher $researcher): bool
    {
        return $researcher !== null && $researcher->status->notation === 'approved' && ($researcher->expires_on === null || ! $researcher->expires_on->isPast());
    }

    /** Register, or register again after a rejection (Heratio's register). */
    public function register(User $user, array $data, ?UploadedFile $photo = null): Researcher
    {
        $existing = $this->of($user);
        if ($existing && $existing->status->notation !== 'rejected') {
            throw new InvalidArgumentException(__('You are registered already.'));
        }
        $type = ($data['researcher_type_id'] ?? '') !== '' ? ResearcherType::where(['id' => $data['researcher_type_id'], 'institution_id' => $user->institution_id, 'is_active' => true])->first()
            ?? throw new InvalidArgumentException(__('Choose a kind of researcher from the list.')) : null;
        $fields = $this->fields($data, $type) + ['researcher_type_id' => $type?->id, 'status_id' => $this->status('pending'), 'rejection_reason' => null];

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $existing, $fields, $type, $photo) {
            if ($photo) {
                $fields['photo_file_id'] = $this->files->keep($photo, $user->institution_id, $user)->id;
            }
            $researcher = $existing ? tap($existing)->update($fields) : Researcher::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id]);
            if ($type?->auto_approve) {
                $this->approve($researcher, $user, null, true);
            }

            return $researcher->fresh();
        }));
    }

    /** The researcher keeps their own details up (Heratio's profile edit). */
    public function update(User $user, Researcher $researcher, array $data, ?UploadedFile $photo = null): void
    {
        if ($researcher->user_id !== $user->id && ! $this->manages($user, $researcher)) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        $fields = array_intersect_key($this->fields($data, $researcher->type), array_flip([...self::OWN, 'affiliation_id', 'id_type_id']));
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($researcher, $fields, $photo, $user) {
            if ($photo) {
                $fields['photo_file_id'] = $this->files->keep($photo, $researcher->institution_id, $user)->id;
            }
            $researcher->update($fields);
        }));
    }

    /** Approve until a date: the type's months, else a year (Heratio's approveResearcher). Gives a reader's card number. */
    public function approve(Researcher $researcher, User $actor, ?string $expiresOn = null, bool $auto = false): void
    {
        if (! $auto) {
            $this->mustManage($actor, $researcher);
            if ($researcher->type?->requires_id_verification && ! $researcher->id_verified) {
                throw new InvalidArgumentException(__('Verify the researcher\'s identity first: their kind of researcher requires it.'));
            }
        }
        $until = $expiresOn ? CarbonImmutable::parse($expiresOn) : CarbonImmutable::now(config('workspace.timezone'))->addMonths($researcher->type?->expiry_months ?? 12);
        if ($until->isPast()) {
            throw new InvalidArgumentException(__('The registration must end after today.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $researcher->update(['status_id' => $this->status('approved'), 'approved_by_id' => $actor->id, 'approved_at' => now(),
            'expires_on' => $until->toDateString(), 'rejection_reason' => null, 'renewal_reminder_sent_at' => null,
            'card_number' => $researcher->card_number ?? $this->cardNumber($researcher), 'card_issued_at' => $researcher->card_issued_at ?? now()])));
        $this->tell($researcher, 'Your registration as a researcher is approved', [['You may book the reading room until :date. Your reader\'s card number is :card.',
            ['date' => $until->format('j F Y'), 'card' => $researcher->fresh()->card_number]]]);
    }

    public function reject(Researcher $researcher, User $actor, string $reason): void
    {
        $this->decide($researcher, $actor, 'rejected', $reason, 'Your registration as a researcher was not approved', 'You may correct it and register again.');
    }

    public function suspend(Researcher $researcher, User $actor, string $reason): void
    {
        $this->decide($researcher, $actor, 'suspended', $reason, 'Your registration as a researcher is suspended', 'Ask the reading room if you have questions.');
    }

    /** Ask to renew an expired registration (Heratio's renewal): back to pending, for staff to approve. */
    public function renew(User $user, Researcher $researcher): void
    {
        if ($researcher->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if (! in_array($researcher->status->notation, ['expired', 'approved'], true)) {
            throw new InvalidArgumentException(__('Renewal is not available for your current status.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $researcher->update(['status_id' => $this->status('pending'), 'rejection_reason' => null])));
    }

    /** Record a check of identity or affiliation; a verified identity document marks the identity verified. */
    public function verify(Researcher $researcher, User $actor, array $data, ?UploadedFile $document = null): ResearcherVerification
    {
        $this->mustManage($actor, $researcher);
        $type = Concept::where('iri', Vocab::BASE.'verification-type/'.($data['type'] ?? ''))->value('id') ?? throw new InvalidArgumentException(__('Choose how it was verified.'));
        $status = $data['status'] ?? 'verified';
        $statusId = Concept::where('iri', Vocab::BASE.'verification-status/'.$status)->value('id') ?? throw new InvalidArgumentException(__('Choose a status from the list.'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($researcher, $actor, $data, $document, $type, $statusId, $status) {
            $verification = ResearcherVerification::create(['researcher_id' => $researcher->id, 'type_id' => $type, 'status_id' => $statusId,
                'document_type' => trim((string) ($data['document_type'] ?? '')) ?: null, 'document_reference' => trim((string) ($data['document_reference'] ?? '')) ?: null,
                'document_file_id' => $document ? $this->files->keep($document, $researcher->institution_id, $actor)->id : null,
                'verified_by_id' => $actor->id, 'verified_at' => now(), 'expires_on' => ($data['expires_on'] ?? '') ?: null,
                'rejection_reason' => $status === 'rejected' ? (trim((string) ($data['rejection_reason'] ?? '')) ?: null) : null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null]);
            if ($status === 'verified' && ($data['type'] ?? '') === 'id-document') {
                $researcher->update(['id_verified' => true, 'id_verified_by_id' => $actor->id, 'id_verified_at' => now()]);
            }

            return $verification;
        }));
    }

    /** The daily run: warn a month before a registration ends, and end it when it has. @return array{warned: int, expired: int} */
    public function process(): array
    {
        $today = CarbonImmutable::now(config('workspace.timezone'))->toDateString();
        $approved = Researcher::whereHas('status', fn ($q) => $q->where('iri', self::STATUSES.'approved'))->with('user', 'status');
        $warned = 0;
        foreach ((clone $approved)->whereNull('renewal_reminder_sent_at')->whereBetween('expires_on', [$today, CarbonImmutable::parse($today)->addDays(self::REMIND_DAYS)->toDateString()])->get() as $r) {
            $r->update(['renewal_reminder_sent_at' => now()]);
            $this->tell($r, 'Your registration as a researcher ends on :date', [['Ask to renew it from your researcher page before then, to keep booking the reading room.', []]], ['date' => $r->expires_on->format('j F Y')]);
            $warned++;
        }
        $expired = 0;
        foreach ((clone $approved)->where('expires_on', '<', $today)->get() as $r) {
            $r->update(['status_id' => $this->status('expired')]);
            $expired++;
        }

        return ['warned' => $warned, 'expired' => $expired];
    }

    public function manages(User $user, Researcher $researcher): bool
    {
        return $user->institution_id === $researcher->institution_id && $user->hasPermission(Vocab::permission('manage-reading-room'));
    }

    public function status(string $notation): string
    {
        return Concept::where('iri', self::STATUSES.$notation)->value('id');
    }

    private function decide(Researcher $researcher, User $actor, string $status, string $reason, string $subject, string $then): void
    {
        $this->mustManage($actor, $researcher);
        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('Give the reason.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $researcher->update(['status_id' => $this->status($status), 'rejection_reason' => trim($reason)])));
        $this->tell($researcher, $subject, [['The reason: :reason', ['reason' => trim($reason)]], [$then, []]]);
    }

    private function mustManage(User $actor, Researcher $researcher): void
    {
        if (! $this->manages($actor, $researcher)) {
            throw new InvalidArgumentException(__('Only who may manage the reading room may do that.'));
        }
    }

    /** The fields a registration gives, checked. */
    private function fields(array $data, ?ResearcherType $type): array
    {
        $text = fn (string $key) => ($v = trim((string) ($data[$key] ?? ''))) === '' ? null : $v;
        if (! $text('first_name') || ! $text('last_name')) {
            throw new InvalidArgumentException(__('Give your first name and surname.'));
        }
        $affiliation = Concept::where('iri', Vocab::BASE.'researcher-affiliation/'.($data['affiliation'] ?? 'independent'))->value('id') ?? throw new InvalidArgumentException(__('Choose your affiliation from the list.'));
        $idType = $text('id_type') ? (Concept::where('iri', Vocab::BASE.'identity-document-type/'.$data['id_type'])->value('id') ?? throw new InvalidArgumentException(__('Choose an identity document from the list.'))) : null;
        if (($type?->requires_id_verification ?? false) && (! $idType || ! $text('id_number'))) {
            throw new InvalidArgumentException(__('Give your identity document and its number.'));
        }
        if ($text('orcid_id') && ! preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', (string) $text('orcid_id'))) {
            throw new InvalidArgumentException(__('An ORCID iD looks like 0000-0002-1825-0097.'));
        }

        return array_combine(self::OWN, array_map($text, self::OWN)) + ['affiliation_id' => $affiliation, 'id_type_id' => $idType];
    }

    private function cardNumber(Researcher $researcher): string
    {
        $year = now(config('workspace.timezone'))->format('Y');
        $n = Researcher::where('institution_id', $researcher->institution_id)->where('card_number', 'like', "R-$year-%")->count() + 1;

        return sprintf('R-%s-%05d', $year, $n);
    }

    /** @param  list<array{0: string, 1: array}>  $lines */
    private function tell(Researcher $researcher, string $subject, array $lines, array $replace = []): void
    {
        $researcher->loadMissing('user');
        if ($researcher->user && $researcher->user->disabled_at === null) {
            $this->outbox->send($researcher->user, new ResearchMail($researcher->user, $subject, $replace, $lines, route('research.profile'), 'Open your researcher page'));
        }
    }
}
