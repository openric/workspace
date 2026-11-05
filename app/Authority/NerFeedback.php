<?php

// OpenRiC Workspace - names wrongly found, kept to train the named-entity model again (roadmap phase 17b2), after
// Heratio's NerFeedbackService: every rejected mention with the text it was found in, its span, its label, the reason
// and who rejected it; exported as JSON Lines or CoNLL for the gateway's NER model to learn from, each row once.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Authority;

use App\Ai\Assist;
use App\Models\AuthorityDecision;
use App\Models\AuthorityMention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NerFeedback
{
    public function capture(AuthorityMention $mention, AuthorityDecision $decision): string
    {
        $id = (string) Str::uuid7();
        DB::table('ner_feedback')->insert(['id' => $id, 'institution_id' => $mention->institution_id, 'mention_id' => $mention->id, 'decision_id' => $decision->id,
            'source_text' => app(Assist::class)->text($mention->record), 'value' => mb_substr($mention->value(), 0, 1000), 'label' => $mention->label(),
            'offset_start' => $mention->offset_start, 'offset_end' => $mention->offset_end, 'reason' => $decision->reason ?? '(no reason supplied)',
            'user_id' => $decision->user_id, 'ner_model' => $mention->ner_model, 'exported_at' => null, 'created_at' => now()]);

        return $id;
    }

    /**
     * Write every row not yet exported, as JSON Lines or CoNLL, and mark them exported.
     *
     * @return array{count: int, path: string}
     */
    public function export(string $format = 'jsonl', ?string $institutionId = null): array
    {
        in_array($format = strtolower($format), ['jsonl', 'conll'], true) || throw new InvalidArgumentException("Unknown export format: {$format}");
        $rows = DB::table('ner_feedback')->whereNull('exported_at')->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))->orderBy('created_at')->get();
        $dir = storage_path('app/authority/ner-feedback');
        is_dir($dir) || mkdir($dir, 0775, true);
        $path = $dir.'/'.date('Ymd-His').'.'.$format;
        $out = fopen($path, 'wb');
        foreach ($rows as $r) {
            fwrite($out, $format === 'jsonl' ? $this->jsonl($r)."\n" : $this->conll($r));
        }
        fclose($out);
        $rows->isNotEmpty() && DB::table('ner_feedback')->whereIn('id', $rows->pluck('id'))->update(['exported_at' => now()]);

        return ['count' => $rows->count(), 'path' => $path];
    }

    private function jsonl(object $r): string
    {
        return (string) json_encode(['feedback_id' => $r->id, 'mention_id' => $r->mention_id, 'decision_id' => $r->decision_id, 'text' => $r->source_text,
            'spans' => [['start' => $r->offset_start, 'end' => $r->offset_end, 'type' => $r->label, 'value' => $r->value, 'label' => 'reject',
                'rejection_reason' => $r->reason, 'archivist_user_id' => $r->user_id, 'ner_model_version' => $r->ner_model]], 'created_at' => (string) $r->created_at],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Heratio's CoNLL: one token a line, the rejected span tagged B-REJ- and I-REJ- with its label, found by matching its words. */
    private function conll(object $r): string
    {
        $tokens = preg_split('/\s+/u', trim((string) $r->source_text)) ?: [];
        $span = array_values(array_filter(preg_split('/\s+/u', trim((string) $r->value)) ?: [], fn ($t) => $t !== ''));
        $at = -1;
        for ($i = 0; $span !== [] && $i + count($span) <= count($tokens); $i++) {
            if (array_slice($tokens, $i, count($span)) === $span) {
                $at = $i;
                break;
            }
        }
        $lines = ["# feedback_id={$r->id}  mention_id={$r->mention_id}  reject_type={$r->label}"];
        foreach ($tokens as $i => $token) {
            $lines[] = $token."\t".($i === $at ? 'B-REJ-'.$r->label : ($at >= 0 && $i > $at && $i < $at + count($span) ? 'I-REJ-'.$r->label : 'O'));
        }

        return implode("\n", $lines)."\n\n";
    }
}
