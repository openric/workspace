<?php

// OpenRiC Workspace - the intake of accessions (PSIS's ahgAccessionManagePlugin AccessionIntakeService, and Heratio's
// finalisationBlockers and accession settings; roadmap phase 9c, ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The workflow is PSIS's: draft to submitted, submitted to under review or returned, under review to accepted, rejected
// or returned, and returned to submitted again. Accepting is Heratio's finalise: when the institution asks for it, an
// agreement must document the accession first. Every step, assignment, attachment and deaccession is written to the
// accession's timeline, its chain of custody; the audit log keeps every change besides.

namespace App\Description;

use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Models\Accession;
use App\Models\AccessionAttachment;
use App\Models\AccessionChecklistItem;
use App\Models\AccessionTimelineEvent;
use App\Models\ChecklistTemplate;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Relation;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccessionIntakeService
{
    /** PSIS's TRANSITIONS: from => the statuses it may go to. */
    public const TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['under-review', 'returned'],
        'under-review' => ['accepted', 'rejected', 'returned'],
        'returned' => ['submitted'],
        'accepted' => [],
        'rejected' => [],
    ];

    /** The timeline event each status is reached by. */
    private const EVENTS = ['submitted' => 'submitted', 'under-review' => 'reviewed', 'accepted' => 'accepted', 'rejected' => 'rejected', 'returned' => 'returned'];

    /** Heratio's seeded "Standard Intake" template, given to an institution that has none. */
    public const STANDARD = ['name' => 'Standard Intake', 'description' => 'Default checklist for archival accessions', 'items' => ['Donor agreement signed',
        'Preliminary inventory completed', 'Physical condition assessed', 'Restrictions identified', 'Storage location assigned', 'Accession number assigned']];

    public function __construct(private FileStore $store) {}

    /** A new accession's intake columns: draft, the institution's default priority, and assigned to who makes it if the institution asks. */
    public function defaults(Institution $institution, ?User $actor): array
    {
        $institution = $institution->fresh();

        return ['intake_status_id' => $this->term('intake-status', 'draft')->id,
            'intake_priority_id' => $institution->accession_default_priority_id ?? $this->term('intake-priority', 'normal')->id,
            'assigned_to_id' => $institution->accession_auto_assign ? $actor?->id : null];
    }

    /** A new accession's checklist, from the institution's default template, and the first event of its timeline. */
    public function start(Accession $accession, ?User $actor): void
    {
        if ($template = $this->defaultTemplate(Institution::findOrFail($accession->institution_id))) {
            $this->applyTemplate($accession, $template);
        }
        $this->record($accession, 'created', $actor, __('Accession created'));
    }

    /**
     * Move the accession on: submit, review, accept, reject (with a reason) or return for revision (with notes).
     */
    public function transition(Accession $accession, string $to, ?string $text, User $actor): void
    {
        $from = $accession->intakeStatus->notation;
        in_array($to, self::TRANSITIONS[$from] ?? [], true)
            || throw new InvalidArgumentException(__('an accession that is :from cannot be :to', ['from' => mb_strtolower($accession->intakeStatus->label(Vocab::languages())),
                'to' => mb_strtolower($this->term('intake-status', $to)->label(Vocab::languages()))]));
        $text = ($t = trim((string) $text)) === '' ? null : $t;
        if ($to === 'accepted' && ($blockers = $this->blockers($accession)) !== []) {
            throw new InvalidArgumentException(__('it cannot be accepted yet: :blockers', ['blockers' => implode('; ', $blockers)]));
        }
        $to !== 'rejected' || $text !== null || throw new InvalidArgumentException(__('give the reason it is rejected'));
        $values = ['intake_status_id' => $this->term('intake-status', $to)->id] + match ($to) {
            'submitted' => ['submitted_at' => now()],
            'accepted' => ['accepted_at' => now()],
            'rejected' => ['rejected_at' => now(), 'rejection_reason' => $text],
            'returned' => ['intake_notes' => $text],
            default => [],
        };
        $description = match ($to) {
            'submitted' => __('Accession submitted for review'),
            'under-review' => __('Accession under review'),
            'accepted' => __('Accession accepted'),
            'rejected' => __('Accession rejected: :reason', ['reason' => $text]),
            'returned' => __('Returned for revision: :notes', ['notes' => $text ?? '']),
        };
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($accession, $values, $to, $actor, $description) {
            $accession->update($values);
            $this->record($accession, self::EVENTS[$to], $actor, $description);
        }));
    }

    /** What stands in the way of accepting it (Heratio's finalisationBlockers): an agreement documenting it, when the institution asks for one. */
    public function blockers(Accession $accession): array
    {
        $blockers = [];
        if (Institution::findOrFail($accession->institution_id)->accession_requires_agreement && ! Relation::where(['target_table' => 'accessions', 'target_id' => $accession->id, 'source_table' => 'agreements'])
            ->whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/documentation'))
            ->whereIn('source_id', DB::table('agreements')->whereNull('deleted_at')->select('id'))->exists()) {
            $blockers[] = __('no donor agreement documents it');
        }

        return $blockers;
    }

    public function assign(Accession $accession, ?User $assignee, User $actor): void
    {
        $assignee === null || $assignee->institution_id === $accession->institution_id || throw new InvalidArgumentException(__('assign it to an account of the institution'));
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($accession, $assignee, $actor) {
            $accession->update(['assigned_to_id' => $assignee?->id]);
            $this->record($accession, 'assigned', $actor, $assignee ? __('Assigned to :name', ['name' => $assignee->name]) : __('No longer assigned'),
                $assignee ? ['assignee_id' => $assignee->id] : null);
        }));
    }

    /** Its priority in the intake queue. */
    public function prioritise(Accession $accession, string $priority, User $actor): void
    {
        $concept = $this->term('intake-priority', $priority);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $accession->update(['intake_priority_id' => $concept->id])));
    }

    /** A note on the timeline. */
    public function note(Accession $accession, string $text, User $actor): void
    {
        ($text = trim($text)) !== '' || throw new InvalidArgumentException(__('write the note'));
        $this->record($accession, 'note', $actor, $text);
    }

    public function record(Accession $accession, string $event, ?User $actor, ?string $description, ?array $metadata = null): AccessionTimelineEvent
    {
        return AccessionTimelineEvent::create(['institution_id' => $accession->institution_id, 'accession_id' => $accession->id,
            'event_type_id' => $this->term('timeline-event', $event)->id, 'actor_id' => $actor?->id, 'description' => $description, 'metadata' => $metadata, 'occurred_at' => now()]);
    }

    /** Replace the accession's checklist with the template's items (PSIS's applyChecklistTemplate). */
    public function applyTemplate(Accession $accession, ChecklistTemplate $template, ?User $actor = null): int
    {
        $template->institution_id === $accession->institution_id || throw new InvalidArgumentException(__('the template is one of the institution\'s'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($accession, $template) {
            $accession->checklist()->get()->each->delete();
            foreach (array_values($template->items) as $position => $label) {
                AccessionChecklistItem::create(['institution_id' => $accession->institution_id, 'accession_id' => $accession->id, 'label' => $label, 'position' => $position]);
            }

            return count($template->items);
        }));
    }

    public function addItem(Accession $accession, string $label, User $actor): AccessionChecklistItem
    {
        ($label = trim($label)) !== '' || throw new InvalidArgumentException(__('give the item a label'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => AccessionChecklistItem::create(['institution_id' => $accession->institution_id,
            'accession_id' => $accession->id, 'label' => $label, 'position' => (int) $accession->checklist()->max('position') + 1])));
    }

    /** Tick or untick an item (PSIS's toggleChecklistItem). */
    public function toggle(AccessionChecklistItem $item, User $actor): void
    {
        $done = ! $item->is_completed;
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $item->update(['is_completed' => $done, 'completed_by_id' => $done ? $actor->id : null,
            'completed_at' => $done ? now() : null])));
    }

    public function removeItem(AccessionChecklistItem $item, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $item->delete()));
    }

    /** @return array{total: int, completed: int, percent: int} */
    public function progress(Accession $accession): array
    {
        $total = $accession->checklist->count();
        $completed = $accession->checklist->where('is_completed', true)->count();

        return ['total' => $total, 'completed' => $completed, 'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0];
    }

    /** A file attached to the accession: checked and kept by the file store, and noted on the timeline. */
    public function attach(Accession $accession, string $path, string $originalName, string $category, ?string $description, User $actor): AccessionAttachment
    {
        $concept = $this->term('attachment-category', $category);
        // ponytail: attachments count towards no repository's upload limit, as agreement documents; add one when they fill disks.
        $file = $this->store->ingest($accession->institution_id, $path);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($accession, $originalName, $concept, $description, $actor, $file) {
            $attachment = AccessionAttachment::create(['institution_id' => $accession->institution_id, 'accession_id' => $accession->id, 'category_id' => $concept->id,
                'original_name' => mb_substr($originalName, 0, 255), 'storage_path' => $file['storage_path'], 'mime_type' => $file['mime_type'], 'size_bytes' => $file['size_bytes'],
                'sha256' => $file['sha256'], 'scanned_with' => $file['scanned_with'], 'scanned_at' => $file['scanned_at'],
                'description' => ($d = trim((string) $description)) === '' ? null : $d, 'uploaded_by_id' => $actor->id]);
            $this->record($accession, 'note', $actor, __('Attachment added: :name', ['name' => $attachment->original_name]));

            return $attachment;
        }));
    }

    public function detach(AccessionAttachment $attachment, User $actor): void
    {
        // The file stays: it is kept by its checksum, and another attachment, document or instantiation may hold the same one.
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($attachment, $actor) {
            $attachment->delete();
            $this->record($attachment->accession, 'note', $actor, __('Attachment removed: :name', ['name' => $attachment->original_name]));
        }));
    }

    /** The institution's checklist templates; one that has none is given Heratio's standard one. */
    public function templates(Institution $institution)
    {
        if (! ChecklistTemplate::where('institution_id', $institution->id)->exists()) {
            $template = ChecklistTemplate::create(['institution_id' => $institution->id, 'name' => self::STANDARD['name'], 'description' => self::STANDARD['description'], 'items' => self::STANDARD['items']]);
            $institution->accession_checklist_template_id ??= $template->id;
            $institution->save();
        }

        return ChecklistTemplate::where('institution_id', $institution->id)->orderBy('name')->get();
    }

    public function defaultTemplate(Institution $institution): ?ChecklistTemplate
    {
        $this->templates($institution);

        return ChecklistTemplate::find($institution->fresh()->accession_checklist_template_id);
    }

    /** Add or change a template. Fields: name, description, items (one per line). */
    public function saveTemplate(Institution $institution, ?ChecklistTemplate $template, array $fields, User $actor): ChecklistTemplate
    {
        $name = trim((string) ($fields['name'] ?? '')) ?: throw new InvalidArgumentException(__('give the template a name'));
        $items = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($fields['items'] ?? ''))), fn ($l) => $l !== ''));
        $items !== [] || throw new InvalidArgumentException(__('give the template at least one item'));
        $values = ['name' => $name, 'description' => ($d = trim((string) ($fields['description'] ?? ''))) === '' ? null : $d, 'items' => $items];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $template ? tap($template)->update($values) : ChecklistTemplate::create($values + ['institution_id' => $institution->id])));
    }

    /**
     * PSIS's queue statistics: by intake status and priority, the average hours from submitted to accepted, and how many
     * submitted or under review have waited more than seven days.
     */
    public function stats(string $institutionId): array
    {
        $base = fn () => DB::table('accessions as a')->where('a.institution_id', $institutionId)->whereNull('a.deleted_at');
        $count = fn (string $column) => $base()->join('concepts as c', 'c.id', '=', "a.$column")->selectRaw('c.notation, count(*) as n')->groupBy('c.notation')->pluck('n', 'notation')->all();
        $hours = $base()->whereNotNull('a.submitted_at')->whereNotNull('a.accepted_at')->selectRaw('avg(extract(epoch from a.accepted_at - a.submitted_at) / 3600) as h')->value('h');

        return ['status' => $count('intake_status_id'), 'priority' => $count('intake_priority_id'), 'hours_to_accept' => $hours === null ? null : round((float) $hours, 1),
            'overdue' => $base()->whereIn('a.intake_status_id', Concept::choices(Vocab::BASE.'intake-status/')->whereIn('notation', ['submitted', 'under-review'])->select('id'))
                ->where('a.submitted_at', '<', now()->subDays(7))->count(),
            'recent' => $base()->where('a.created_at', '>=', now()->subDays(30))->count(), 'total' => $base()->count()];
    }

    private function term(string $vocabulary, string $notation): Concept
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
