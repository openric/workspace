{{-- OpenRiC Workspace - files that failed fixity (Heratio's ahg-integrity dead letter; roadmap phase 12a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Fixity failures'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    <h1>{{ __('Fixity failures') }}</h1>
    <p><a href="{{ route('fixity.schedules') }}">{{ __('Fixity') }}</a> · <a href="{{ route('fixity.failures') }}">{{ __('Open') }}</a>@foreach($statuses->whereNotIn('notation', \App\Models\FixityFailure::OPEN) as $s) · <a href="{{ route('fixity.failures', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endforeach</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A file whose size or SHA-256 differs from what came in, or that is not in the object store, is no longer what was received: restore it from a backup and check it again. It closes by itself when it passes.') }}</p>
    @forelse($failures as $f)
        <section style="border-top: 1px solid var(--line); padding: .5rem 0">
            <h2 style="font-size: 1rem; margin: .2rem 0">@if($f->file)<a href="{{ route('instantiations.show', $f->file->instantiation_id) }}">{{ $f->file->original_name }}</a> ({{ $f->file->use->label($lang) }}) · {{ \App\Relations\EntityKinds::label($f->file->instantiation->record) }}@else<code>{{ $f->digital_file_id }}</code>@endif</h2>
            <p><strong>{{ $f->outcome->label($lang) }}</strong> · {{ $f->status->label($lang) }} · {{ trans_choice(':count failed check|:count failed checks in a row', $f->consecutive_failures, ['count' => $f->consecutive_failures]) }}
                · {{ __('first :first, last :last', ['first' => $f->first_failed_at->timezone($tz)->format('Y-m-d H:i'), 'last' => $f->last_failed_at->timezone($tz)->format('Y-m-d H:i')]) }}
                @if($f->acknowledgedBy) · {{ __('acknowledged by :name', ['name' => $f->acknowledgedBy->name]) }}@endif</p>
            @if($f->resolution_notes)<p>{{ $f->resolution_notes }}</p>@endif
            @if(in_array($f->status->notation, \App\Models\FixityFailure::OPEN, true))
                <form method="post" action="{{ route('fixity.failures.update', $f) }}" style="display: flex; gap: .5rem; flex-wrap: wrap; align-items: end">@csrf
                    <div><label for="f{{ $loop->index }}-status" style="margin-top: 0">{{ __('Status') }}</label><select id="f{{ $loop->index }}-status" name="status">@foreach($statuses->where('notation', '!=', 'open') as $s)<option value="{{ $s->notation }}">{{ $s->label($lang) }}</option>@endforeach</select></div>
                    <div><label for="f{{ $loop->index }}-notes" style="margin-top: 0">{{ __('Notes') }}</label><input id="f{{ $loop->index }}-notes" name="notes" maxlength="2000"></div>
                    <button type="submit" style="margin-top: 0">{{ __('Save') }}</button></form>
            @endif
        </section>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
@endsection
