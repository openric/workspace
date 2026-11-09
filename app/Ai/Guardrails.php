<?php

// OpenRiC Workspace - the guardrails on the catalogue's assistant (roadmap phase 17f), copied from Heratio's
// ahg-ai-services GuardrailService (#141): personal information masked in what a reader asks before it reaches a model
// (email addresses, and runs of nine digits or more: telephone, identity and account numbers), and an answer scored for
// how far its significant words are found in the sources it was given, an answer below the threshold flagged as not
// grounded. Heratio's data-scope and purpose rules are not copied: every call goes through the institution's own AI
// gateway, never to a cloud provider of the reader's choosing.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Ai;

class Guardrails
{
    /** Heratio's default: below this share of an answer's significant words found in its sources, it is not grounded. */
    public const GROUNDING_THRESHOLD = 0.45;

    /**
     * Email addresses and number runs of nine digits or more, masked; dates such as 1939-1945 (eight digits) left alone.
     *
     * @return array{0: string, 1: int} the masked text and how many were masked
     */
    public function maskPii(string $text): array
    {
        $count = 0;
        $text = preg_replace_callback('/[\w.+-]+@[\w-]+\.[\w.-]+/u', function () use (&$count) {
            $count++;

            return '[REDACTED:email]';
        }, $text) ?? $text;
        $text = preg_replace_callback('/\+?\d[\d\s().-]{6,}\d/u', function ($m) use (&$count) {
            if (strlen((string) preg_replace('/\D/', '', $m[0])) >= 9) {
                $count++;

                return '[REDACTED:number]';
            }

            return $m[0];
        }, $text) ?? $text;

        return [$text, $count];
    }

    /**
     * How far an answer is grounded in its sources: the share of its significant words found in them. Too few words to
     * judge counts as grounded, as in Heratio.
     *
     * @param  list<string>  $sources
     * @return array{grounding_score: float, grounded: bool, terms_checked: int}|null null when there are no sources
     */
    public function grounding(string $answer, array $sources): ?array
    {
        $context = mb_strtolower(trim(implode(' ', $sources)));
        if ($context === '') {
            return null;
        }
        $terms = $this->significantTerms($answer);
        if (count($terms) < 5) {
            return ['grounding_score' => 1.0, 'grounded' => true, 'terms_checked' => count($terms)];
        }
        $found = count(array_filter($terms, fn ($t) => str_contains($context, $t)));
        $score = round($found / count($terms), 4);

        return ['grounding_score' => $score, 'grounded' => $score >= self::GROUNDING_THRESHOLD, 'terms_checked' => count($terms)];
    }

    /** Distinct lowercased runs of five or more letters, less a few common words (Heratio's significantTerms). */
    public function significantTerms(string $text): array
    {
        preg_match_all('/\p{L}{5,}/u', mb_strtolower($text), $matches);
        $stop = array_flip(['which', 'their', 'there', 'these', 'those', 'where', 'about', 'would', 'could', 'should', 'other', 'through', 'being', 'because',
            'between', 'during', 'before', 'after', 'while', 'within', 'first', 'three', 'among', 'under', 'above', 'below', 'using', 'based', 'including',
            'various', 'several', 'however', 'therefore']);

        return array_values(array_unique(array_filter($matches[0], fn ($w) => ! isset($stop[$w]))));
    }
}
