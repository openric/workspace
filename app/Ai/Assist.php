<?php

// OpenRiC Workspace - asking a model to help describe (roadmap phase 17b1), after Heratio's ahg-ai-services NerService,
// LlmService::summarize and its condition assessment: the names of people, organisations, places and dates found in a
// unit's description and the transcripts of its pages, each proposed as an access point or a date; a summary of its
// pages, proposed as its scope and content; and the condition of an object read from its picture by a vision model,
// proposed as the physical characteristics of its instantiation with a grade from the condition-grade vocabulary.
// Every call goes through the AI gateway and is a receipt in the log of inferences; every answer is a proposal that
// names it, reviewed in the queue (ADR 0007). A model's own score is kept where it gives one and never made up.
// Changed from Heratio: names come from spaCy only, through the gateway's NER route, without Heratio's fallback to a
// language model; condition is a vision model's reading until a trained condition model has a gateway route
// (ArchiveHeritageGroup/ahg-ai#5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Ai;

use App\Audit\AuditLog;
use App\Description\InstantiationService;
use App\Jobs\ReadPageText;
use App\Models\Concept;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\User;
use App\Proposals\ProposalService;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Assist
{
    /** ponytail: at most this much text a call; a longer description is cut, not split into several calls. */
    public const MAX_TEXT = 20000;

    /** Heratio's summary length, in words. */
    private const SUMMARY_WORDS = [30, 200];

    /** spaCy's labels, as the proposals take them (ProposalService::TYPES). */
    private const NER_TYPES = ['PERSON' => 'PERSON', 'PER' => 'PERSON', 'ORG' => 'ORG', 'GPE' => 'GPE', 'LOC' => 'GPE', 'DATE' => 'DATE'];

    private const CONDITION_PROMPT = <<<'TXT'
You are a conservator examining a photograph of an archival object (a document, volume, photograph or map).
Judge only what is visible. Reply with JSON only, in this form:
{"grade": "<one of: %s>", "damage": ["<each kind of damage you see, such as tears, foxing, staining, fading, mould, insect damage, loss, brittleness, creasing, adhesive tape>"], "notes": "<one or two sentences on where the damage is>"}
If you see no damage, give an empty list. Do not guess at what is not visible.
TXT;

    public function __construct(private Gateway $gateway, private ProposalService $proposals) {}

    /** The text a unit's names are found in: its description, then the transcripts of its pages. */
    public function text(RecordResource $record): string
    {
        $record->loadMissing('descriptionValues', 'instantiations.descriptionValues');
        $parts = $record->descriptionValues->pluck('value')
            ->merge($record->instantiations->whereNull('deleted_at')->map(fn (Instantiation $i) => $i->described('instantiation-transcript', [$record->originalLanguage('title'), 'en', 'af'])))
            ->map(fn ($v) => trim(strip_tags((string) $v)))->filter()->unique();

        return mb_substr($parts->join("\n\n"), 0, self::MAX_TEXT);
    }

    /** Names found in the unit's text by the gateway's NER, each proposed. @return int proposals made */
    public function names(RecordResource $record, User $user): int
    {
        $text = $this->text($record);
        $text !== '' || throw new InvalidArgumentException(__('There is no text to find names in: describe the unit or read its pages first.'));
        $call = $this->gateway->call($this->institution($record), 'ner', 'ner/extract', ['text' => $text, 'clean' => true],
            ['subject' => $record, 'field' => 'access-points', 'user' => $user, 'input' => $text],
            fn (array $answer) => ['output' => json_encode($answer['entities_v2'] ?? $answer['entities'] ?? [], JSON_UNESCAPED_UNICODE), 'model' => (string) ($answer['model'] ?? 'spacy'),
                'model_version' => (string) ($answer['model_version'] ?? 'unknown')]);
        $entities = $this->entities($call['answer']);
        if ($entities === []) {
            return 0;
        }
        $model = $call['inference']->model;
        $evidence = fn (array $e) => isset($e['offset_start']) && $e['offset_end'] > $e['offset_start']
            ? __('Found by :model in: ":context"', ['model' => $model, 'context' => Str::limit(mb_substr($text, max(0, $e['offset_start'] - 80), $e['offset_end'] - $e['offset_start'] + 160), 300)])
            : __('Found by :model in the description and the transcripts of its pages.', ['model' => $model]);
        $result = $this->proposals->propose($record, $user, null, ['source' => 'ai-ner', 'entities' => array_map(fn ($e) => ['type' => $e['type'], 'value' => $e['value'],
            'confidence' => $e['score'], 'evidence' => $evidence($e)], $entities)], $call['inference']->id);

        return count($result['body']['queued'] ?? []);
    }

    /** A summary of the transcripts of the unit's pages, proposed as its scope and content. */
    public function summary(RecordResource $record, User $user): ?Proposal
    {
        $record->loadMissing('instantiations.descriptionValues');
        $language = $record->originalLanguage('title');
        $pages = $record->instantiations->whereNull('deleted_at')->map(fn (Instantiation $i) => trim((string) $i->described('instantiation-transcript', [$language, 'en', 'af'])))->filter();
        $pages->isNotEmpty() || throw new InvalidArgumentException(__('There is nothing to summarise: read the text of its pages first.'));
        $text = mb_substr($pages->join("\n\n"), 0, self::MAX_TEXT);
        [$min, $max] = self::SUMMARY_WORDS;
        $model = $this->gateway->settings($record->institution_id)->chat_model ?: (string) config('workspace.ai.chat_model');
        // Heratio's prompt (LlmService::summarize), asked in the description's language.
        $prompt = "Summarize the following text in {$min} to {$max} words, in the language with the BCP 47 tag {$language}. "
            ."Preserve key facts, dates, and names. Output only the summary, no preamble.\n\nText:\n{$text}";
        $call = $this->gateway->call($this->institution($record), 'summarize', 'ollama/api/chat',
            ['model' => $model, 'stream' => false, 'think' => false, 'options' => ['temperature' => 0.2], 'messages' => [['role' => 'user', 'content' => $prompt]]],
            ['subject' => $record, 'field' => 'scope-and-content', 'user' => $user, 'input' => $prompt, 'input_excerpt' => Str::limit($text, 400)],
            fn (array $answer) => ['output' => self::withoutThinking((string) ($answer['message']['content'] ?? '')), 'model' => (string) ($answer['model'] ?? $model)]);
        $summary = trim($call['read']['output']);
        if ($summary === '') {
            return null;
        }
        $this->proposals->propose($record, $user, null, ['source' => 'ai-summary', 'fields' => [['field' => 'scope_and_content', 'text' => $summary, 'language' => $language,
            'evidence' => trans_choice('Summarised by :model from the transcript of :count page; check it against the pages.|Summarised by :model from the transcripts of :count pages; check it against them.',
                $pages->count(), ['model' => $call['inference']->model, 'count' => $pages->count()])]]], $call['inference']->id);

        return Proposal::where('inference_id', $call['inference']->id)->first();
    }

    /** The condition of the object an instantiation shows, read by a vision model, proposed as its physical characteristics. */
    public function condition(Instantiation $instantiation, User $user): ?Proposal
    {
        $file = ReadPageText::image($instantiation) ?? throw new InvalidArgumentException(__('It has no picture to assess.'));
        $record = $instantiation->record;
        $grades = Concept::choices(Vocab::BASE.'condition-grade/')->get();
        $model = $this->gateway->settings($record->institution_id)->ocr_model ?: (string) config('workspace.ai.ocr_model');
        $image = base64_encode((string) file_get_contents(app(InstantiationService::class)->path($file)));
        $call = $this->gateway->call($this->institution($record), 'condition', 'ollama/api/chat',
            ['model' => $model, 'stream' => false, 'format' => 'json', 'options' => ['temperature' => 0],
                'messages' => [['role' => 'user', 'content' => sprintf(self::CONDITION_PROMPT, $grades->pluck('notation')->join(', ')), 'images' => [$image]]]],
            ['subject' => $instantiation, 'field' => 'instantiation-physical-characteristics', 'user' => $user, 'input' => $image, 'input_excerpt' => 'image: '.$file->original_name],
            function (array $answer) use ($model) {
                $reading = json_decode(self::withoutThinking((string) ($answer['message']['content'] ?? '')), true);
                is_array($reading) || throw new InvalidArgumentException('not JSON');

                return ['output' => json_encode($reading, JSON_UNESCAPED_UNICODE), 'model' => (string) ($answer['model'] ?? $model), 'details' => ['reading' => $reading]];
            });
        $reading = $call['read']['details']['reading'];
        $grade = $grades->firstWhere('notation', strtolower(trim((string) ($reading['grade'] ?? ''))));
        $damage = array_values(array_filter(array_map(fn ($d) => trim((string) $d), (array) ($reading['damage'] ?? []))));
        $language = $record->originalLanguage('title');
        $text = trim(__('Condition: :grade.', ['grade' => $grade ? mb_strtolower((string) $grade->label([$language, 'en'])) : __('not graded')])
            .' '.($damage ? __('Damage seen: :damage.', ['damage' => implode(', ', $damage)]) : __('No damage seen.'))
            .' '.trim((string) ($reading['notes'] ?? '')));

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => Proposal::create([
            'institution_id' => $record->institution_id, 'record_resource_id' => $record->id, 'instantiation_id' => $instantiation->id,
            'kind_id' => Concept::where('iri', Vocab::PROPOSAL_KINDS.'value')->value('id'), 'element_id' => Concept::where('iri', Vocab::element('instantiation-physical-characteristics'))->value('id'),
            'status_id' => Concept::where('iri', Vocab::ASSERTION_STATUSES.'proposed')->value('id'), 'value' => $text, 'language' => $language,
            'evidence' => __('Assessed from the picture :file by :model, a vision model, not a trained condition model; check it against the object itself.', ['file' => $file->original_name, 'model' => $call['inference']->model]),
            'origin_label' => 'ai-condition', 'proposed_by_id' => $user->id, 'inference_id' => $call['inference']->id])));
    }

    /**
     * The entities of a NER answer, with their offsets and score where given: Heratio's entities_v2, else its buckets.
     *
     * @return list<array{type: string, value: string, score: ?float, offset_start?: int, offset_end?: int}>
     */
    private function entities(array $answer): array
    {
        $found = [];
        if (is_array($answer['entities_v2'] ?? null)) {
            foreach ($answer['entities_v2'] as $e) {
                $type = self::NER_TYPES[strtoupper((string) ($e['type'] ?? ''))] ?? null;
                $value = trim((string) ($e['value'] ?? ''));
                if ($type && $value !== '' && mb_strlen($value) <= 500) {
                    $found[$type."\0".mb_strtolower($value)] ??= ['type' => $type, 'value' => $value, 'score' => isset($e['score']) && is_numeric($e['score']) ? (float) $e['score'] : null,
                        'offset_start' => (int) ($e['offset_start'] ?? 0), 'offset_end' => (int) ($e['offset_end'] ?? 0)];
                }
            }
        } else {
            foreach ((array) ($answer['entities'] ?? []) as $label => $values) {
                $type = self::NER_TYPES[strtoupper((string) $label)] ?? ['persons' => 'PERSON', 'organizations' => 'ORG', 'places' => 'GPE', 'dates' => 'DATE'][$label] ?? null;
                foreach ((array) $values as $value) {
                    $value = trim((string) (is_array($value) ? ($value['text'] ?? $value['value'] ?? '') : $value));
                    if ($type && $value !== '' && mb_strlen($value) <= 500) {
                        $found[$type."\0".mb_strtolower($value)] ??= ['type' => $type, 'value' => $value, 'score' => null];
                    }
                }
            }
        }

        return array_values($found);
    }

    /** A reasoning model's thinking, which is not its answer. */
    public static function withoutThinking(string $text): string
    {
        return trim((string) preg_replace('/<think>.*?<\/think>/s', '', $text));
    }

    private function institution(RecordResource $record): Institution
    {
        return Institution::findOrFail($record->institution_id);
    }
}
