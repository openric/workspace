{{-- OpenRiC Workspace - the file plan and its imports (Heratio's file plan pages; roadmap phase 13c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('File plan'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($byParent = $nodes->groupBy(fn ($n) => $n->parent_id ?? 'top'))
    <h1>{{ __('File plan') }}</h1>
    @include('records-management._nav')
    @if(session('import_errors'))<ul class="error">@foreach(session('import_errors') as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    @php($branch = function ($parent) use (&$branch, $byParent, $lang) {
        $items = $byParent[$parent] ?? collect();
        if ($items->isEmpty()) { return ''; }
        return '<ul>'.$items->map(fn ($n) => '<li><a href="'.e(route('records-management.node', $n)).'">'.e($n->code).' '.e($n->title).'</a> <span class="meta">'.e($n->nodeType->label($lang))
            .($n->activity ? ' · '.e(\App\Relations\EntityKinds::label($n->activity)) : '').($n->disposalClass ? ' · '.e($n->disposalClass->reference) : '')
            .' · '.e(trans_choice(':count record|:count records', $n->placements_count, ['count' => $n->placements_count])).($n->status->notation !== 'active' ? ' · '.e($n->status->label($lang)) : '').'</span>'.$branch($n->id).'</li>')->implode('').'</ul>';
    })
    @if($nodes->isEmpty())<p>{{ __('The file plan is empty. Add its functions, activities and series, or import it.') }}</p>@else{!! $branch('top') !!}@endif
    <details><summary>{{ __('Add a node') }}</summary><form method="post" action="{{ route('records-management.nodes.store') }}">@csrf @include('records-management._node-fields', ['n' => null, 'x' => 'nn'])<button type="submit">{{ __('Add') }}</button></form></details>
    <h2>{{ __('Import a file plan') }}</h2>
    <form method="post" action="{{ route('records-management.import') }}" enctype="multipart/form-data">@csrf
        <label for="i-source">{{ __('From') }}</label> <select id="i-source" name="source"><option value="spreadsheet">{{ __('A spreadsheet (CSV or Excel), one node a row, with a code and a title column') }}</option>
            <option value="directory">{{ __('A listing of folders, one path a line') }}</option><option value="xml">{{ __('XML: EAD, or elements with a code and title') }}</option></select>
        <label for="i-file">{{ __('File') }}</label> <input id="i-file" name="file" type="file" required><br>
        <label for="i-type">{{ __('Kind of node') }}</label> @include('projects._select', ['id' => 'i-type', 'name' => 'node_type', 'choices' => $types, 'current' => null, 'default' => 'series'])
        <label for="i-dept">{{ __('Department') }}</label> <input id="i-dept" name="department" size="20"> <label for="i-agency">{{ __('Agency code') }}</label> <input id="i-agency" name="agency_code" size="10"><br>
        <input type="hidden" name="place_records" value="0"><label><input type="checkbox" name="place_records" value="1"> {{ __('Place records whose reference code begins with a node\'s code') }}</label>
        <button type="submit">{{ __('Import') }}</button></form>
    @if($imports->isNotEmpty())<h3>{{ __('Imports') }}</h3><ul class="meta">@foreach($imports as $i)<li>{{ $i->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }} · {{ $i->source->label($lang) }} · {{ $i->source_filename }} · {{ $i->status->label($lang) }} · {{ __(':n of :total nodes; :placed records placed', ['n' => $i->imported_nodes, 'total' => $i->total_nodes, 'placed' => $i->linked_records]) }} · {{ $i->importedBy?->name }}</li>@endforeach</ul>@endif
@endsection
