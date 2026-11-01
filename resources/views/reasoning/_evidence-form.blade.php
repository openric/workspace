{{-- OpenRiC Workspace - adding a piece of evidence to a claim or hypothesis (roadmap phase 19i). Needs $action, $x, $available, $sources, $relationships. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<details><summary>{{ __('Add evidence') }}</summary>
    <form method="post" action="{{ $action }}">@csrf
        <label for="{{ $x }}-rel">{{ __('It') }}</label> <select id="{{ $x }}-rel" name="relationship">@foreach($relationships as $r)<option value="{{ $r->notation }}">{{ $r->label($lang) }}</option>@endforeach</select>
        <label for="{{ $x }}-type">{{ __('Kind') }}</label> <select id="{{ $x }}-type" name="source_type">@foreach($sources as $s)<option value="{{ $s->notation }}">{{ $s->label($lang) }}</option>@endforeach</select><br>
        <label for="{{ $x }}-iri">{{ __('A record (its persistent IRI)') }}</label> <input id="{{ $x }}-iri" name="iri" size="40"><br>
        <label for="{{ $x }}-id">{{ __('Or one of the project\'s') }}</label> <select id="{{ $x }}-id" name="source_id"><option value="">{{ __('None') }}</option>
            @foreach($available as $kind => $list)@foreach($list as $a)<option value="{{ $a->id }}">{{ $sources->firstWhere('notation', $kind)?->label($lang) }}: {{ \Illuminate\Support\Str::limit((string) ($a->name ?? $a->title ?? $a->reference_code ?? $a->object_value ?? $a->content), 70) }}</option>@endforeach @endforeach
            @foreach($available['bibliography'] as $b)@foreach($b->entries as $entry)<option value="{{ $entry->id }}">{{ $sources->firstWhere('notation', 'bibliography-entry')?->label($lang) }}: {{ \Illuminate\Support\Str::limit(trim($entry->authors.' '.$entry->title), 70) }}</option>@endforeach @endforeach</select><br>
        @isset($confidence)<label for="{{ $x }}-conf">{{ __('Confidence (0 to 1)') }}</label> <input id="{{ $x }}-conf" name="confidence" type="number" min="0" max="1" step="0.01" style="width: 5rem">@endisset
        <label for="{{ $x }}-note">{{ __('Note') }}</label> <input id="{{ $x }}-note" name="note" size="50"> <button type="submit">{{ __('Add') }}</button></form>
</details>
