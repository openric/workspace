<?php

// OpenRiC Workspace - the institution's own wording of the interface (roadmap phase 17c), after Heratio's
// ahg-translation UiStringService: every string of the interface (the English keys of lang/af.json, which has them all)
// in each language the interface offers, as the Workspace ships it and as the institution has reworded it; a change
// proposed by whoever may translate, then accepted or rejected by whoever administers the institution, who may also
// change a string at once; and the history of every string. The wording is the institution's alone.
// Changed from Heratio: per institution rather than per installation; the change's status is the assertion-status
// vocabulary (proposed, accepted, rejected); English is reworded too, as a string's key is its English.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Translation;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\User;
use App\Support\InterfaceLanguages;
use App\Support\Vocab;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InterfaceStrings
{
    /** @return list<string> the languages the interface offers */
    public function locales(): array
    {
        return array_values(InterfaceLanguages::available());
    }

    /** @return list<string> every string of the interface, by its English */
    public function keys(): array
    {
        return array_keys(json_decode((string) file_get_contents(lang_path('af.json')), true));
    }

    /** @return array<string, string> the strings as the Workspace ships them in a language */
    public function shipped(string $locale): array
    {
        $path = lang_path($locale.'.json');

        return $locale === 'en' || ! is_readable($path) ? [] : (array) json_decode((string) file_get_contents($path), true);
    }

    /** @return array<string, string> the institution's own wording in a language */
    public function own(string $institutionId, string $locale): array
    {
        return DB::table('interface_strings')->where(['institution_id' => $institutionId, 'locale' => $locale])->pluck('value', 'key')->all();
    }

    /**
     * A page of strings with their shipped and own wording, those missing in the language or containing the words.
     *
     * @return LengthAwarePaginator<array{key: string, shipped: ?string, own: ?string, pending: int}>
     */
    public function matrix(string $institutionId, string $locale, ?string $contains, bool $missingOnly, int $page, int $perPage = 50): LengthAwarePaginator
    {
        $this->guard($locale);
        [$shipped, $own] = [$this->shipped($locale), $this->own($institutionId, $locale)];
        $needle = mb_strtolower(trim((string) $contains));
        $keys = collect($this->keys())->filter(fn ($k) => $needle === '' || str_contains(mb_strtolower($k), $needle) || str_contains(mb_strtolower($shipped[$k] ?? ''), $needle)
            || str_contains(mb_strtolower($own[$k] ?? ''), $needle))
            ->filter(fn ($k) => ! $missingOnly || ($locale !== 'en' && ($shipped[$k] ?? '') === '' && ($own[$k] ?? '') === ''))->values();
        $pending = DB::table('interface_string_changes')->where(['institution_id' => $institutionId, 'locale' => $locale, 'status_id' => $this->status('proposed')])
            ->selectRaw('key, count(*) AS n')->groupBy('key')->pluck('n', 'key');
        $rows = $keys->slice(($page - 1) * $perPage, $perPage)->map(fn ($k) => ['key' => $k, 'shipped' => $shipped[$k] ?? null, 'own' => $own[$k] ?? null, 'pending' => (int) ($pending[$k] ?? 0)])->values();

        return new LengthAwarePaginator($rows, $keys->count(), $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    /** Propose a new wording, for whoever administers the institution to accept. */
    public function propose(User $user, string $locale, string $key, ?string $value): string
    {
        $this->guard($locale, $key);
        $id = (string) Str::uuid7();
        DB::table('interface_string_changes')->insert(['id' => $id, 'institution_id' => $user->institution_id, 'locale' => $locale, 'key' => $key,
            'old_value' => $this->own($user->institution_id, $locale)[$key] ?? null, 'new_value' => self::clean($value), 'status_id' => $this->status('proposed'),
            'submitted_by_id' => $user->id, 'submitted_at' => now()]);

        return $id;
    }

    /** Set a wording at once, or accept a proposed change; an empty one goes back to the Workspace's. */
    public function set(User $user, string $locale, string $key, ?string $value, ?string $changeId = null, ?string $note = null): void
    {
        $this->guard($locale, $key);
        $value = self::clean($value);
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $locale, $key, $value, $changeId, $note) {
            $old = $this->own($user->institution_id, $locale)[$key] ?? null;
            $where = ['institution_id' => $user->institution_id, 'locale' => $locale, 'key' => $key];
            $value === null ? DB::table('interface_strings')->where($where)->delete()
                : DB::table('interface_strings')->upsert([$where + ['id' => (string) Str::uuid7(), 'value' => $value, 'updated_by_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]],
                    ['institution_id', 'locale', 'key'], ['value', 'updated_by_id', 'updated_at']);
            $changeId ? DB::table('interface_string_changes')->where(['id' => $changeId, 'institution_id' => $user->institution_id])
                ->update(['status_id' => $this->status('accepted'), 'reviewed_by_id' => $user->id, 'reviewed_at' => now(), 'review_note' => $note])
                : DB::table('interface_string_changes')->insert($where + ['id' => (string) Str::uuid7(), 'old_value' => $old, 'new_value' => $value,
                    'status_id' => $this->status('accepted'), 'submitted_by_id' => $user->id, 'submitted_at' => now(), 'reviewed_by_id' => $user->id, 'reviewed_at' => now(), 'review_note' => $note]);
            AuditLog::record(Vocab::action('update'), $user->institution, null, ['interface_string' => $key, 'locale' => $locale], $user, $user->institution_id);
        }));
        $this->forget();
    }

    public function accept(User $user, string $changeId, ?string $note = null): void
    {
        $change = $this->pending($user->institution_id)->firstWhere('id', $changeId) ?? throw new InvalidArgumentException(__('That change was reviewed already.'));
        $this->set($user, $change->locale, $change->key, $change->new_value, $change->id, $note);
    }

    public function reject(User $user, string $changeId, ?string $note = null): void
    {
        $this->pending($user->institution_id)->firstWhere('id', $changeId) ?? throw new InvalidArgumentException(__('That change was reviewed already.'));
        DB::table('interface_string_changes')->where('id', $changeId)->update(['status_id' => $this->status('rejected'), 'reviewed_by_id' => $user->id,
            'reviewed_at' => now(), 'review_note' => $note]);
    }

    /** @return Collection<int, object> changes waiting for review */
    public function pending(string $institutionId, ?string $locale = null): Collection
    {
        return DB::table('interface_string_changes as c')->join('users as u', 'u.id', '=', 'c.submitted_by_id')->where(['c.institution_id' => $institutionId, 'c.status_id' => $this->status('proposed')])
            ->when($locale, fn ($q, $l) => $q->where('c.locale', $l))->orderBy('c.submitted_at')->get(['c.*', 'u.name as submitted_by']);
    }

    /** @return Collection<int, object> every change to a string, newest first */
    public function history(string $institutionId, string $locale, string $key): Collection
    {
        return DB::table('interface_string_changes as c')->join('users as u', 'u.id', '=', 'c.submitted_by_id')->leftJoin('users as r', 'r.id', '=', 'c.reviewed_by_id')
            ->join('concepts as s', 's.id', '=', 'c.status_id')->where(['c.institution_id' => $institutionId, 'c.locale' => $locale, 'c.key' => $key])
            ->orderByDesc('c.submitted_at')->limit(50)->get(['c.*', 'u.name as submitted_by', 'r.name as reviewed_by', 's.notation as status']);
    }

    /** The translator reads the overlay afresh, in this request and the next. */
    public function forget(): void
    {
        $loader = app('translator')->getLoader();
        $loader instanceof OverlayLoader && $loader->flush();
        app('translator')->setLoaded([]);
    }

    private function guard(string $locale, ?string $key = null): void
    {
        in_array($locale, $this->locales(), true) || throw new InvalidArgumentException(__('The interface is not offered in :locale.', ['locale' => $locale]));
        $key === null || in_array($key, $this->keys(), true) || throw new InvalidArgumentException(__('The interface has no such string.'));
    }

    private function status(string $notation): string
    {
        return Concept::where('iri', Vocab::ASSERTION_STATUSES.$notation)->valueOrFail('id');
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 20000);
    }
}
