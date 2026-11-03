{{-- OpenRiC Workspace - enter archival descriptions in a grid under a record set, imported through the dry run (PSIS's grid entry; roadmap phase 22a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Grid entry'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('records.show', $parent) }}">{{ \App\Relations\EntityKinds::label($parent) }}</a> · <a href="{{ route('imports.create', ['parent' => $parent->id]) }}">{{ __('Import a file instead') }}</a></p>
    <h1>{{ __('Grid entry under :title', ['title' => \App\Relations\EntityKinds::label($parent)]) }}</h1>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('Type into the cells, or paste a block of cells copied from a spreadsheet into the first cell, with or without its heading row. The rows go through a dry run first, as an imported file does: nothing is written until you read its report and import.') }}</p>
    <form method="post" action="{{ route('imports.grid.save') }}">@csrf<input type="hidden" name="parent" value="{{ $parent->id }}">
        <p><label for="language">{{ __('Language (BCP 47)') }}</label> <input id="language" name="language" value="{{ old('language', 'en') }}" size="6" required></p>
        @php($terms = ['title' => 'rico:Title', 'identifier' => 'rico:Identifier', 'levelOfDescription' => 'rico:RecordSetType', 'eventDates' => 'rico:Date', 'extentAndMedium' => 'rico:hasExtent', 'scopeAndContent' => 'rico:scopeAndContent', 'subjectAccessPoints' => 'rico:hasOrHadSubject'])
        <table id="grid"><thead><tr>@foreach($columns as $c)<th scope="col">{{ ['title' => __('Title'), 'identifier' => __('Identifier'), 'levelOfDescription' => __('Level of description'), 'eventDates' => __('Dates'), 'extentAndMedium' => __('Extent and medium'), 'scopeAndContent' => __('Scope and content'), 'subjectAccessPoints' => __('Subjects (separate with |)')][$c] }} @include('description._ric', ['term' => $terms[$c]])</th>@endforeach<th scope="col"></th></tr></thead>
            <tbody>@foreach(old('rows', array_fill(0, 5, [])) as $n => $row)<tr>@foreach($columns as $c)<td>
                @if($c === 'levelOfDescription')<select name="rows[{{ $n }}][{{ $c }}]" aria-label="{{ __('Level of description') }}">@foreach($levels as $l)<option value="{{ $l->notation }}" @selected(($row[$c] ?? 'item') === $l->notation)>{{ $l->label($lang) }}</option>@endforeach</select>
                @else<input name="rows[{{ $n }}][{{ $c }}]" value="{{ $row[$c] ?? '' }}" aria-label="{{ $c }}" size="{{ $c === 'scopeAndContent' ? 30 : 14 }}">@endif</td>@endforeach
                <td><button type="button" class="dup">{{ __('Duplicate') }}</button> <button type="button" class="del">{{ __('Delete') }}</button></td></tr>@endforeach</tbody></table>
        <p><button type="button" id="add">{{ __('Add a row') }}</button> <button type="submit">{{ __('Validate and save') }}</button></p></form>
    <script>
    (() => {
        const body = document.querySelector('#grid tbody');
        const renumber = () => body.querySelectorAll('tr').forEach((tr, n) => tr.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(/rows\[\d+\]/, `rows[${n}]`)));
        const blank = tr => { const c = tr.cloneNode(true); c.querySelectorAll('input').forEach(i => i.value = ''); return c; };
        body.addEventListener('click', e => {
            const tr = e.target.closest('tr');
            if (e.target.classList.contains('dup')) { tr.after(tr.cloneNode(true)); renumber(); }
            if (e.target.classList.contains('del') && body.rows.length > 1) { tr.remove(); renumber(); }
        });
        document.getElementById('add').addEventListener('click', () => { body.append(blank(body.rows[body.rows.length - 1])); renumber(); });
        // A block pasted from a spreadsheet fills the grid from the cell it is pasted in, rows and columns.
        body.addEventListener('paste', e => {
            const text = e.clipboardData.getData('text');
            if (!text.includes('\t') && !text.includes('\n')) return;
            e.preventDefault();
            let lines = text.replace(/\r/g, '').split('\n').filter(l => l.trim() !== '');
            if (/^title\b/i.test(lines[0] || '')) lines = lines.slice(1);
            let tr = e.target.closest('tr');
            const start = [...tr.cells].indexOf(e.target.closest('td'));
            lines.forEach((line, i) => {
                if (i > 0) { const next = tr.nextElementSibling || body.appendChild(blank(tr)); tr = next; }
                line.split('\t').forEach((cell, j) => { const field = tr.cells[start + j]?.querySelector('input,select'); if (field) field.value = cell.trim(); });
            });
            renumber();
        });
    })();
    </script>
@endsection
