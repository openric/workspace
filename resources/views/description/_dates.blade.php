{{-- OpenRiC Workspace - the dates of a described entity, as rico:Date entities (ADR 0006). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs: $subject (HasDates), $kind (URL segment), $heading, $canUpdate, $choices (DateService::choices). --}}
<h2>{{ $heading }} @include('description._ric', ['term' => 'rico:Date'])</h2>
@forelse($subject->dates as $date)
    @php($summary = $date->property->label(['en', 'und']).': '.$date->display().($date->expressed && $date->normalized_value ? ' ('.$date->normalized_value.')' : '').($date->dateType ? ', '.$date->dateType->label(['en', 'und']) : ''))
    @unless($canUpdate)
        <p>{{ $summary }} @include('description._ric', ['term' => $date->property])</p>
        @continue
    @endunless
    <details>
        <summary>{{ $summary }} @include('description._ric', ['term' => $date->property]) <small lang="en">- edit or remove</small></summary>
        <form method="post" action="{{ route('dates.update', [$kind, $subject->getKey(), $date]) }}">
            @csrf
            @include('description._date-fields', ['date' => $date, 'prefix' => 'd'.$loop->index])
            <button type="submit">Save date</button>
        </form>
        <form method="post" action="{{ route('dates.destroy', [$kind, $subject->getKey(), $date]) }}">
            @csrf
            <button type="submit">Remove date</button>
        </form>
        <p><strong>Persistent IRI:</strong> <code>{{ $date->iri }}</code></p>
    </details>
@empty
    <p>None yet.</p>
@endforelse
@if($canUpdate)
<details>
    <summary>Add a date</summary>
    <form method="post" action="{{ route('dates.store', [$kind, $subject->getKey()]) }}">
        @csrf
        @include('description._date-fields', ['date' => null, 'prefix' => 'new'])
        <button type="submit">Add date</button>
    </form>
</details>
@endif
