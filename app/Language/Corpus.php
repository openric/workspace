<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/LanguageCorpusService.php at
 * commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d4): the collection as
 * a resource for a language: every language it is described in, with how much of it and how many terms carry a label in
 * it, the richest first and Afrikaans just before Dutch (afBeforeNl); the published records described in a language;
 * the terms and places named in it; its transcriptions; the community glossary, contributed, reviewed and moderated;
 * a machine translation of a passage, marked as one; the limits and the disclaimer. Changed: Heratio reads AtoM's i18n
 * rows by culture; the Workspace reads its own language-tagged names and values, a record counted where the public
 * search index holds it, so only what is published; the terms are the labels of the institution's descriptive
 * vocabularies and the names of its places; the transcriptions are its pages' transcripts in the language and the
 * approved contributions; a language is named as the interface names it (InterfaceLanguages); the translation goes
 * through the gateway's translate route with its receipt; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Language;

use App\Ai\Gateway;
use App\Audit\AuditLog;
use App\Description\InstantiationService;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\LanguageGlossaryEntry;
use App\Models\User;
use App\Support\InterfaceLanguages;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Corpus
{
    public const STATUSES = Vocab::BASE.'community-contribution-status/';

    private const MAX_RECORDS = 60;

    private const MAX_TERMS = 120;

    private const MAX_TRANSCRIPTIONS = 40;

    private const MAX_GLOSSARY = 500;

    private const MAX_SNIPPET_CHARS = 1200;

    public const MT_LABEL = 'Machine translation via the AHG gateway - not an official or authoritative translation.';

    public const DISCLAIMER = 'Heritage and endangered languages are living languages that belong to the communities who speak them. This page gathers what the collection holds in or about a language - records described in it, place-names and subject terms that carry a label in it, and any transcriptions - as a resource for speakers, learners and researchers. It does not claim authority over the language itself. The community glossary is contributed by people, reviewed before it appears, and is a shared starting point, not a definitive dictionary.';

    public function __construct(private Gateway $gateway) {}

    /** A language as a BCP 47 primary subtag (af-ZA becomes af); null for anything that is not one. */
    public static function language(?string $code): ?string
    {
        $code = strtolower(trim((string) $code));

        return preg_match('/^([a-z]{2,3})([-_@][a-z0-9]+)*$/', $code, $m) ? $m[1] : null;
    }

    public static function label(string $code): string
    {
        return InterfaceLanguages::name($code) ?: strtoupper($code);
    }

    /** @return list<array{code: string, label: string, records: int, terms: int}> */
    public function languages(Institution $institution): array
    {
        $records = $this->publishedNames($institution)->selectRaw("lower(split_part(n.language, '-', 1)) AS code, count(DISTINCT n.subject_id) AS c")->groupBy('code')->pluck('c', 'code');
        $terms = $this->termLabels($institution)->selectRaw("lower(split_part(l.language, '-', 1)) AS code, count(*) AS c")->groupBy('code')->pluck('c', 'code');
        $out = [];
        foreach (array_unique([...$records->keys()->all(), ...$terms->keys()->all()]) as $code) {
            if (self::language($code) === null || in_array($code, ['und', 'zxx', 'mul'], true)) {
                continue;
            }
            $out[] = ['code' => $code, 'label' => self::label($code), 'records' => (int) ($records[$code] ?? 0), 'terms' => (int) ($terms[$code] ?? 0)];
        }
        usort($out, fn ($a, $b) => [$b['records'], $b['terms'], $a['label']] <=> [$a['records'], $a['terms'], $b['label']]);

        return $this->afBeforeNl($out);
    }

    /** The published records whose title is in the language, with a passage of their description. */
    public function describedRecords(Institution $institution, string $code): Collection
    {
        return $this->publishedNames($institution)->whereRaw("lower(split_part(n.language, '-', 1)) = ?", [$code])
            ->join('search_entries as e', fn ($j) => $j->on('e.subject_id', '=', 'n.subject_id')->where('e.public', true)->where('e.subject_table', 'record_resources'))
            ->select('n.subject_id as id', 'n.value as title', 'e.body')->distinct()->orderBy('n.value')->limit(self::MAX_RECORDS)->get()
            ->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'snippet' => ($s = trim((string) $r->body)) === '' ? null : Str::limit($s, 220)]);
    }

    /** The descriptive terms and places named in the language: a starting word-list. */
    public function terms(Institution $institution, string $code): Collection
    {
        $concepts = $this->termLabels($institution)->whereRaw("lower(split_part(l.language, '-', 1)) = ?", [$code])
            ->select('c.id', 'l.pref_label as name')->orderBy('l.pref_label')->limit(self::MAX_TERMS)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'kind' => 'subject']);
        $places = DB::table('names as n')->where('n.institution_id', $institution->id)->where('n.subject_table', 'places')
            ->where('n.state_id', Concept::where('iri', Vocab::valueState('published'))->value('id'))->whereRaw("lower(split_part(n.language, '-', 1)) = ?", [$code])
            ->select('n.subject_id as id', 'n.value as name')->distinct()->orderBy('n.value')->limit(self::MAX_TERMS)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'kind' => 'place']);

        return $concepts->concat($places)->sortBy(fn ($t) => mb_strtolower($t['name']))->take(self::MAX_TERMS)->values();
    }

    /** Text in the language: the published transcripts of the pages of published records. */
    public function transcriptions(Institution $institution, string $code): Collection
    {
        $public = DB::table('search_entries')->where(['institution_id' => $institution->id, 'public' => true, 'subject_table' => 'record_resources'])->select('subject_id');

        return DB::table('description_values as v')->join('instantiations as i', 'i.id', '=', 'v.subject_id')->where('v.subject_table', 'instantiations')
            ->where('v.element_id', Concept::where('iri', Vocab::element('instantiation-transcript'))->value('id'))
            ->where('v.state_id', Concept::where('iri', Vocab::valueState('published'))->value('id'))
            ->whereRaw("lower(split_part(v.language, '-', 1)) = ?", [$code])->whereNull('i.deleted_at')->whereIn('i.record_resource_id', $public)
            // ponytail: the delivery rule is decided in PHP, so three times as many are read to keep MAX_TRANSCRIPTIONS after
            // those withheld (an embargo on the files, a protocol, a clearance); fewer show when most of a language is withheld.
            ->select('i.record_resource_id as id', 'i.id as instantiation_id', 'v.value')->orderByRaw('length(v.value) DESC')->limit(self::MAX_TRANSCRIPTIONS * 3)->get()
            ->filter(fn ($r) => app(InstantiationService::class)->textDeliverable(null, Instantiation::find($r->instantiation_id)))->take(self::MAX_TRANSCRIPTIONS)->values()
            ->map(fn ($r) => ['id' => $r->id, 'text' => Str::limit(trim((string) $r->value), 1500)]);
    }

    public function glossary(Institution $institution, string $code): Collection
    {
        return LanguageGlossaryEntry::where(['institution_id' => $institution->id, 'language' => $code, 'status_id' => self::status('approved')])
            ->orderBy('term')->limit(self::MAX_GLOSSARY)->get();
    }

    /** $data: language, term, meaning, usage_example, source, contributor_name. Pending until reviewed. */
    public function contribute(Institution $institution, array $data, ?User $user): LanguageGlossaryEntry
    {
        $code = self::language($data['language'] ?? null) ?? throw new InvalidArgumentException(__('Choose the language.'));
        [$term, $meaning] = [trim((string) ($data['term'] ?? '')), trim((string) ($data['meaning'] ?? ''))];
        $term !== '' && $meaning !== '' || throw new InvalidArgumentException(__('Give the term and what it means.'));
        $clip = fn (string $key, int $max) => ($v = trim((string) ($data[$key] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => LanguageGlossaryEntry::create(['institution_id' => $institution->id, 'language' => $code,
            'term' => mb_substr($term, 0, 512), 'meaning' => $meaning, 'usage_example' => $clip('usage_example', 20000), 'source' => $clip('source', 512),
            'contributor_name' => $clip('contributor_name', 255), 'status_id' => self::status('pending'), 'contributed_by_id' => $user?->id])));
    }

    /** A passage translated by machine through the gateway, labelled as such; null when it gives nothing. */
    public function translate(Institution $institution, string $text, string $target, string $source = '', ?User $user = null): ?array
    {
        $text = mb_substr(trim($text), 0, self::MAX_SNIPPET_CHARS);
        $target = self::language($target) ?? 'en';
        if ($text === '') {
            return null;
        }
        $call = $this->gateway->call($institution, 'translate', 'translate', ['text' => $text, 'source' => self::language($source) ?? 'en', 'target' => $target],
            ['field' => 'language-corpus@'.$target, 'user' => $user, 'input' => $text],
            fn (array $answer) => ['output' => (string) ($answer['translated'] ?? $answer['translatedText'] ?? $answer['translation'] ?? ''), 'model' => (string) ($answer['model'] ?? 'nllb-200')]);
        $out = trim($call['read']['output']);

        return $out === '' ? null : ['text' => $out, 'label' => self::MT_LABEL, 'inference_id' => $call['inference']->id];
    }

    public static function status(string $notation): string
    {
        return Concept::where('iri', self::STATUSES.$notation)->valueOrFail('id');
    }

    /** The published authorised names of the institution's records the public search index holds. */
    private function publishedNames(Institution $institution)
    {
        return DB::table('names as n')->where('n.institution_id', $institution->id)->where('n.subject_table', 'record_resources')
            ->where('n.state_id', Concept::where('iri', Vocab::valueState('published'))->value('id'))
            ->where('n.name_type_id', Concept::where('iri', Vocab::NAME_TYPES.'authorised')->value('id'))
            ->whereIn('n.subject_id', DB::table('search_entries')->where(['institution_id' => $institution->id, 'public' => true, 'subject_table' => 'record_resources'])->select('subject_id'));
    }

    /** The labels of the concepts of the institution's descriptive vocabularies (those with a kind). */
    private function termLabels(Institution $institution)
    {
        return DB::table('concept_labels as l')->join('concepts as c', 'c.id', '=', 'l.concept_id')->join('vocabularies as v', 'v.id', '=', 'c.vocabulary_id')
            ->whereNotNull('v.kind_id')->where(fn ($q) => $q->whereNull('v.institution_id')->orWhere('v.institution_id', $institution->id))
            ->whereNull('c.deprecated_at')->where(fn ($q) => $q->whereNull('c.institution_id')->orWhere('c.institution_id', $institution->id));
    }

    /** Heratio's afBeforeNl: Afrikaans just before Dutch, wherever Dutch falls. */
    private function afBeforeNl(array $rows): array
    {
        $codes = array_column($rows, 'code');
        if (! in_array('af', $codes, true) || ! in_array('nl', $codes, true)) {
            return $rows;
        }
        $af = $rows[array_search('af', $codes, true)];
        $out = [];
        foreach ($rows as $row) {
            if ($row['code'] === 'nl') {
                $out[] = $af;
            }
            if ($row['code'] !== 'af') {
                $out[] = $row;
            }
        }

        return $out;
    }
}
