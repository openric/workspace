<?php

// OpenRiC Workspace - institution settings: its name, the header text, the logo, and the public catalogue's introduction and credits.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Changing them needs manage-institution, and every change is audited through
// the Institution model. A logo may be PNG, JPEG or WebP only: SVG can carry
// script. It is kept on the private disk and served by logo(), never as a
// static file, with its own media type.

namespace App\Http\Controllers;

use App\Audit\AuditLog;
use App\Models\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.edit', ['institution' => $request->user()->institution]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:500'],
            'header_title' => ['nullable', 'string', 'max:255'],
            'catalogue_introduction' => ['nullable', 'string', 'max:5000'],
            'catalogue_credits' => ['nullable', 'string', 'max:5000'],
            'registration_open' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024', 'dimensions:max_width=4000,max_height=4000'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        $institution = $request->user()->institution;
        $disk = Storage::disk('local');
        $old = $institution->logo_path;
        $blank = fn (string $key) => trim((string) ($data[$key] ?? '')) === '' ? null : trim((string) $data[$key]);
        $changes = ['name' => $data['name'], 'header_title' => $blank('header_title'),
            'catalogue_introduction' => $blank('catalogue_introduction'), 'catalogue_credits' => $blank('catalogue_credits'),
            'registration_open' => $request->boolean('registration_open')];
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            // A new name each time, so no cache shows the old logo.
            $changes['logo_path'] = $disk->putFileAs('branding', $file, $institution->id.'-'.Str::random(12).'.'.$file->extension());
            $changes['logo_mime'] = $file->getMimeType();
        } elseif ($request->boolean('remove_logo')) {
            $changes['logo_path'] = null;
            $changes['logo_mime'] = null;
        }
        try {
            DB::transaction(fn () => AuditLog::actingAs($request->user(), fn () => $institution->update($changes)));
        } catch (\Throwable $e) {
            if (isset($changes['logo_path'])) {
                $disk->delete($changes['logo_path']);
            }
            throw $e;
        }
        // Remove the replaced file only once the change is stored.
        if ($old && array_key_exists('logo_path', $changes) && $old !== $changes['logo_path']) {
            $disk->delete($old);
        }

        return redirect()->route('settings.edit')->with('status', __('Settings saved.'));
    }

    /** Public: the sign-in page shows the logo too. */
    public function logo(): Response
    {
        $institution = Institution::current();
        abort_unless($institution?->logo_path && Storage::disk('local')->exists($institution->logo_path), 404);

        return response(Storage::disk('local')->get($institution->logo_path), 200, [
            'Content-Type' => $institution->logo_mime,
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
