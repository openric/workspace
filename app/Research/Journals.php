<?php

// OpenRiC Workspace - research journals, their issues and articles, and the directory of journals to aim at (roadmap
// phase 19g, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchJournalService, ResearchJournalController, ResearchTargetJournalService and
// ResearchTargetJournalController. A journal is one a researcher or the institution publishes, or a manuscript being
// prepared for an outside journal; it has issues by volume and number, and articles (title, authors, abstract,
// keywords, a body written in Markdown and its word count, reference style, DOI), placed in an issue or not yet. Its
// table of contents lists the issues and their articles. An article aimed at a journal of the directory is checked
// against it as Heratio's validateManuscript checks it: title, abstract, authors, body, reference style, word limits.
// The directory holds the journals the institution's researchers aim at (scope, accreditation, reference style, word
// limits, peer review, open access, charges); it can start from Heratio's list of South African journals accredited
// by the DHET. A researcher's journal is theirs to edit; the institution's, and the directory, are kept by who runs the
// research services.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\ResearchJournal;
use App\Models\ResearchJournalArticle;
use App\Models\ResearchJournalIssue;
use App\Models\ResearchTargetJournal;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Journals
{
    private const ISSN = '/^\d{4}-\d{3}[\dX]$/';

    public function mayRun(User $user): bool
    {
        return $user->hasPermission(Vocab::permission('manage-reading-room'));
    }

    public function mayEdit(User $user, ResearchJournal $journal): bool
    {
        return $journal->institution_id === $user->institution_id && ($journal->user_id ? $journal->user_id === $user->id : $this->mayRun($user));
    }

    /** One's own journals, the institution's, and every published one of it. */
    public function visible(User $user): Collection
    {
        return ResearchJournal::where('institution_id', $user->institution_id)->with('kind', 'status', 'user')->orderBy('title')->get()
            ->filter(fn ($j) => $this->mayEdit($user, $j) || $j->status->notation === 'published')->values();
    }

    public function saveJournal(User $user, array $data, ?ResearchJournal $journal = null): ResearchJournal
    {
        if ($journal && ! $this->mayEdit($user, $journal)) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        if (! $journal && ! empty($data['institutional']) && ! $this->mayRun($user)) {
            throw new InvalidArgumentException(__('Only who runs the research services makes a journal of the institution.'));
        }
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the journal a title.'));
        }
        foreach (['issn', 'eissn'] as $k) {
            if (($data[$k] ?? '') !== '' && $data[$k] !== null && ! preg_match(self::ISSN, mb_strtoupper(trim($data[$k])))) {
                throw new InvalidArgumentException(__('Give an ISSN as 1234-567X.'));
            }
        }
        $cover = ($data['cover_iri'] ?? '') !== '' ? RecordResource::where(['institution_id' => $user->institution_id, 'iri' => trim($data['cover_iri'])])->first() : null;
        if (($data['cover_iri'] ?? '') !== '' && (! $cover || ! EntityKinds::readable($user, $cover))) {
            throw new InvalidArgumentException(__('No record has that IRI.'));
        }
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $fields = ['kind_id' => $this->concept('journal-kind', (string) ($data['kind'] ?? 'publication')), 'title' => mb_substr(trim($data['title']), 0, 255), 'subtitle' => $text('subtitle'),
            'publisher' => $text('publisher'), 'description' => $text('description'), 'aims_scope' => $text('aims_scope'), 'editor_name' => $text('editor_name'), 'editor_email' => $text('editor_email'),
            'doi' => FieldAlerts::doi($data['doi'] ?? null), 'issn' => $text('issn') ? mb_strtoupper($text('issn')) : null, 'eissn' => $text('eissn') ? mb_strtoupper($text('eissn')) : null,
            'target_journal_id' => $this->target($user, $data['target_journal_id'] ?? null)?->id, 'cover_record_id' => $cover?->id,
            'status_id' => $this->concept('journal-status', (string) ($data['status'] ?? $journal?->status->notation ?? 'draft'))];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $journal ? tap($journal)->update($fields)
            : ResearchJournal::create($fields + ['institution_id' => $user->institution_id, 'user_id' => empty($data['institutional']) ? $user->id : null])));
    }

    public function deleteJournal(User $user, ResearchJournal $journal): void
    {
        $this->mayEdit($user, $journal) || throw new InvalidArgumentException(__('That is not yours.'));
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $journal->delete()));
    }

    public function saveIssue(User $user, ResearchJournal $journal, array $data, ?ResearchJournalIssue $issue = null): ResearchJournalIssue
    {
        $this->mayEdit($user, $journal) || throw new InvalidArgumentException(__('That is not yours.'));
        $fields = ['volume' => trim((string) ($data['volume'] ?? '')) ?: null, 'number' => trim((string) ($data['number'] ?? '')) ?: null, 'title' => trim((string) ($data['title'] ?? '')) ?: null,
            'issue_date' => ($data['issue_date'] ?? '') ?: null, 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'status_id' => $this->concept('issue-status', (string) ($data['status'] ?? $issue?->status->notation ?? 'draft')), 'sort_order' => (int) ($data['sort_order'] ?? $issue?->sort_order ?? 0)];
        if ($fields['volume'] === null && $fields['number'] === null && $fields['title'] === null) {
            throw new InvalidArgumentException(__('Give the issue a volume, a number or a title.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $issue ? tap($issue)->update($fields) : $journal->issues()->create($fields)));
    }

    public function saveArticle(User $user, ResearchJournal $journal, array $data, ?ResearchJournalArticle $article = null): ResearchJournalArticle
    {
        $this->mayEdit($user, $journal) || throw new InvalidArgumentException(__('That is not yours.'));
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the article a title.'));
        }
        $issue = ($data['issue_id'] ?? null) ? $journal->issues()->where('id', $data['issue_id'])->first() ?? throw new InvalidArgumentException(__('Choose an issue of this journal.')) : null;
        $body = (string) ($data['body_markdown'] ?? '');
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $fields = ['issue_id' => $issue?->id, 'title' => mb_substr(trim($data['title']), 0, 500), 'authors' => $text('authors'), 'abstract' => $text('abstract'), 'keywords' => Diary::tags($data['keywords'] ?? null),
            'body_markdown' => $body === '' ? null : $body, 'word_count' => self::words($body), 'reference_style' => $text('reference_style'), 'doi' => FieldAlerts::doi($data['doi'] ?? null),
            'target_journal_id' => $this->target($user, $data['target_journal_id'] ?? null)?->id, 'status_id' => $this->concept('article-status', (string) ($data['status'] ?? $article?->status->notation ?? 'draft')),
            'sort_order' => (int) ($data['sort_order'] ?? $article?->sort_order ?? 0)];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $article ? tap($article)->update($fields) : $journal->articles()->create($fields)));
    }

    public function remove(User $user, ResearchJournalIssue|ResearchJournalArticle $thing): void
    {
        $this->mayEdit($user, $thing->journal) || throw new InvalidArgumentException(__('That is not yours.'));
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $thing->delete()));
    }

    /** Heratio's tableOfContents: the issues with their articles, then those not yet placed. @return list<array{issue: ?ResearchJournalIssue, articles: Collection}> */
    public function contents(ResearchJournal $journal): array
    {
        $toc = $journal->issues()->with('status', 'articles.status')->get()->map(fn ($i) => ['issue' => $i, 'articles' => $i->articles])->all();
        $loose = $journal->articles()->whereNull('issue_id')->with('status')->get();

        return $loose->isEmpty() ? $toc : [...$toc, ['issue' => null, 'articles' => $loose]];
    }

    /** Heratio's validateManuscript: what keeps an article from being sent to its target journal. @return list<string> */
    public function check(ResearchJournalArticle $article): array
    {
        $problems = [];
        foreach (['title' => 'Title is required.', 'abstract' => 'An abstract is required for submission.', 'authors' => 'At least one author must be listed.'] as $field => $message) {
            if (trim((string) $article->{$field}) === '') {
                $problems[] = __($message);
            }
        }
        if ($article->word_count < 1) {
            $problems[] = __('The manuscript body is empty.');
        }
        if ($target = $article->targetJournal ?? $article->journal->targetJournal) {
            if ($target->reference_style && $article->reference_style && strcasecmp($target->reference_style, $article->reference_style) !== 0) {
                $problems[] = __('Reference style should be :style for this journal.', ['style' => $target->reference_style]);
            }
            if ($target->max_words && $article->word_count > $target->max_words) {
                $problems[] = __('The manuscript has :n words; this journal allows :max.', ['n' => $article->word_count, 'max' => $target->max_words]);
            }
            if ($target->abstract_max_words && self::words((string) $article->abstract) > $target->abstract_max_words) {
                $problems[] = __('The abstract has :n words; this journal allows :max.', ['n' => self::words((string) $article->abstract), 'max' => $target->abstract_max_words]);
            }
        }

        return $problems;
    }

    /** The article's body as HTML: Markdown, with any HTML in it escaped. */
    public static function html(?string $markdown): string
    {
        return Str::markdown((string) $markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    public static function words(string $text): int
    {
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'\-]*/u', strip_tags($text));
    }

    // The directory of target journals.

    public function directory(User $user, array $filters = []): Collection
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return ResearchTargetJournal::where('institution_id', $user->institution_id)->with('status')
            ->when($filters['market'] ?? null, fn ($w, $m) => $w->where('accreditation_market', mb_strtoupper($m)))
            ->when(! empty($filters['open_access']), fn ($w) => $w->where('open_access', true))
            ->when($q !== '', fn ($w) => $w->where(fn ($o) => $o->where('title', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('subject_scope', 'ilike', '%'.addcslashes($q, '%_\\').'%')))
            ->orderBy('title')->get();
    }

    public function saveTarget(User $user, array $data, ?ResearchTargetJournal $journal = null): ResearchTargetJournal
    {
        $this->mayRun($user) || throw new InvalidArgumentException(__('Only who runs the research services keeps the directory.'));
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the journal a title.'));
        }
        foreach (['issn', 'eissn'] as $k) {
            if (($data[$k] ?? '') !== '' && $data[$k] !== null && ! preg_match(self::ISSN, mb_strtoupper(trim($data[$k])))) {
                throw new InvalidArgumentException(__('Give an ISSN as 1234-567X.'));
            }
        }
        $fields = ['title' => mb_substr(trim($data['title']), 0, 300), 'open_access' => ! empty($data['open_access']), 'status_id' => $this->concept('target-journal-status', (string) ($data['status'] ?? 'active')),
            'max_words' => ($data['max_words'] ?? '') !== '' ? (int) $data['max_words'] : null, 'abstract_max_words' => ($data['abstract_max_words'] ?? '') !== '' ? (int) $data['abstract_max_words'] : null,
            'accreditation_market' => ($data['accreditation_market'] ?? '') !== '' ? mb_strtoupper(mb_substr(trim($data['accreditation_market']), 0, 8)) : null];
        foreach (['subtitle', 'publisher', 'homepage_url', 'submission_url', 'languages', 'subject_scope', 'article_types', 'accreditation', 'reference_style', 'structure_notes', 'peer_review',
            'apc_amount', 'turnaround', 'notes'] as $k) {
            $fields[$k] = trim((string) ($data[$k] ?? '')) ?: null;
        }
        foreach (['issn', 'eissn'] as $k) {
            $fields[$k] = ($data[$k] ?? '') !== '' && $data[$k] !== null ? mb_strtoupper(trim($data[$k])) : null;
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $journal ? tap($journal)->update($fields) : ResearchTargetJournal::create($fields + ['institution_id' => $user->institution_id])));
    }

    /** Heratio's seedDhetStarter: South African journals accredited by the DHET, added or brought up to date by title. @return int how many */
    public function seedDhet(User $user): int
    {
        $this->mayRun($user) || throw new InvalidArgumentException(__('Only who runs the research services keeps the directory.'));
        $rows = json_decode(file_get_contents(resource_path('research/target-journals-za.json')), true);

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $rows) {
            foreach ($rows as $row) {
                $existing = ResearchTargetJournal::where(['institution_id' => $user->institution_id, 'title' => $row['title']])->first();
                $this->saveTarget($user, $row + ['accreditation_market' => 'ZA', 'status' => 'active'], $existing);
            }

            return count($rows);
        }));
    }

    private function target(User $user, ?string $id): ?ResearchTargetJournal
    {
        return $id ? ResearchTargetJournal::where(['id' => $id, 'institution_id' => $user->institution_id])->first() ?? throw new InvalidArgumentException(__('Choose a journal of the directory.')) : null;
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
