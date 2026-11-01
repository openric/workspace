<?php

// OpenRiC Workspace - researcher submissions (roadmap phase 19e, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's ahgResearcherPlugin (SubmissionService, PublishService, ExchangeImportService), which Heratio's
// ahg-researcher-manage copies only in part: a researcher drafts descriptions for the catalogue - with their levels,
// dates, extent, scope and content, access points, conditions, notes and files, one inside another - and notes on
// records already in it, new repositories and new creators; from scratch, from one of their collections, or from an
// exchange file made offline. They send it for review; a reviewer takes it on, comments, returns it to be changed,
// rejects it, or approves it; an approved submission is published.
//
// Changed: PSIS writes AtoM's tables itself; here publishing writes the descriptions as AtoM CSV and runs them through
// the Workspace's import, as the reviewer, so they are matched, checked, made and reported as any import is, with a dry
// run first to see what will happen; the access points, creators and dates go in as the import puts them in. Then the
// files become the new records' instantiations, and each note is added to its record's notes. A submission made from a
// collection gives a note on each of its records (PSIS copies them as new descriptions). New repositories and
// creators are made first, so the descriptions find them by name. A review is the submission's own steps, as in PSIS;
// the Workspace's workflows move catalogue entities, and the records made then follow its publication rules.

namespace App\Research;

