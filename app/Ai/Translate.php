<?php

// OpenRiC Workspace - machine translation (roadmap phase 17c), after Heratio's ahg-translation TranslationController
// (translateText, store, the drafts): the elements of a unit of description chosen, in a language it is described in,
// each sent to the AI gateway's NLLB-200 route and its translation proposed as the element's value in the target
// language, naming its receipt, for review in the queue; an element that already has a value in the target language,
// or the same translation waiting, is left. And the suggestion of a translation for one interface string.
// Changed from Heratio: a draft is a proposal of the review queue (Heratio's ahg_translation_draft), applying it is
// accepting it, and every call is a receipt in the log of inferences (Heratio's ahg_translation_log).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Ai;

use App\Audit\AuditLog;
use App\Description\Elements;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\Proposal;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Translate
{
    /** The elements offered when an institution has chosen none (Heratio's translation_fields default). */
    public const DEFAULT_ELEMENTS = ['title', 'scope-and-content'];

    public function __construct(private Gateway $gateway) {}

    /** The ISAD(G) elements whose text may be translated: every text element, not codes or names other than the title. @return Collection<int, Concept> */
    public function elements(): Collection
    {
        return collect(Elements::byArea(Elements::ISAD_G))->flatten(1)
            ->filter(fn (Concept $e) => Elements::codeKind($e->notation) === null && (Elements::nameType($e->notation) === null || $e->notation === 'title'))->values();
    }

    /**
     * Translate the chosen elements of a unit from one of its languages into another, each proposed. @return int proposals made
     *
     * @param  list<string>  $notations
     */
    public function record(RecordResource $record, User $user, string $source, string $target, array $notations): int
    {
        $source !== $target || throw new InvalidArgumentException(__('Translate into another language than the one translated from.'));
        in_array($target, (array) config('workspace.ai.translation_targets'), true) || throw new InvalidArgumentException(__(':language is not a language the gateway translates into.', ['language' => $target]));
        $institution = Institution::findOrFail($record->institution_id);
        $elements = $this->elements()->whereIn('notation', $notations);
        $made = 0;
        foreach ($elements as $element) {
            $text = trim((string) $record->describedIn($element->notation, $source));
            if ($text === '' || ($record->describedIn($element->notation, $target) ?? '') !== '') {
                continue;
            }
            $call = $this->gateway->call($institution, 'translate', 'translate', ['text' => $text, 'source' => $source, 'target' => $target],
                ['subject' => $record, 'field' => $element->notation.'@'.$target, 'user' => $user, 'input' => $text],
                fn (array $answer) => ['output' => (string) ($answer['translated'] ?? $answer['translatedText'] ?? $answer['translation']
                    ?? throw new InvalidArgumentException('no translation in the answer')), 'model' => (string) ($answer['model'] ?? 'nllb-200')]);
            $translation = trim($call['read']['output']);
            if ($translation === '' || $this->waiting($record, $element, $target, $translation)) {
                continue;
            }
            AuditLog::actingAs($user, fn () => Proposal::create(['institution_id' => $record->institution_id, 'record_resource_id' => $record->id,
                'kind_id' => Concept::where('iri', Vocab::PROPOSAL_KINDS.'value')->value('id'), 'element_id' => $element->id, 'language' => $target, 'value' => $translation,
                'status_id' => Concept::where('iri', Vocab::ASSERTION_STATUSES.'proposed')->value('id'), 'origin_label' => 'ai-translate',
                'evidence' => __('Translated by :model from the :language :element; check it against the original.', ['model' => $call['inference']->model,
                    'language' => $source, 'element' => mb_strtolower((string) $element->label(['en']))]),
                'proposed_by_id' => $user->id, 'inference_id' => $call['inference']->id]));
            $made++;
        }

        return $made;
    }

    /** A machine translation of one interface string, for a person to take or leave. */
    public function suggest(Institution $institution, User $user, string $text, string $target): string
    {
        $call = $this->gateway->call($institution, 'translate', 'translate', ['text' => $text, 'source' => 'en', 'target' => $target],
            ['field' => 'interface-string@'.$target, 'user' => $user, 'input' => $text],
            fn (array $answer) => ['output' => (string) ($answer['translated'] ?? $answer['translatedText'] ?? $answer['translation'] ?? ''), 'model' => (string) ($answer['model'] ?? 'nllb-200')]);

        return trim($call['read']['output']);
    }

    private function waiting(RecordResource $record, Concept $element, string $language, string $value): bool
    {
        return Proposal::where(['record_resource_id' => $record->id, 'element_id' => $element->id, 'language' => $language, 'value' => $value])
            ->whereHas('status', fn ($q) => $q->where('iri', Vocab::ASSERTION_STATUSES.'proposed'))->exists();
    }
}
