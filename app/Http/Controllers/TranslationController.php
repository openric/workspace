<?php

// OpenRiC Workspace - translation (roadmap phase 17c), after Heratio's ahg-translation TranslationController: a unit's
// elements translated by machine into another language, each translation proposed for review; and the institution's
// own wording of the interface, proposed by whoever may translate, with a machine suggestion, accepted or rejected by
// whoever administers the institution, every change kept.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Ai\AiUnavailable;
use App\Ai\Gateway;
use App\Ai\Translate;
use App\Jobs\AskModel;
use App\Jobs\JobsService;
use App\Models\InterfaceStringChange;
use App\Models\RecordResource;
use App\Support\InterfaceLanguages;
use App\Support\Vocab;
use App\Translation\InterfaceStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class TranslationController extends Controller
{
    public function __construct(private Gateway $gateway, private Translate $translate, private InterfaceStrings $strings) {}

    public function form(Request $request, RecordResource $record): View
    {
        $this->may($request, $record);
        $settings = $this->gateway->settings($record->institution_id);
        $languages = $record->descriptionValues()->pluck('language')->merge($record->names()->pluck('language'))->filter()->unique()->values();

        return view('records.translate', ['record' => $record, 'languages' => $languages, 'elements' => $this->translate->elements(),
            'chosen' => $settings->translation_elements ?: Translate::DEFAULT_ELEMENTS, 'targets' => $this->targets(),
            'target' => $settings->translation_target ?: ($record->originalLanguage('title') === 'en' ? 'af' : 'en'), 'source' => $record->originalLanguage('title'),
            'refusal' => $this->gateway->refusal($record->institution_id)]);
    }

    public function start(Request $request, RecordResource $record): RedirectResponse
    {
        $this->may($request, $record);
        $data = $request->validate(['source' => ['required', 'string', 'max:35'], 'target' => ['required', Rule::in((array) config('workspace.ai.translation_targets')), 'different:source'],
            'elements' => ['required', 'array'], 'elements.*' => [Rule::in($this->translate->elements()->pluck('notation'))]]);
        if ($reason = $this->gateway->refusal($record->institution_id)) {
            return back()->withErrors(['ai' => $reason]);
        }
        $job = app(JobsService::class)->create($record->institution_id, 'ai-assist', __('Translating :title into :language', ['title' => $record->described('title', ['en']),
            'language' => InterfaceLanguages::name($data['target'])]), $request->user(), $record);
        AskModel::dispatch($job->id, 'translate', $record->id, ['source' => $data['source'], 'target' => $data['target'], 'elements' => array_values($data['elements'])]);

        return redirect()->route('records.show', $record)->with('status', __('The translation has been asked for. Each element will wait for review in the proposals queue.'));
    }

    public function strings(Request $request): View
    {
        $locale = (string) $request->query('locale', 'af');
        $locales = $this->strings->locales();
        in_array($locale, $locales, true) || $locale = $locales[1] ?? 'en';
        $institution = $request->user()->institution_id;

        return view('translation.strings', ['locale' => $locale, 'locales' => $locales, 'q' => (string) $request->query('q'), 'missing' => $request->boolean('missing'),
            'rows' => $this->strings->matrix($institution, $locale, $request->query('q'), $request->boolean('missing'), max(1, (int) $request->query('page', 1))),
            'pending' => $this->strings->pending($institution), 'admin' => $this->admin($request), 'suggest' => $this->gateway->available($institution)]);
    }

    /** Whoever administers the institution sets a wording at once; whoever may translate proposes it. */
    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', 'max:35'], 'key' => ['required', 'string'], 'value' => ['nullable', 'string', 'max:20000']]);
        try {
            $this->admin($request) ? $this->strings->set($request->user(), $data['locale'], $data['key'], $data['value'] ?? null)
                : $this->strings->propose($request->user(), $data['locale'], $data['key'], $data['value'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['value' => $e->getMessage()]);
        }

        return back()->with('status', $this->admin($request) ? __('Saved.') : __('Proposed, for whoever administers the institution to accept.'));
    }

    public function review(Request $request, InterfaceStringChange $change, string $decision): RedirectResponse
    {
        abort_unless($change->institution_id === $request->user()->institution_id, 404);
        abort_unless($this->admin($request) && in_array($decision, ['accept', 'reject'], true), 403);
        try {
            $decision === 'accept' ? $this->strings->accept($request->user(), $change->id, $request->input('note')) : $this->strings->reject($request->user(), $change->id, $request->input('note'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['value' => $e->getMessage()]);
        }

        return back()->with('status', $decision === 'accept' ? __('Accepted.') : __('Rejected.'));
    }

    public function history(Request $request): View
    {
        $data = $request->validate(['locale' => ['required', 'string', 'max:35'], 'key' => ['required', 'string']]);

        return view('translation.history', ['locale' => $data['locale'], 'key' => $data['key'], 'changes' => $this->strings->history($request->user()->institution_id, $data['locale'], $data['key'])]);
    }

    /** A machine translation of one string, as JSON (Heratio's mt-suggest). */
    public function suggest(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:20000'], 'locale' => ['required', Rule::in((array) config('workspace.ai.translation_targets'))]]);
        try {
            $translated = $this->translate->suggest($request->user()->institution, $request->user(), $data['key'], $data['locale']);
        } catch (AiUnavailable $e) {
            return $request->wantsJson() ? response()->json(['ok' => false, 'error' => $e->getMessage()], 503) : back()->withErrors(['value' => $e->getMessage()]);
        }

        return $request->wantsJson() ? response()->json(['ok' => true, 'translated' => $translated])
            : back()->with('status', __('Machine suggestion for ":key": :text', ['key' => $data['key'], 'text' => $translated]));
    }

    /** @return array<string, string> code => name, for the languages the gateway translates into */
    private function targets(): array
    {
        return collect((array) config('workspace.ai.translation_targets'))->mapWithKeys(fn ($c) => [$c => InterfaceLanguages::name($c)])->all();
    }

    private function may(Request $request, RecordResource $record): void
    {
        abort_unless($record->institution_id === $request->user()->institution_id && $record->deleted_at === null, 404);
        abort_unless($request->user()->may('translate', 'description', $record) || $request->user()->may('update', 'description', $record), 403);
    }

    private function admin(Request $request): bool
    {
        return $request->user()->hasPermission(Vocab::permission('manage-institution'));
    }
}
