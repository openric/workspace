{{-- OpenRiC Workspace - map a spreadsheet's headings to the columns the import reads (PSIS's ingest map step; roadmap phase 22a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Map the columns of :name', ['name' => $import->file_name]))
@section('content')
    <p><a href="{{ route('imports.index') }}">{{ __('Import') }}</a> · <a href="{{ route('imports.show', $import) }}">{{ $import->file_name }}</a></p>
    <h1>{{ __('Map the columns of :name', ['name' => $import->file_name]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('Each heading of the file is offered the column it most likely is. Change any, leave out what the Workspace should not read, give a value for empty cells, and the dry run follows. Two headings mapped to one column are joined with "|".') }}</p>
    @if($profiles->isNotEmpty())
        <form method="get" action="{{ route('imports.mapping', $import) }}"><label for="profile">{{ __('Use a mapping kept before') }}</label>
            <select id="profile" name="profile">@foreach($profiles as $p)<option value="{{ $p->id }}" @selected($profile?->id === $p->id)>{{ $p->name }}</option>@endforeach</select> <button type="submit">{{ __('Use it') }}</button></form>
    @endif
    @php($kept = $profile?->columns ?? ($import->options['mapping'] ?? []))
    <form method="post" action="{{ route('imports.mapping.save', $import) }}">@csrf
        <table><thead><tr><th scope="col">{{ __('Heading in the file') }}</th><th scope="col">{{ __('First values') }}</th><th scope="col">{{ __('Column') }}</th><th scope="col">{{ __('Value when empty') }}</th><th scope="col">{{ __('Transform') }}</th></tr></thead>
            <tbody>@foreach($headings as $i => $heading)
                @php($target = old("columns.$i.target", $kept[$heading]['target'] ?? $suggested[$heading]['target']))
                <tr><th scope="row">{{ $heading }}@if(! isset($kept[$heading])) <span class="meta">({{ ['exact' => __('sure'), 'close' => __('likely'), 'none' => __('no match')][$suggested[$heading]['confidence']] }})</span>@endif</th>
                    <td class="meta">{{ implode(' · ', $samples[$i] ?? []) }}</td>
                    <td><label class="visually-hidden" for="t-{{ $i }}">{{ __('Column for :heading', ['heading' => $heading]) }}</label><select id="t-{{ $i }}" name="columns[{{ $i }}][target]"><option value="">{{ __('Leave out') }}</option>@foreach($targets as $t)<option value="{{ $t }}" @selected($target === $t)>{{ $t }}</option>@endforeach</select></td>
                    <td><label class="visually-hidden" for="d-{{ $i }}">{{ __('Value when empty for :heading', ['heading' => $heading]) }}</label><input id="d-{{ $i }}" name="columns[{{ $i }}][default]" size="14" value="{{ old("columns.$i.default", $kept[$heading]['default'] ?? '') }}"></td>
                    <td><label class="visually-hidden" for="x-{{ $i }}">{{ __('Transform for :heading', ['heading' => $heading]) }}</label><select id="x-{{ $i }}" name="columns[{{ $i }}][transform]"><option value="">{{ __('None') }}</option>@foreach(\App\Ingest\ColumnMapping::TRANSFORMS as $x)<option value="{{ $x }}" @selected(old("columns.$i.transform", $kept[$heading]['transform'] ?? '') === $x)>{{ __(['trim' => 'Trim spaces', 'uppercase' => 'Upper case', 'lowercase' => 'Lower case', 'titlecase' => 'Title case', 'date-iso' => 'Date as YYYY-MM-DD', 'strip-html' => 'Remove HTML'][$x]) }}</option>@endforeach</select></td></tr>
            @endforeach</tbody></table>
        <p><label for="keep_as">{{ __('Keep this mapping as') }}</label> <input id="keep_as" name="keep_as" size="30" value="{{ old('keep_as', $profile?->name) }}"> <span class="meta">{{ __('Optional: to use it again for files from the same place.') }}</span></p>
        <button type="submit">{{ __('Map and make a dry run') }}</button></form>
@endsection
