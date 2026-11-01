<?php

// OpenRiC Workspace - share links (roadmap phase 19a, ADR 0017), after Heratio's ShareLinkIssueController,
// ShareLinkAdminController and its recipient view (ahg-share-link): issue a link on a record's page, list and revoke
// links, and open a record by its link.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\Elements;
use App\Description\InstantiationService;
use App\Files\FileResponse;
use App\Models\DigitalFile;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ShareLink;
use App\Research\ShareLinks;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ShareLinkController extends Controller
{
    public function __construct(private ShareLinks $links) {}

    /** The institution's links for an administrator; one's own for anyone else who issues them. */
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->mayAnywhere('update', 'description'), 403);
        $all = $user->hasPermission(Vocab::permission('manage-institution'));
        $links = ShareLink::where('institution_id', $user->institution_id)->when(! $all, fn ($q) => $q->where('issued_by_id', $user->id))
            ->with('record', 'issuedBy', 'revokedBy')->withCount('events')->orderByDesc('created_at')->get();

        return view('share-links.index', ['links' => $links, 'all' => $all]);
    }

    public function issue(Request $request, RecordResource $record): RedirectResponse
    {
        $data = $request->validate(['recipient_email' => ['nullable', 'string', 'max:255'], 'recipient_note' => ['nullable', 'string', 'max:500'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'], 'max_access' => ['nullable', 'integer', 'min:1', 'max:100000'], 'allows_download' => ['nullable', 'boolean']]);
        try {
            [, $token] = $this->links->issue($request->user(), $record, $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['share_link' => $e->getMessage()]);
        }

        return redirect(route('records.show', $record).'#share-links')->with('status', __('The link is made. Copy it now; it is not shown again:'))
            ->with('share_link', route('share-links.open', $token));
    }

    public function revoke(Request $request, ShareLink $link): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        try {
            $this->links->revoke($request->user(), $link, $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['share_link' => $e->getMessage()]);
        }

        return back()->with('status', __('The link is revoked.'));
    }

    /** The record, for whoever holds the link. */
    public function open(Request $request, string $token): View|Response
    {
        [$link, $refusal] = $this->links->open($token, $request->ip(), $request->userAgent());
        if ($link === null) {
            return response()->view('share-links.refused', ['refusal' => $refusal], $refusal === 'not-found' ? 404 : 410);
        }
        $view = $this->links->view($link);
        $unit = $link->record->load('instantiations.files.use', 'instantiations.names.nameType', 'instantiations.names.state', 'dates', 'level.labels');

        return view('share-links.record', ['institution' => Institution::find($link->institution_id), 'link' => $link, 'token' => $token, 'unit' => $unit, 'view' => $view, 'elements' => Elements::of(Elements::ISAD_G),
            'files' => $unit->instantiations->flatMap(fn ($i) => $i->files->map(fn ($f) => $f->setRelation('instantiation', $i)))->filter(fn ($f) => $this->links->deliverable($link, $f))->values()]);
    }

    public function file(Request $request, string $token, DigitalFile $file): Response
    {
        [$link, $refusal] = $this->links->open($token, $request->ip(), $request->userAgent(), 'download');
        abort_if($link === null, $refusal === 'not-found' ? 404 : 410);
        $file->load('instantiation.record', 'instantiation.availability', 'use');
        abort_unless($this->links->deliverable($link, $file), 404);
        $path = app(InstantiationService::class)->path($file);
        abort_unless(is_file($path), 404);
        $this->links->downloaded($link, $request->ip(), $request->userAgent());

        return FileResponse::make($file, $path, true);
    }
}
