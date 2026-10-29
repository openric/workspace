<?php

// OpenRiC Workspace - the next accession or agreement number, after PSIS's AccessionContainerService::generateNextNumber
// (ahgAccessionManagePlugin) and Heratio's accession_numbering_sequence (roadmap phase 9c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A mask holds tokens: {YEAR}, {MONTH}, {DAY}, {REPO} (the repository's identifier), {SEQ} or {SEQ:n} (the sequence,
// padded to n digits) and, for an agreement, {TYPE} (its type's prefix, such as DOG, from the type's scope note). Each
// sequence (the institution's accessions by repository, or its agreements by type) starts again at 1 each year, as PSIS's does,
// and is taken under a row lock, so two people adding at once never get the same number.

namespace App\Description;

use App\Models\Concept;
use App\Models\Institution;
use App\Models\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NumberingService
{
    public const TOKENS = ['{YEAR}', '{MONTH}', '{DAY}', '{REPO}', '{SEQ}', '{SEQ:n}', '{TYPE}'];

    /**
     * The next number of the kind (accession or agreement) that $free accepts: a number already taken moves the
     * sequence on, so a number typed in by hand is never given out again.
     *
     * @param  callable(string): bool  $free
     */
    public function next(Institution $institution, string $kind, ?Repository $repository, ?Concept $type, callable $free): string
    {
        // Read now, not from the model, which may have been loaded before the mask was changed.
        $mask = (string) Institution::whereKey($institution->id)->value($kind === 'agreement' ? 'agreement_numbering_mask' : 'accession_numbering_mask');
        self::valid($mask) || throw new InvalidArgumentException(__('the numbering mask needs a sequence, {SEQ} or {SEQ:n}'));

        return DB::transaction(function () use ($institution, $kind, $repository, $type, $free, $mask) {
            // A mask with {TYPE} numbers each agreement type on its own: DOG-2026-001 and LOA-2026-001.
            $scope = ['institution_id' => $institution->id, 'kind' => $kind, 'repository_id' => $repository?->id, 'type_id' => str_contains($mask, '{TYPE}') ? $type?->id : null];
            DB::table('numbering_sequences')->insertOrIgnore([...$scope, 'id' => (string) Str::uuid7(), 'last_sequence' => 0, 'last_year' => (int) date('Y'), 'created_at' => now(), 'updated_at' => now()]);
            $sequence = DB::table('numbering_sequences')->where($scope)->lockForUpdate()->first();
            $year = (int) date('Y');
            $next = $sequence->last_year === $year ? $sequence->last_sequence : 0;
            do {
                $number = $this->render($mask, ++$next, $repository, $type);
            } while (! $free($number));
            DB::table('numbering_sequences')->where('id', $sequence->id)->update(['last_sequence' => $next, 'last_year' => $year, 'updated_at' => now()]);

            return $number;
        });
    }

    /** A mask gives a different number each time only with a sequence in it. */
    public static function valid(string $mask): bool
    {
        return preg_match('/\{SEQ(?::\d{1,2})?\}/', $mask) === 1 && mb_strlen($mask) <= 100;
    }

    private function render(string $mask, int $sequence, ?Repository $repository, ?Concept $type): string
    {
        $number = strtr($mask, ['{YEAR}' => date('Y'), '{MONTH}' => date('m'), '{DAY}' => date('d'), '{SEQ}' => (string) $sequence,
            '{REPO}' => (string) $repository?->identifier, '{TYPE}' => self::prefix($type)]);
        $number = preg_replace_callback('/\{SEQ:(\d{1,2})\}/', fn ($m) => str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT), $number);

        return trim(preg_replace('/-{2,}/', '-', trim($number, '-')));
    }

    /** An agreement type's prefix, as its scope note gives it ("Heratio numbers it DOG"); AGR, as Heratio's default, else. */
    public static function prefix(?Concept $type): string
    {
        $note = (string) $type?->labels->firstWhere('language', 'en')?->scope_note;

        return preg_match('/numbers it ([A-Z]{2,6})\b/', $note, $m) ? $m[1] : 'AGR';
    }
}
