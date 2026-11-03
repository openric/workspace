{{-- OpenRiC Workspace - the institution's own fields and form templates (Heratio's ahg-custom-fields admin and ahg-forms; roadmap phase 22b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Own fields and form templates'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($kinds = ['record_resources' => __('Archival descriptions'), 'agents' => __('Authority records'), 'repositories' => __('Repositories'), 'accessions' => __('Accessions'), 'activities' => __('Activities')])
    <h1>{{ __('Own fields and form templates') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Fields the institution adds to what it describes, beyond the standards, and form templates that show only the fields a kind of work needs. RiC-O has no term for an institution\'s own field: its values are kept, searched and exported in CSV, and an RDF export lists them as not carried.') }}</p>
    <h2>{{ __('Own fields') }}</h2>
    @foreach($kinds as $table => $title)
        <h3>{{ $title }}</h3>
        @foreach($definitions[$table] ?? [] as $d)
            <details><summary>{{ $d->label }} <code>{{ $d->field_key }}</code> · {{ $d->fieldType->label($lang) }}@if($d->field_group) · {{ $d->field_group }}@endif @unless($d->is_active) · <strong>{{ __('off') }}</strong>@endunless</summary>
                <form method="post" action="{{ route('custom-fields.definitions.update', $d) }}">@csrf @include('custom-fields._definition-fields', ['d' => $d, 'x' => 'd'.$d->id, 'table' => $table])<button type="submit">{{ __('Save') }}</button></form></details>
        @endforeach
        <details><summary>{{ __('Add a field to these') }}</summary><form method="post" action="{{ route('custom-fields.definitions.store') }}">@csrf @include('custom-fields._definition-fields', ['d' => null, 'x' => 'n'.$table, 'table' => $table])<button type="submit">{{ __('Add') }}</button></form></details>
    @endforeach
    <h2>{{ __('Form templates') }}</h2>
    <ul>@forelse($templates as $t)<li><a href="{{ route('custom-fields.template', $t) }}">{{ $t->name }}</a> <span class="meta">{{ $kinds[$t->entity_table] }} · {{ trans_choice(':count field|:count fields', $t->fields_count, ['count' => $t->fields_count]) }}@if($t->is_default) · {{ __('default') }}@endif @unless($t->is_active) · {{ __('off') }}@endunless</span></li>@empty<li>{{ __('None yet.') }}</li>@endforelse</ul>
    <p>{{ __('New template for') }} @foreach(array_slice($kinds, 0, 3, true) as $table => $title)<a href="{{ route('custom-fields.templates.create', ['for' => $table]) }}">{{ mb_strtolower($title) }}</a>@unless($loop->last) · @endunless @endforeach</p>
@endsection
