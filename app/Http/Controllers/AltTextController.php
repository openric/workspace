<?php

/**
 * AltTextController - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * Licensed under the GNU Affero General Public License v3 or later.
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-core/src/Controllers/AltTextController.php and
 * src/Services/AltTextService.php at commit 011766951 (governance/CODE_PROVENANCE.md; PSIS
 * ahgAccessibilityPlugin): the worklist of images without alternative text in a working language,
 * paginated, with the coverage figure, and saving one image's text, a blank one clearing it.
 * Changed: the text is the instantiation's alternative-text element, one value in each language,
 * draft until published like every other value, so the image_alt_text table, normalizeLang and
 * the Schema guards are not needed; the language is a BCP 47 tag; the worklist holds every image
 * of the institution, not only published ones, since a draft can be given its text before it is
 * published, and an account that may publish the unit can publish the text as it saves it; the
 * hint beside an image is its embedded caption, from the master's metadata. The AI suggestion
 * (suggest()) waits for phase 17.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Description\Bcp47;
use App\Description\InstantiationService;
use App\Models\Concept;
use App\Models\DescriptionValue;
use App\Models\Instantiation;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AltTextController extends Controller
{
    private const ELEMENT = 'instantiation-alt-text';

    /** Max stored length, as Heratio's MAX_ALT_LEN. */
    public const MAX_ALT_LEN = 2000;

    private const PER_PAGE = 25;

    public function __construct(private InstantiationService $instantiations) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->mayAnywhere('update', 'description'), 403);
        $lang = $this->lang($request);
        $show = $request->query('show') === 'all' ? 'all' : 'missing';
        $total = $this->images($user->institution_id)->count();
        $with = $this->images($user->institution_id)->whereHas('descriptionValues', $this->altIn($lang))->count();
        $worklist = $this->images($user->institution_id)
            ->when($show === 'missing', fn ($q) => $q->whereDoesntHave('descriptionValues', $this->altIn($lang)))
            ->with(['files.use', 'record', 'names.nameType', 'descriptionValues.element', 'descriptionValues.state'])
            ->orderBy('record_resource_id')->orderBy('position')->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('alt-text.index', ['lang' => $lang, 'show' => $show, 'worklist' => $worklist, 'maxAltLen' => self::MAX_ALT_LEN,
            'coverage' => ['total' => $total, 'with' => $with, 'pct' => $total > 0 ? round($with / $total * 100, 1) : 0.0]]);
    }

    public function store(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $user = $request->user();
        $unit = $instantiation->record;
        abort_unless($instantiation->institution_id === $user->institution_id && $instantiation->deleted_at === null && $unit->deleted_at === null, 404);
        abort_unless($user->may('update', 'description', $unit), 403);
        $data = $request->validate(['alt_text' => ['nullable', 'string', 'max:'.self::MAX_ALT_LEN], 'publish' => ['nullable', 'boolean']]);
        $lang = $this->lang($request);
        $text = trim((string) ($data['alt_text'] ?? ''));

        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($instantiation, $unit, $user, $lang, $text, $data) {
            $this->instantiations->describe($instantiation, [self::ELEMENT => $text], $lang, $user);
            if ($text !== '' && ! empty($data['publish']) && $user->may('publish', 'description', $unit)) {
                DescriptionValue::where(['subject_table' => 'instantiations', 'subject_id' => $instantiation->id, 'language' => $lang])
                    ->whereHas('element', fn ($q) => $q->where('iri', Vocab::element(self::ELEMENT)))->first()
                    ?->update(['state_id' => Concept::where('iri', Vocab::valueState('published'))->value('id')]);
            }
        }));

        return redirect()->route('alt-text.index', array_filter(['language' => $lang, 'show' => $request->input('show'), 'page' => $request->input('page')]))
            ->with('status', $text === '' ? __('Alternative text cleared.') : __('Alternative text saved.'));
    }

    /** Instantiations of the institution whose master is an image, of units not deleted. */
    private function images(string $institutionId): Builder
    {
        return Instantiation::where('institution_id', $institutionId)->whereNull('deleted_at')
            ->whereHas('record', fn ($q) => $q->whereNull('deleted_at'))
            ->whereHas('files', fn ($q) => $q->where('mime_type', 'like', 'image/%')->whereHas('use', fn ($q) => $q->where('notation', 'master')));
    }

    private function altIn(string $lang): \Closure
    {
        return fn ($q) => $q->where('language', $lang)->whereHas('element', fn ($q) => $q->where('iri', Vocab::element(self::ELEMENT)));
    }

    /** The working language; not ?lang=, which chooses the interface's. */
    private function lang(Request $request): string
    {
        $lang = str_replace('_', '-', trim((string) $request->input('language', app()->getLocale())));

        return Bcp47::valid($lang) ? $lang : 'en';
    }
}
