{{-- OpenRiC Workspace - the file formats kept, as PRONOM identifies them (Heratio's ahg-preservation format registry; roadmap phase 15a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('File formats'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('File formats') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('The formats of the files the institution keeps, as PRONOM identifies them. Each file is identified by Siegfried when it is kept; each identification, like every check and change of a file, is a PREMIS event on its instantiation\'s page.') }}</p>
    <p>{{ trans_choice(':count file not yet identified.|:count files not yet identified.', $unidentified, ['count' => $unidentified]) }}
        @if($unidentified)<form method="post" action="{{ route('formats.identify') }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0">{{ __('Identify them now') }}</button></form>@endif</p>
    <table><thead><tr><th scope="col">{{ __('Format') }}</th><th scope="col">PUID</th><th scope="col">{{ __('Files') }}</th><th scope="col">{{ __('Size') }}</th><th scope="col">{{ __('Risk') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead>
        <tbody>@forelse($formats as $f)<tr><td>{{ $f->name }}@if($f->version) {{ $f->version }}@endif @if($f->is_preservation_format)<br><small>{{ __('a preservation format') }}</small>@endif</td>
            <td><a href="{{ $f->pronomUrl() }}" rel="noopener noreferrer">{{ $f->puid }}</a></td><td>{{ number_format($counts[$f->id]->n) }}</td><td>{{ \Illuminate\Support\Number::fileSize((int) $counts[$f->id]->bytes, 1) }}</td>
            @if($operator)
                <td colspan="2"><form method="post" action="{{ route('formats.update', $f) }}" style="display: flex; gap: .4rem; flex-wrap: wrap; align-items: end">@csrf
                    <select name="risk" aria-label="{{ __('Risk') }}"><option value="">{{ __('Not rated') }}</option>@foreach($risks as $r)<option value="{{ $r->notation }}" @selected($f->risk?->id === $r->id)>{{ $r->label($lang) }}</option>@endforeach</select>
                    <select name="action" aria-label="{{ __('Action') }}"><option value="">{{ __('Not set') }}</option>@foreach($actions as $a)<option value="{{ $a->notation }}" @selected($f->action?->id === $a->id)>{{ $a->label($lang) }}</option>@endforeach</select>
                    <label style="margin: 0"><input type="checkbox" name="is_preservation_format" value="1" @checked($f->is_preservation_format)> {{ __('Preservation format') }}</label>
                    <select name="migration_target_id" aria-label="{{ __('Migrate to') }}"><option value="">{{ __('No migration target') }}</option>@foreach($targets->where('id', '!=', $f->id) as $t)<option value="{{ $t->id }}" @selected($f->migration_target_id === $t->id)>{{ $t->puid }} {{ $t->name }}</option>@endforeach</select>
                    <input name="risk_notes" value="{{ $f->risk_notes }}" aria-label="{{ __('Why this risk') }}" placeholder="{{ __('Why this risk') }}">
                    <button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Save') }}</button></form></td>
            @else
                <td>{{ $f->risk?->label($lang) ?? __('not rated') }}@if($f->risk_notes)<br><small>{{ $f->risk_notes }}</small>@endif</td><td>{{ $f->action?->label($lang) ?? __('not set') }}@if($f->migrationTarget)<br><small>{{ __('to :format', ['format' => $f->migrationTarget->puid.' '.$f->migrationTarget->name]) }}</small>@endif</td>
            @endif</tr>
            @if($p = $pathways->get($f->puid)?->first())<tr><td colspan="6"><small>{{ __('Migration pathway: to :target by :tool', ['target' => $p->target_puid, 'tool' => $p->tool->label($lang)]) }}@if($p->is_recommended) ({{ __('recommended') }})@endif · <a href="{{ route('preservation.index') }}#plans">{{ __('Plan a migration') }}</a></small></td></tr>@endif
        @empty<tr><td colspan="6">{{ __('None identified yet.') }}</td></tr>@endforelse</tbody></table>
    @unless($operator)<p class="meta">{{ __('A format\'s risk and action are set by the installation\'s operators, as they hold for every institution on it.') }}</p>@endunless
@endsection