use App\Audit\AuditLog;
use App\Description\AgentService;
use App\Description\ContactPointService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Exchange\DescriptionCsv;
use App\Exchange\ExportView;
use App\Exchange\Import\ImportService;
use App\Files\FileStore;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Concept;
use App\Models\Import;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\ResearchCollection;
use App\Models\ResearcherSubmission;
use App\Models\ResearcherSubmissionFile;
use App\Models\ResearcherSubmissionItem;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Submissions
{
    public const STATUSES = Vocab::BASE.'submission-status/';

    public const TYPES = Vocab::BASE.'submission-item-type/';

    public const ACTIONS = Vocab::BASE.'submission-review-action/';

    private const LEVELS = Vocab::BASE.'level-of-description/';

    /** The fields of an item a researcher writes. */
    public const FIELDS = ['title', 'identifier', 'scope_and_content', 'extent_and_medium', 'date_display', 'date_start', 'date_end', 'creators', 'subjects', 'places', 'genres',
        'access_conditions', 'reproduction_conditions', 'notes', 'repository_name', 'repository_address', 'repository_contact'];

    /** Where the researcher may still change it. */
    private const OPEN = ['draft', 'returned'];

    public function __construct(private Researchers $researchers, private ResearchFiles $files, private Outbox $outbox) {}

    // The researcher's side.

    public function create(User $user, array $data, string $source = 'online'): ResearcherSubmission
    {
        $fields = $this->fields($user, $data);

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => ResearcherSubmission::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id,
            'researcher_id' => $this->researchers->of($user)?->id, 'source_type_id' => $this->concept(Vocab::BASE.'submission-source/', $source),
            'status_id' => $this->concept(self::STATUSES, 'draft')])));
    }

    public function update(User $user, ResearcherSubmission $submission, array $data): void
    {
        $this->mustChange($user, $submission);
        $fields = $this->fields($user, $data);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $submission->update($fields)));
    }

    public function delete(User $user, ResearcherSubmission $submission): void
    {
        $this->mustOwn($user, $submission);
        if (in_array($submission->status->notation, ['approved', 'published'], true)) {
            throw new InvalidArgumentException(__('An approved or published submission is kept.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $submission->delete()));
    }

    /** Add or change an item: a description (inside another of the submission, or not), a note on a record, a repository or a creator. */
    public function saveItem(User $user, ResearcherSubmission $submission, array $data, ?ResearcherSubmissionItem $item = null): ResearcherSubmissionItem
    {
        $this->mustChange($user, $submission);
        if ($item && $item->submission_id !== $submission->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        $type = $item?->itemType->notation ?? (string) ($data['item_type'] ?? 'description');
        $fields = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, array_intersect_key($data, array_flip(self::FIELDS)));
        if (($fields['title'] ?? null) === null && $type !== 'note') {
            throw new InvalidArgumentException(__('Give the item a title.'));
        }
        foreach (['creators', 'subjects', 'places', 'genres'] as $list) {
            if (isset($fields[$list])) {
                $fields[$list] = Diary::tags($fields[$list]);
            }
        }
        if (($fields['date_start'] ?? null) && ($fields['date_end'] ?? null) && $fields['date_end'] < $fields['date_start']) {
            throw new InvalidArgumentException(__('The end date comes before the start.'));
        }
        if ($type === 'description') {
            $level = ($data['level'] ?? '') !== '' ? $this->levels($submission->institution_id)->first(fn ($c) => $c->iri === $data['level'] || $c->notation === $data['level'])
                ?? throw new InvalidArgumentException(__('Choose a level of description from the list.')) : null;
            $fields['level_id'] = $level?->id;
            $parent = ($data['parent_item_id'] ?? '') !== '' ? $submission->items()->where('id', $data['parent_item_id'])
                ->whereHas('itemType', fn ($t) => $t->where('iri', self::TYPES.'description'))->first() ?? throw new InvalidArgumentException(__('Choose a description of this submission.')) : null;
            if ($parent && $item && ($parent->id === $item->id || in_array($item->id, $this->ancestors($parent), true))) {
                throw new InvalidArgumentException(__('A description cannot be put inside itself.'));
            }
            $fields['parent_item_id'] = $parent?->id;
        }
        if ($type === 'note') {
            $record = $this->record($user, (string) ($data['reference_iri'] ?? $item?->referenceRecord?->iri ?? ''));
            $fields['reference_record_id'] = $record->id;
            $fields['title'] ??= EntityKinds::label($record);
        }
        if (! $item && ! in_array($type, ['description', 'note', 'repository', 'creator'], true)) {
            throw new InvalidArgumentException(__('Choose a kind from the list.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($submission, $item, $type, $fields) {
            if ($item) {
                $item->update($fields);
            } else {
                $item = $submission->items()->create($fields + ['item_type_id' => $this->concept(self::TYPES, $type), 'sort_order' => (int) $submission->items()->max('sort_order') + 1]);
            }
            $this->totals($submission);

            return $item;
        }));
    }

    public function removeItem(User $user, ResearcherSubmissionItem $item): void
    {
        $this->mustChange($user, $item->submission);
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($item) {
            $item->delete();
            $this->totals($item->submission);
        }));
    }

    /** A file of a description, kept as the other research files are. */
    public function addFile(User $user, ResearcherSubmissionItem $item, UploadedFile $upload, ?string $caption = null): ResearcherSubmissionFile
    {
        $this->mustChange($user, $item->submission);
        if ($item->itemType->notation !== 'description') {
            throw new InvalidArgumentException(__('Files belong to descriptions.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $item, $upload, $caption) {
            $file = $item->files()->create(['file_id' => $this->files->keep($upload, $item->submission->institution_id, $user)->id, 'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'caption' => trim((string) $caption) ?: null, 'sort_order' => (int) $item->files()->max('sort_order') + 1, 'created_at' => now()]);
            $this->totals($item->submission);

            return $file;
        }));
    }

    public function removeFile(User $user, ResearcherSubmissionFile $file): void
    {
        $this->mustChange($user, $file->item->submission);
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($file) {
            $file->delete();
            $this->totals($file->item->submission);
        }));
    }

    /** A note on each record of one's collection, with the item's notes (PSIS's createFromCollection). */
    public function fromCollection(User $user, ResearchCollection $collection): ResearcherSubmission
    {
        app(Collections::class)->mustOwn($user, $collection);

        return DB::transaction(function () use ($user, $collection) {
            $submission = $this->create($user, ['title' => $collection->name, 'description' => $collection->description]);
            foreach (app(Collections::class)->items($user, $collection)->filter(fn ($i) => $i['readable'] && $i['entity'] instanceof RecordResource) as $i) {
                $this->saveItem($user, $submission, ['item_type' => 'note', 'reference_iri' => $i['entity']->iri, 'title' => $i['title'], 'scope_and_content' => $i['item']->notes]);
            }

            return $submission;
        });
    }

    /**
     * An exchange file made offline (PSIS's researcher-exchange.json, format 1.0): notes on records, files described,
     * new descriptions one inside another, new creators and new repositories. A note names its record by its
     * persistent IRI (reference_iri); one that names none is kept for the researcher to point.
     *
     * @return array{submission: ResearcherSubmission, counts: array<string, int>}
     */
    public function importExchange(User $user, UploadedFile $upload, array $data = []): array
    {
        $exchange = json_decode((string) file_get_contents($upload->getRealPath()), true);
        if (! is_array($exchange) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(__('The file is not JSON.'));
        }
        if (($exchange['format_version'] ?? null) !== '1.0') {
            throw new InvalidArgumentException(__('The exchange file is not of format 1.0.'));
        }
        if (! is_array($exchange['collections'] ?? null) || $exchange['collections'] === []) {
            throw new InvalidArgumentException(__('The exchange file holds nothing.'));
        }
        $images = (bool) ($exchange['export_options']['include_images'] ?? true);
        $counts = ['notes' => 0, 'descriptions' => 0, 'creators' => 0, 'repositories' => 0, 'files' => 0];

        $submission = DB::transaction(function () use ($user, $upload, $data, $exchange, $images, &$counts) {
            return AuditLog::actingAs($user, function () use ($user, $upload, $data, $exchange, $images, &$counts) {
                $submission = $this->create($user, ['title' => mb_substr(__('Import: :title', ['title' => $exchange['source_config']['title'] ?? __('Portable export')]), 0, 255),
                    'description' => __('Imported from :file on :date', ['file' => $upload->getClientOriginalName(), 'date' => now(config('workspace.timezone'))->format('j F Y H:i')]),
                    'repository_id' => $data['repository_id'] ?? null], 'offline');
                $submission->update(['source_file_id' => $this->files->keep($upload, $user->institution_id, $user)->id, 'include_images' => $images]);
                foreach ($exchange['collections'] as $collection) {
                    $map = [];
                    foreach ((array) ($collection['items'] ?? []) as $entry) {
                        $entry = (array) $entry;
                        $list = fn ($v) => is_array($v) ? implode(', ', array_filter(array_map('strval', $v))) : (is_string($v) ? $v : null);
                        $fields = match ($collection['type'] ?? null) {
                            'notes' => ['item_type' => 'note', 'title' => $entry['title'] ?? null, 'scope_and_content' => $entry['note'] ?? null, 'iri' => $entry['reference_iri'] ?? null],
                            'files', 'new_items' => ['item_type' => 'description', 'title' => $entry['title'] ?? __('Untitled'), 'identifier' => $entry['identifier'] ?? null,
                                'level' => $entry['level_of_description'] ?? 'item', 'scope_and_content' => $entry['scope_and_content'] ?? null, 'extent_and_medium' => $entry['extent_and_medium'] ?? null,
                                'date_display' => $entry['date_display'] ?? null, 'date_start' => $this->day($entry['date_start'] ?? null), 'date_end' => $this->day($entry['date_end'] ?? null),
                                'creators' => $list($entry['creators'] ?? null), 'subjects' => $list($entry['subjects'] ?? null), 'places' => $list($entry['places'] ?? null),
                                'genres' => $list($entry['genres'] ?? null), 'access_conditions' => $entry['access_conditions'] ?? null, 'reproduction_conditions' => $entry['reproduction_conditions'] ?? null,
                                'notes' => $entry['notes'] ?? null, 'parent_item_id' => isset($entry['parent_id']) ? ($map[$entry['parent_id']] ?? null) : null],
                            'new_creators' => ['item_type' => 'creator', 'title' => $entry['name'] ?? $entry['title'] ?? __('New creator'), 'scope_and_content' => $entry['history'] ?? $entry['biography'] ?? null,
                                'date_display' => $entry['dates_of_existence'] ?? null, 'notes' => $entry['notes'] ?? null],
                            'new_repositories' => ['item_type' => 'repository', 'title' => $entry['name'] ?? $entry['title'] ?? __('New repository'), 'scope_and_content' => $entry['description'] ?? null,
                                'repository_name' => $entry['name'] ?? $entry['title'] ?? null, 'repository_address' => $entry['address'] ?? null, 'repository_contact' => $entry['contact'] ?? null, 'notes' => $entry['notes'] ?? null],
                            default => null,
                        };
                        if ($fields === null) {
                            continue;
                        }
                        $item = $this->importItem($submission, $user, $fields);
                        if (isset($entry['id'])) {
                            $map[$entry['id']] = $item->id;
                        }
                        $counts[['note' => 'notes', 'description' => 'descriptions', 'creator' => 'creators', 'repository' => 'repositories'][$fields['item_type']]]++;
                        foreach ($images && $fields['item_type'] === 'description' ? (array) ($entry['files'] ?? []) : [] as $file) {
                            $counts['files'] += $this->importFile($user, $item, (array) $file) ? 1 : 0;
                        }
                    }
                }
                $this->totals($submission);

                return $submission;
            });
        });

        return ['submission' => $submission, 'counts' => $counts];
    }

    /** Send it for review (PSIS's submitForReview and resubmit): it needs a description, and each note its record and text. */
    public function submit(User $user, ResearcherSubmission $submission): void
    {
        $this->mustChange($user, $submission);
        $items = $submission->items()->with('itemType')->get();
        if (! $items->contains(fn ($i) => $i->itemType->notation === 'description') && ! $items->contains(fn ($i) => $i->itemType->notation === 'note')) {
            throw new InvalidArgumentException(__('Add a description or a note before sending it.'));
        }
        if ($items->contains(fn ($i) => $i->itemType->notation === 'note' && ($i->reference_record_id === null || trim((string) $i->scope_and_content) === ''))) {
            throw new InvalidArgumentException(__('Each note needs its record and its text.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $submission) {
            $again = $submission->status->notation === 'returned';
            $submission->update(['status_id' => $this->concept(self::STATUSES, 'submitted'), 'submitted_at' => now(), 'return_comment' => null]);
            $this->log($submission, $user, 'comment', $again ? __('Sent again for review.') : __('Submitted for review.'));
        }));
    }

    // The reviewer's side.

    /** Take it on, comment, return, approve or reject. A return or rejection says why. */
    public function review(User $reviewer, ResearcherSubmission $submission, string $action, ?string $comment = null): void
    {
        $this->mustReview($reviewer, $submission);
        $comment = trim((string) $comment) ?: null;
        $status = $submission->status->notation;
        $to = match ($action) {
            'take' => in_array($status, ['submitted'], true) ? 'under-review' : null,
            'comment' => $comment !== null ? $status : null,
            'return', 'reject' => in_array($status, ['submitted', 'under-review', 'approved'], true) && $comment !== null ? ($action === 'return' ? 'returned' : 'rejected') : null,
            'approve' => in_array($status, ['submitted', 'under-review'], true) ? 'approved' : null,
            default => throw new InvalidArgumentException(__('Choose a kind from the list.')),
        } ?? throw new InvalidArgumentException(in_array($action, ['comment', 'return', 'reject'], true) && $comment === null ? __('Say why.') : __('It cannot be done now.'));

        DB::transaction(fn () => AuditLog::actingAs($reviewer, function () use ($reviewer, $submission, $action, $comment, $to) {
            $submission->update(['status_id' => $this->concept(self::STATUSES, $to), 'reviewer_id' => $reviewer->id]
                + ($action === 'return' ? ['return_comment' => $comment] : []) + ($action === 'reject' ? ['reject_comment' => $comment] : []));
            $this->log($submission, $reviewer, $action === 'take' ? 'comment' : $action, $action === 'take' ? __('Taken on for review.') : $comment);
        }));
        if (in_array($action, ['return', 'reject', 'approve', 'comment'], true) && $submission->user) {
            $this->outbox->send($submission->user, new ResearchMail($submission->user, 'Your submission :title', ['title' => $submission->title],
                [[match ($action) {
                    'return' => 'Your submission ":title" is returned to you to change.', 'reject' => 'Your submission ":title" is not to be published.',
                    'approve' => 'Your submission ":title" is approved and will be published.', default => 'A reviewer wrote about your submission ":title".'
                }, ['title' => $submission->title]],
                    ...($comment ? [[':comment', ['comment' => $comment]]] : [])], route('research.submissions.show', $submission), 'Open the submission'));
        }
    }

    /**
     * Publish what is approved (PSIS's publish): new repositories and creators first, then the descriptions through
     * the import, then their files and the notes. With $dryRun nothing is kept, and the report says what would happen.
     *
     * @param  array{repository_id?: ?string, language?: ?string, publish?: bool, create_terms?: bool}  $options
     */
    public function publish(User $reviewer, ResearcherSubmission $submission, array $options, bool $dryRun = false): Import
    {
        $this->mustReview($reviewer, $submission);
        if ($submission->status->notation !== 'approved') {
            throw new InvalidArgumentException(__('Only an approved submission is published.'));
        }
        if (! $reviewer->may('create', 'description')) {
            throw new InvalidArgumentException(__('You may not create descriptions.'));
        }
        $institution = Institution::findOrFail($submission->institution_id);
        $repository = ($options['repository_id'] ?? null) ? Repository::where(['institution_id' => $institution->id, 'id' => $options['repository_id']])->first()
            : ($submission->repository ?? $submission->parentRecord?->repository);
        $repository ?? throw new InvalidArgumentException(__('Choose the repository its descriptions belong to.'));
        $language = (string) ($options['language'] ?? '') ?: 'en';
        $items = $submission->items()->with('itemType', 'level', 'files.file', 'referenceRecord')->get();
        $of = fn (string $type) => $items->filter(fn ($i) => $i->itemType->notation === $type);

        $made = ['repositories' => 0, 'creators' => 0, 'notes' => 0, 'files' => 0];
        if (! $dryRun) {
            DB::transaction(function () use ($reviewer, $institution, $language, $of, &$made) {
                AuditLog::actingAs($reviewer, function () use ($reviewer, $institution, $language, $of, &$made) {
                    foreach ($of('repository')->whereNull('published_id') as $item) {
                        $made['repositories']++;
                        $new = app(RepositoryService::class)->create($institution, (string) ($item->repository_name ?? $item->title), $language, null, $reviewer);
                        if ($item->scope_and_content) {
                            app(RepositoryService::class)->describe($new, ['holdings' => $item->scope_and_content], $language, $reviewer);
                        }
                        if ($item->repository_address || $item->repository_contact) {
                            app(ContactPointService::class)->save($new, ['street_address' => $item->repository_address, 'contact_person' => $item->repository_contact, 'is_primary' => true], null, $reviewer);
                        }
                        $item->update(['published_table' => $new->getTable(), 'published_id' => $new->id]);
                    }
                    foreach ($of('creator')->whereNull('published_id') as $item) {
                        $made['creators']++;
                        $agent = app(AgentService::class)->create($institution, 'person', $item->title, $language, null, $reviewer);
                        $values = array_filter(['agent-history' => $item->scope_and_content, 'agent-dates-of-existence' => $item->date_display]);
                        if ($values !== []) {
                            app(AgentService::class)->describe($agent, $values, $language, $reviewer);
                        }
                        $item->update(['published_table' => $agent->getTable(), 'published_id' => $agent->id]);
                    }
                });
            });
        }

        $descriptions = $of('description')->whereNull('published_id');
        $csv = $this->csv($descriptions, ! empty($options['publish']), $language);
        $import = Import::create(['institution_id' => $institution->id, 'user_id' => $reviewer->id, 'format' => 'csv', 'source' => __('Researcher submission: :title', ['title' => $submission->title]),
            'file_name' => 'submission-'.substr($submission->id, 0, 8).'.csv', 'file_sha256' => hash('sha256', $csv), 'content' => $csv, 'language' => $language,
            'repository_id' => $repository->id, 'parent_id' => $submission->parent_record_id,
            'options' => ['agent_type' => 'person', 'create_terms' => (bool) ($options['create_terms'] ?? true), 'match_identifier' => false, 'default_level' => 'item']]);
        $report = $descriptions->isEmpty() ? null : app(ImportService::class)->run($import, ! $dryRun);
        if ($dryRun) {
            $submission->update(['import_id' => $import->id]);

            return $import;
        }

        $units = collect($report?->toArray()['units'] ?? [])->filter(fn ($u) => in_array($u['action'], ['created', 'updated'], true) && $u['unit_id'])->keyBy('key');
        DB::transaction(function () use ($reviewer, $submission, $import, $descriptions, $units, $of, $language, &$made) {
            AuditLog::actingAs($reviewer, function () use ($reviewer, $submission, $import, $descriptions, $units, $of, $language, &$made) {
                foreach ($descriptions as $item) {
                    $unit = isset($units[$item->id]) ? RecordResource::find($units[$item->id]['unit_id']) : null;
                    if ($unit === null) {
                        continue;
                    }
                    $item->update(['published_table' => 'record_resources', 'published_id' => $unit->id]);
                    foreach ($item->files->whereNotNull('file_id') as $file) {
                        $made['files']++;
                        $copy = app(InstantiationService::class)->upload($unit, app(FileStore::class)->path($file->file->storage_path), $file->original_name, $language,
                            $file->caption ?? $file->original_name, $file->file->sha256, 'online', $reviewer);
                        $file->update(['published_instantiation_id' => $copy->id]);
                    }
                }
                foreach ($of('note')->whereNull('published_id') as $item) {
                    $record = $item->referenceRecord;
                    if ($record === null || ! $reviewer->may('update', 'description', $record)) {
                        continue;
                    }
                    $made['notes']++;
                    $before = (new ExportView($reviewer, true, null, Institution::find($record->institution_id)))->valueIn($record, 'note', $language);
                    app(RecordService::class)->describe($record, ['note' => trim(($before ? $before."\n\n" : '').$item->scope_and_content)], $language, $reviewer);
                    $item->update(['published_table' => 'record_resources', 'published_id' => $record->id]);
                }
                $published = $submission->items()->whereNotNull('published_id')->exists();
                $submission->update(['import_id' => $import->id] + ($published ? ['status_id' => $this->concept(self::STATUSES, 'published'), 'published_at' => now()] : []));
                $this->log($submission, $reviewer, 'publish', __('Published :records descriptions, :notes notes, :creators creators, :repositories repositories and :files files.',
                    ['records' => $units->count(), 'notes' => $made['notes'], 'creators' => $made['creators'], 'repositories' => $made['repositories'], 'files' => $made['files']]));
            });
        });
        if ($submission->fresh()->status->notation === 'published' && $submission->user) {
            $this->outbox->send($submission->user, new ResearchMail($submission->user, 'Your submission :title', ['title' => $submission->title],
                [['Your submission ":title" is published in the catalogue.', ['title' => $submission->title]]], route('research.submissions.show', $submission), 'Open the submission'));
        }

        return $import;
    }

    /** The descriptions as AtoM CSV, for the import: each item's id its legacyId, so the report says what each became. */
    public function csv(Collection $descriptions, bool $publish, string $language): string
    {
        $out = fopen('php://temp', 'r+');
        $columns = ['legacyId', 'parentId', 'identifier', 'title', 'levelOfDescription', 'extentAndMedium', 'scopeAndContent', 'accessConditions', 'reproductionConditions', 'generalNote',
            'subjectAccessPoints', 'placeAccessPoints', 'genreAccessPoints', 'eventActors', 'eventTypes', 'eventDates', 'eventStartDates', 'eventEndDates', 'publicationStatus', 'culture'];
        array_diff($columns, array_keys(DescriptionCsv::COLUMNS)) === [] || throw new \LogicException('a column the import does not read');
        fputcsv($out, $columns, escape: '');
        $pipe = fn (?string $list) => collect(explode(',', (string) $list))->map(fn ($v) => trim($v))->filter()->implode('|');
        $ids = $descriptions->pluck('id')->all();
        // A parent before the items inside it.
        $ordered = [];
        $place = function ($item) use (&$place, &$ordered, $descriptions, $ids) {
            if (isset($ordered[$item->id])) {
                return;
            }
            if ($item->parent_item_id && in_array($item->parent_item_id, $ids, true)) {
                $place($descriptions->firstWhere('id', $item->parent_item_id));
            }
            $ordered[$item->id] = $item;
        };
        $descriptions->each($place);
        foreach ($ordered as $item) {
            $creators = $pipe($item->creators);
            $dated = $item->date_display || $item->date_start || $item->date_end;
            fputcsv($out, [$item->id, $item->parent_item_id && in_array($item->parent_item_id, $ids, true) ? $item->parent_item_id : '', $item->identifier, $item->title, $item->level?->iri ?? '',
                $item->extent_and_medium, $item->scope_and_content, $item->access_conditions, $item->reproduction_conditions, $item->notes, $pipe($item->subjects), $pipe($item->places),
                // One creation event for each creator, the dates on the first; a date with no creator is a creation event of its own.
                $pipe($item->genres), $creators, $creators !== '' || $dated ? implode('|', array_fill(0, $creators === '' ? 1 : substr_count($creators, '|') + 1, 'Creation')) : '',
                $dated ? (string) $item->date_display : '', $item->date_start?->toDateString() ?? '', $item->date_end?->toDateString() ?? '', $publish ? 'Published' : 'Draft', $language], escape: '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    // Lists.

    /** The submissions waiting for a reviewer, or all of the institution's in a status (PSIS's getPendingReviews). */
    public function pending(User $reviewer, ?string $status = null)
    {
        return ResearcherSubmission::where('institution_id', $reviewer->institution_id)
            ->whereHas('status', fn ($s) => $s->whereIn('iri', array_map(fn ($n) => self::STATUSES.$n, $status ? [$status] : ['submitted', 'under-review', 'approved'])))
            ->with('status', 'user', 'reviewer')->orderBy('submitted_at');
    }

    /** PSIS's getDashboardStats: how many of one's submissions are in each status. @return array<string, int> */
    public function stats(User $user): array
    {
        return ResearcherSubmission::where('user_id', $user->id)->join('concepts', 'concepts.id', '=', 'researcher_submissions.status_id')
            ->selectRaw('concepts.notation, count(*) as n')->groupBy('concepts.notation')->pluck('n', 'notation')->map(fn ($n) => (int) $n)->all();
    }

    /** The levels of description a description may have: the bundled ones, then the institution's own. */
    public function levels(string $institutionId): Collection
    {
        return Concept::bundledThenLocal(self::LEVELS, 'level-of-description', $institutionId);
    }

    public function mayReview(User $user): bool
    {
        return $user->hasPermission(Vocab::permission('review-submissions'));
    }

    public function mustOwn(User $user, ResearcherSubmission $submission): void
    {
        if ($submission->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function mustChange(User $user, ResearcherSubmission $submission): void
    {
        $this->mustOwn($user, $submission);
        if (! in_array($submission->status->notation, self::OPEN, true)) {
            throw new InvalidArgumentException(__('It is with the reviewers; it can be changed once it is returned.'));
        }
    }

    private function mustReview(User $user, ResearcherSubmission $submission): void
    {
        if (! $this->mayReview($user) || $submission->institution_id !== $user->institution_id) {
            throw new InvalidArgumentException(__('You may not review submissions.'));
        }
    }

    private function fields(User $user, array $data): array
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the submission a title.'));
        }
        $repository = ($data['repository_id'] ?? null) ? Repository::where(['institution_id' => $user->institution_id, 'id' => $data['repository_id']])->whereNull('deleted_at')->first()
            ?? throw new InvalidArgumentException(__('Choose a repository from the list.')) : null;
        $parent = ($data['parent_iri'] ?? '') !== '' ? $this->record($user, (string) $data['parent_iri']) : null;

        return ['title' => mb_substr(trim($data['title']), 0, 255), 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'repository_id' => $repository?->id ?? $parent?->repository_id, 'parent_record_id' => $parent?->id];
    }

    private function record(User $user, string $iri): RecordResource
    {
        $record = trim($iri) === '' ? null : RecordResource::where(['institution_id' => $user->institution_id, 'iri' => trim($iri)])->whereNull('deleted_at')->first();

        return $record && EntityKinds::readable($user, $record) ? $record : throw new InvalidArgumentException(__('No record has that IRI.'));
    }

    private function importItem(ResearcherSubmission $submission, User $user, array $fields): ResearcherSubmissionItem
    {
        $type = $fields['item_type'];
        $data = array_intersect_key($fields, array_flip(self::FIELDS));
        $level = $type === 'description' ? $this->levels($submission->institution_id)->first(fn ($c) => $c->notation === ($fields['level'] ?? 'item')) : null;
        $record = $type === 'note' && ($fields['iri'] ?? null) ? RecordResource::where(['institution_id' => $submission->institution_id, 'iri' => $fields['iri']])->whereNull('deleted_at')->first() : null;
        $record = $record && EntityKinds::readable($user, $record) ? $record : null;
        foreach (['creators', 'subjects', 'places', 'genres'] as $list) {
            if (isset($data[$list])) {
                $data[$list] = Diary::tags($data[$list]);
            }
        }

        $data = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        $data['title'] ??= $record ? EntityKinds::label($record) : __('Note');

        return $submission->items()->create($data + [
            'item_type_id' => $this->concept(self::TYPES, $type), 'level_id' => $level?->id, 'parent_item_id' => $fields['parent_item_id'] ?? null, 'reference_record_id' => $record?->id,
            'sort_order' => (int) $submission->items()->max('sort_order') + 1]);
    }

    /** A file of an exchange entry: its data (base64, or a data: URL) kept; without data, its name only, as PSIS keeps a placeholder. */
    private function importFile(User $user, ResearcherSubmissionItem $item, array $file): bool
    {
        $name = mb_substr(basename((string) ($file['filename'] ?? 'file')), 0, 255) ?: 'file';
        $raw = (string) ($file['data'] ?? '');
        $bytes = $raw === '' ? null : base64_decode(preg_match('/^data:[^;]+;base64,(.+)$/s', $raw, $m) ? $m[1] : $raw, true);
        if ($raw !== '' && ($bytes === false || $bytes === '')) {
            return false;
        }
        $kept = null;
        if ($bytes !== null) {
            $path = tempnam(sys_get_temp_dir(), 'exchange');
            file_put_contents($path, $bytes);
            try {
                $kept = $this->files->keep(new UploadedFile($path, $name, null, null, true), $item->submission->institution_id, $user);
            } finally {
                @unlink($path);
            }
        }
        $item->files()->create(['file_id' => $kept?->id, 'original_name' => $name, 'caption' => isset($file['caption']) ? (string) $file['caption'] : null,
            'sort_order' => (int) $item->files()->max('sort_order') + 1, 'created_at' => now()]);

        return true;
    }

    /** PSIS's recalculateTotals. */
    private function totals(ResearcherSubmission $submission): void
    {
        $files = ResearcherSubmissionFile::whereIn('item_id', $submission->items()->select('id'))->whereNotNull('file_id')->with('file')->get();
        $submission->update(['total_items' => $submission->items()->whereHas('itemType', fn ($t) => $t->where('iri', self::TYPES.'description'))->count(),
            'total_files' => $files->count(), 'total_file_size' => (int) $files->sum(fn ($f) => $f->file?->size_bytes ?? 0)]);
    }

    /** @return list<string> */
    private function ancestors(ResearcherSubmissionItem $item): array
    {
        $out = [];
        while ($item && $item->parent_item_id && ! in_array($item->parent_item_id, $out, true)) {
            $out[] = $item->parent_item_id;
            $item = ResearcherSubmissionItem::find($item->parent_item_id);
        }

        return $out;
    }

    private function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }

    private function log(ResearcherSubmission $submission, User $user, string $action, ?string $comment): void
    {
        $submission->reviews()->create(['reviewer_id' => $user->id, 'action_id' => $this->concept(self::ACTIONS, $action), 'comment' => $comment, 'created_at' => now()]);
    }

    private function concept(string $scheme, string $notation): string
    {
        return Concept::where('iri', $scheme.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
