{{-- OpenRiC Workspace - offline catalogues (roadmap phase 11c), after Heratio's portable export page: the packages made, a new one, and links to them. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Offline catalogues'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->timezone(config('workspace.timezone'))->format('Y-m-d H:i') : '')
    <h1>{{ __('Offline catalogues') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if(session('link'))
        <div role="status"><p><strong>{{ __('The link, shown this once:') }}</strong></p><p><input readonly value="{{ session('link') }}" aria-label="{{ __('The link') }}" style="width:100%"></p>
            <p><small>{{ __('Copy it now. Only its hash is kept, so it cannot be shown again; revoke it and make another if it is lost.') }}</small></p></div>
    @endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A package of descriptions, authority records and files with a viewer that opens from a USB stick or a reading-room computer, with no internet or server. It carries the RiC-O of every description, the checksum of every file and a page to check them, what it withheld and why, and what it leaves out.') }}</p>

    <h2>{{ __('Make one') }}</h2>
    <form method="post" action="{{ route('offline-catalogues.store') }}">@csrf
        <label for="oc-title">{{ __('Title') }}</label>
        <input id="oc-title" name="title" required maxlength="255" value="{{ old('title', $unit ? $unit->described('title', ['en']) : '') }}">
        <fieldset><legend>{{ __('What it holds') }}</legend>
            <p><label><input type="radio" name="scope" value="everything" @checked(old('scope', $unit ? 'unit' : 'everything') === 'everything')> {{ __('Every description') }}</label></p>
            <p><label><input type="radio" name="scope" value="repository" @checked(old('scope') === 'repository')> {{ __('One repository') }}</label>
                <label for="oc-repository" class="visually-hidden">{{ __('Repository') }}</label>
                <select id="oc-repository" name="repository_id"><option value="">{{ __('Choose') }}</option>
                    @foreach($repositories as $r)<option value="{{ $r->id }}" @selected(old('repository_id') === $r->id)>{{ $r->described('authorised-name', ['en']) }}</option>@endforeach</select></p>
            @if($unit)
                <p><label><input type="radio" name="scope" value="unit" @checked(old('scope', 'unit') === 'unit')> {{ __(':title and everything inside it', ['title' => $unit->described('title', ['en'])]) }}</label>
                    <input type="hidden" name="record_resource_id" value="{{ $unit->id }}"></p>
            @endif
            <p><label><input type="radio" name="scope" value="clipboard" @checked(old('scope') === 'clipboard') @disabled($clipboard === 0)> {{ trans_choice('{0} The clipboard (it holds no descriptions)|{1} The description on the clipboard|[2,*] The :count descriptions on the clipboard', $clipboard, ['count' => $clipboard]) }}</label></p>
            @unless($unit)<p><small>{{ __('For one unit and everything inside it, use Offline catalogue on the unit\'s export page.') }}</small></p>@endunless
        </fieldset>
        <fieldset><legend>{{ __('What it is for') }}</legend>
            @foreach($modes as $mode)
                <p><label><input type="radio" name="mode" value="{{ $mode->notation }}" @checked(old('mode', 'read-only') === $mode->notation)> <strong>{{ $mode->label($lang) }}</strong></label><br>
                    <small>{{ $mode->labels->firstWhere('language', app()->getLocale())?->definition ?? $mode->labels->firstWhere('language', 'en')?->definition }}</small></p>
            @endforeach
            <p><small>{{ __('Read-only and editable packages hold what the public may read and never a master. An archive holds what you may read, and is never given a link.') }}</small></p>
        </fieldset>
        <label for="oc-language">{{ __('Language') }}</label>
        <input id="oc-language" name="language" value="{{ old('language') }}" placeholder="{{ __('Each description\'s original language') }}" aria-describedby="oc-language-help">
        <small id="oc-language-help">{{ __('A BCP 47 tag such as en or af; a value not written in it is given in the language it was first written in.') }}</small>
        <input type="hidden" name="include_files" value="0">
        <p><label><input type="checkbox" name="include_files" value="1" @checked(old('include_files', '1') === '1')> {{ __('Include files (thumbnails and reference copies)') }}</label></p>
        @if($mayMasters)
            <input type="hidden" name="include_masters" value="0">
            <p><label><input type="checkbox" name="include_masters" value="1" @checked(old('include_masters') === '1')> {{ __('Include masters (an archive only)') }}</label></p>
        @endif
        <button type="submit">{{ __('Make the offline catalogue') }}</button>
    </form>

    <h2>{{ __('Made') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Mode') }}</th><th scope="col">{{ __('Holds') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Package') }}</th></tr></thead>
        <tbody>@forelse($catalogues as $c)
            <tr><td>{{ $c->title }}<br><small>{{ $when($c->created_at) }}{{ $c->user ? ' · '.$c->user->name : '' }}</small></td>
                <td>{{ $c->mode->label($lang) }}</td>
                <td>{{ $c->record ? $c->record->described('title', ['en']) : ($c->repository ? $c->repository->described('authorised-name', ['en']) : (is_array($c->scope_items) ? __('The clipboard') : __('Every description'))) }}
                    @if($c->status->notation === 'completed')<br><small>{{ __(':records descriptions, :agents authority records, :files files', ['records' => $c->record_count, 'agents' => $c->agent_count, 'files' => $c->file_count]) }}@if($c->withheld_count) · {{ __(':count left out', ['count' => $c->withheld_count]) }}@endif</small>@endif</td>
                <td>{{ $c->status->label($lang) }}@if($c->error)<br><small>{{ \Illuminate\Support\Str::limit($c->error, 200) }}</small>@endif</td>
                <td>
                    @if($c->status->notation === 'completed')
                        <a href="{{ route('offline-catalogues.download', $c) }}">{{ __('Download') }}</a> · {{ \Illuminate\Support\Number::fileSize((int) $c->size_bytes, 1) }}
                        <br><small>SHA-256 <code>{{ \Illuminate\Support\Str::limit($c->sha256, 16, '') }}</code></small>
                        @if($c->mode->notation !== 'archive')
                            <details><summary>{{ __('Links') }} ({{ $c->shares->whereNull('revoked_at')->count() }})</summary>
                                <ul>@foreach($c->shares as $s)<li>{{ $s->label ?: __('Link') }}: {{ trans_choice('{0} not downloaded|{1} downloaded once|[2,*] downloaded :count times', $s->download_count, ['count' => $s->download_count]) }}{{ $s->max_downloads ? ' '.__('of :max', ['max' => $s->max_downloads]) : '' }}{{ $s->expires_at ? ', '.__('until :date', ['date' => $s->expires_at->format('Y-m-d')]) : '' }}
                                    @if($s->open())<form method="post" action="{{ route('offline-catalogues.revoke', [$c, $s]) }}" style="display:inline">@csrf<button type="submit">{{ __('Revoke') }}</button></form>@else ({{ __('no longer opens') }})@endif</li>@endforeach</ul>
                                <form method="post" action="{{ route('offline-catalogues.share', $c) }}">@csrf
                                    <label for="l-{{ $c->id }}">{{ __('For whom (optional)') }}</label><input id="l-{{ $c->id }}" name="label" maxlength="255">
                                    <label for="e-{{ $c->id }}">{{ __('Opens until (optional)') }}</label><input id="e-{{ $c->id }}" name="expires_at" type="date">
                                    <label for="m-{{ $c->id }}">{{ __('Number of downloads (optional)') }}</label><input id="m-{{ $c->id }}" name="max_downloads" type="number" min="1" max="10000">
                                    <button type="submit">{{ __('Make a link') }}</button></form>
                            </details>
                        @endif
                    @endif
                    @if($c->status->notation !== 'running')
                        <form method="post" action="{{ route('offline-catalogues.destroy', $c) }}" onsubmit="return confirm('{{ __('Delete this offline catalogue and its links?') }}')">@csrf<button type="submit">{{ __('Delete') }}</button></form>
                    @endif
                </td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
