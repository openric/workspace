{{-- OpenRiC Workspace - the permission matrix of one kind. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Permissions')
@section('content')
    <p><a href="{{ $back }}">Back to {{ $holderName }}</a></p>
    <h1>{{ $holderName }}: {{ $kind->label(\App\Support\Vocab::languages()) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>Grant allows the action, Deny refuses it, Inherit leaves it to the role above. A rule for one unit or repository comes before a rule for everything, and a denial wins over a grant at the same level.</p>

    @php($sections = [['all', 'Everything', 'all', $cells['all']]])
    @foreach($shownRepositories as $repository)
        @php($sections[] = ['repository:'.$repository->id, 'In the repository '.$repository->described('authorised-name', ['en']), 'repository', $cells['repositories'][$repository->id] ?? []])
    @endforeach
    @foreach($shownRecords as $record)
        @php($sections[] = ['record:'.$record->id, 'For '.$record->described('title', ['en']).' and everything inside it', 'record', $cells['records'][$record->id] ?? []])
    @endforeach

    <form method="post" action="{{ $action }}">
        @csrf
        @foreach($sections as [$scope, $heading, $type, $set])
            <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem; overflow-x: auto">
                <legend><strong>{{ $heading }}</strong></legend>
                <table>
                    <thead><tr><th scope="col">Action</th><th scope="col">Grant</th><th scope="col">Deny</th><th scope="col">Inherit</th></tr></thead>
                    <tbody>
                    @foreach($actions as $act)
                        @php($cell = $set[$act->notation] ?? null)
                        @php($current = $cell ? ($cell->is_grant ? 'grant' : 'deny') : 'inherit')
                        @php($name = 'cell['.$act->notation.'|'.$scope.']')
                        <tr>
                            <th scope="row" style="font-weight: normal">{{ $act->label(\App\Support\Vocab::languages()) }}<br><small>{{ $act->labels->firstWhere('language', 'en')?->definition }}</small></th>
                            @foreach(['grant', 'deny', 'inherit'] as $choice)
                                <td><input type="radio" name="{{ $name }}" value="{{ $choice }}" aria-label="{{ ucfirst($choice) }} {{ $act->label(\App\Support\Vocab::languages()) }}, {{ $heading }}" style="width: auto" @checked($current === $choice)></td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </fieldset>
        @endforeach
        <button type="submit">Save permissions</button>
    </form>

    @if($repositories->isNotEmpty())
        <form method="get" action="" style="margin-top: 2rem; display: flex; gap: .5rem; align-items: end; flex-wrap: wrap">
            <div><label for="add-repository">Add rules for one repository</label>
            <select id="add-repository" name="repository" style="font: inherit; padding: .5rem">
                @foreach($repositories as $repository)<option value="{{ $repository->id }}">{{ $repository->described('authorised-name', ['en']) }}</option>@endforeach
            </select></div>
            <button type="submit">Add</button>
        </form>
    @endif
    @if($records->isNotEmpty())
        <form method="get" action="" style="margin-top: 1rem; display: flex; gap: .5rem; align-items: end; flex-wrap: wrap">
            <div><label for="add-record">Add rules for one unit and everything inside it</label>
            <select id="add-record" name="record" style="font: inherit; padding: .5rem; max-width: 100%">
                @foreach($records as $record)<option value="{{ $record->id }}">{{ $record->described('title', ['en']) }}@if($record->identifier) ({{ $record->identifier }})@endif</option>@endforeach
            </select></div>
            <button type="submit">Add</button>
        </form>
    @endif
@endsection
