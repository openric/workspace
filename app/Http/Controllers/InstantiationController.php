<?php

// OpenRiC Workspace - instantiations: upload a file, add another carrier, view and describe them; deliver files (WS-007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An instantiation is read and changed as its unit of description is. A
// file is delivered only through file(), which asks InstantiationService::
// deliverable(); it is sent with its own type, never sniffed, and sandboxed
// so a file that is a web page cannot run in the Workspace.

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\Elements;
use App\Description\InstantiationService;
use App\Files\FileResponse;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Instantiation;
use App\Models\RecordResource;
use App\Relations\RelationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InstantiationController extends Controller
{
    public function __construct(private InstantiationService $instantiations) {}

    /** Upload a file as a digital instantiation, or add a carrier with no file, such as the paper original. */
    public function store(Request $request, RecordResource $record): RedirectResponse
    {
        $this->unit($request, $record, 'update');
        $data = $request->validate([
            'file' => ['nullable', 'file', 'max:'.(config('workspace.max_upload_megabytes') * 1024)],
            'title' => [Rule::requiredIf(! $request->hasFile('file')), 'nullable', 'string', 'max:2000'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'sha256' => ['nullable', 'string', 'max:64'],
            'carrier_type' => ['nullable', Rule::in($this->choices(InstantiationService::CARRIER_TYPES)->pluck('notation'))],
            'availability' => ['required', Rule::in($this->choices(InstantiationService::AVAILABILITY)->pluck('notation'))],
        ]);
        try {
            if ($request->hasFile('file')) {
                $upload = $request->file('file');
                $instantiation = $this->instantiations->upload($record, $upload->getRealPath(), $upload->getClientOriginalName(), $data['language'],
                    $data['title'] ?? null, $data['sha256'] ?? null, $data['availability'], $request->user());
                $status = __('File kept, SHA-256 :sha.', ['sha' => $instantiation->file('master')->sha256]);
            } else {
                $instantiation = $this->instantiations->create($record, $data['title'], $data['language'], $data['carrier_type'] ?? null, $data['availability'], $request->user());
                $status = __('Instantiation added.');
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['file' => ucfirst($e->getMessage()).'.']);
        }

        return redirect()->route('instantiations.show', $instantiation)->with('status', $status);
    }

    public function show(Request $request, Instantiation $instantiation): View
    {
        $unit = $this->own($request, $instantiation);
        $instantiation->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property',
            'identifiers.identifierType.labels', 'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels', 'carrierType.labels', 'availability.labels', 'files.use.labels');
        $user = $request->user();

        return view('instantiations.show', ['instantiation' => $instantiation, 'unit' => $unit, 'canUpdate' => $user->may('update', 'description', $unit),
            'deliverable' => $instantiation->files->mapWithKeys(fn (DigitalFile $f) => [$f->id => $this->instantiations->deliverable($user, $f)]),
            'dateChoices' => app(DateService::class)->choices('instantiation'), 'relations' => app(RelationService::class)->of($instantiation)]);
    }

    public function edit(Request $request, Instantiation $instantiation): View
    {
        $this->own($request, $instantiation, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);

        return view('instantiations.edit', ['instantiation' => $instantiation->load('descriptionValues.element', 'names.nameType', 'carrierType', 'availability'),
            'language' => $language, 'carrierTypes' => $this->choices(InstantiationService::CARRIER_TYPES), 'availabilities' => $this->choices(InstantiationService::AVAILABILITY)]);
    }

    /** AtoM's own reference or thumbnail representation, given instead of the one made from the master. */
    public function replaceCopy(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $this->own($request, $instantiation, 'update');
        $data = $request->validate(['use' => ['required', Rule::in(['reference', 'thumbnail'])], 'file' => ['required', 'file', 'image', 'max:'.(50 * 1024)]]);
        try {
            $this->instantiations->replaceCopy($instantiation, $data['use'], $request->file('file')->getRealPath(), $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['file' => ucfirst($e->getMessage()).'.']);
        }

        return redirect()->route('instantiations.show', $instantiation)->with('status', __('Copy replaced.'));
    }

    public function update(Request $request, Instantiation $instantiation): RedirectResponse
    {
        $this->own($request, $instantiation, 'update');
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'values' => ['array'],
            'values.*' => ['nullable', 'max:65535'], 'values.*.*' => ['nullable', 'string', 'max:8'],
            'carrier_type' => ['nullable', Rule::in($this->choices(InstantiationService::CARRIER_TYPES)->pluck('notation'))],
            'availability' => ['required', Rule::in($this->choices(InstantiationService::AVAILABILITY)->pluck('notation'))],
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::INSTANTIATION)));
        try {
            DB::transaction(function () use ($instantiation, $data, $values, $request) {
                $this->instantiations->updateFacts($instantiation, $data['carrier_type'] ?? null, $data['availability'], $request->user());
                $this->instantiations->describe($instantiation, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('instantiations.show', $instantiation)->with('status', __('Instantiation saved.'));
    }

    /** The file itself, to whoever may receive it; to anyone else it does not exist. */
    public function file(Request $request, DigitalFile $file): BinaryFileResponse
    {
        $file->load('instantiation.record', 'instantiation.availability', 'use');
        abort_unless($this->instantiations->deliverable($request->user(), $file), 404);
        $path = $this->instantiations->path($file);
        abort_unless(is_file($path), 404);

        return FileResponse::make($file, $path, $request->boolean('download'));
    }

    /** Check now that the kept file is still what was received. */
    public function fixity(Request $request, DigitalFile $file): RedirectResponse
    {
        $file->load('instantiation.record');
        $this->own($request, $file->instantiation, 'update');
        $intact = $this->instantiations->checkFixity($file, $request->user());

        return redirect()->route('instantiations.show', $file->instantiation)->with('status', $intact
            ? __('Fixity checked: :file is unchanged.', ['file' => $file->original_name])
            : __('Fixity check failed: :file is missing or no longer matches its SHA-256. Restore it from a backup.', ['file' => $file->original_name]));
    }

    /** Another institution's instantiation, or one deleted with its unit, does not exist here (404); without the permission, 403. */
    private function own(Request $request, Instantiation $instantiation, string $action = 'read'): RecordResource
    {
        abort_unless($instantiation->institution_id === $request->user()->institution_id && $instantiation->deleted_at === null, 404);

        return $this->unit($request, $instantiation->record, $action);
    }

    private function unit(Request $request, RecordResource $unit, string $action): RecordResource
    {
        abort_unless($unit->institution_id === $request->user()->institution_id && $unit->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'description', $unit), 403);

        return $unit;
    }

    private function choices(string $vocabulary)
    {
        return Concept::choices($vocabulary)->get();
    }
}
