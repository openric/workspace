{{-- OpenRiC Workspace - the version history of a unit of description or an authority record: versions, one shown, two compared, restore (Heratio's ahg-version-control; roadmap phase 22b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Versions of :title', ['title' => \App\Relations\EntityKinds::label($entity)]))
@section('content')
    @php($tz = config('workspace.timezone'))
    <p><a href="{{ $kind === 'records' ? route('records.show', $entity) : route('agents.show', $entity) }}">{{ \App\Relations\EntityKinds::label($entity) }}</a></p>
    <h1>{{ __('Versions of :title', ['title' => \App\Relations\EntityKinds::label($entity)]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A version is written whenever what it says changes: its values, names, dates, identifiers and relations. Restoring puts back the values and names a version had; what changes goes back to draft, for review.') }}</p>
    @if($versions->isEmpty())
        <p>{{ __('No version yet: one is written the next time it changes.') }}</p>
    @else
        <form method="get" action="{{ route('versions.index', [$kind, $entity->id]) }}">
            <table><thead><tr><th scope="col">{{ __('Version') }}</th><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('By') }}</th><th scope="col">{{ __('What changed') }}</th><th scope="col">{{ __('Compare') }}</th><th scope="col"></th></tr></thead>
                <tbody>@foreach($versions as $v)<tr><td><a href="{{ route('versions.index', [$kind, $entity->id, 'show' => $v->version_number]) }}">{{ $v->version_number }}</a>@if($v->is_restore) <span class="meta">({{ __('restored :n', ['n' => $v->restored_from_version]) }})</span>@endif</td>
                    <td>{{ $v->created_at->timezone($tz)->format('Y-m-d H:i') }}</td><td>{{ $v->createdBy?->name ?? '-' }}</td>
                    <td class="meta">{{ $v->change_summary ? $v->change_summary.' · ' : '' }}{{ $v->changed_fields === null ? __('first version') : implode(', ', array_slice($v->changed_fields, 0, 6)).(count($v->changed_fields) > 6 ? ' …' : '') }}</td>
                    <td><label class="visually-hidden" for="f{{ $v->version_number }}">{{ __('From version :n', ['n' => $v->version_number]) }}</label><input id="f{{ $v->version_number }}" type="radio" name="from" value="{{ $v->version_number }}" @checked((int) request('from', $versions->get(1)?->version_number) === $v->version_number)>
                        <label class="visually-hidden" for="t{{ $v->version_number }}">{{ __('To version :n', ['n' => $v->version_number]) }}</label><input id="t{{ $v->version_number }}" type="radio" name="to" value="{{ $v->version_number }}" @checked((int) request('to', $versions->first()->version_number) === $v->version_number)></td>
                    <td>@if($mayRestore && ! $loop->first)<button type="submit" form="restore-{{ $v->version_number }}">{{ __('Restore') }}</button>@endif</td></tr>@endforeach</tbody></table>
            @if($versions->count() > 1)<button type="submit">{{ __('Compare the two chosen') }}</button>@endif
        </form>
        @foreach($versions as $v)@if($mayRestore && ! $loop->first)<form id="restore-{{ $v->version_number }}" method="post" action="{{ route('versions.restore', [$kind, $entity->id]) }}" onsubmit="return confirm('{{ __('Restore version :n?', ['n' => $v->version_number]) }}')">@csrf<input type="hidden" name="version" value="{{ $v->version_number }}"></form>@endif @endforeach
    @endif

    @if($compare)
        <h2>{{ __('Version :a compared with :b', ['a' => $compare['from']->version_number, 'b' => $compare['to']->version_number]) }}</h2>
        @if($compare['diff'] === [])<p>{{ __('They say the same.') }}</p>@else
        <table><thead><tr><th scope="col">{{ __('Field') }}</th><th scope="col">{{ __('Version :n', ['n' => $compare['from']->version_number]) }}</th><th scope="col">{{ __('Version :n', ['n' => $compare['to']->version_number]) }}</th></tr></thead>
            <tbody>@foreach($compare['diff'] as $field => $d)<tr><th scope="row"><code>{{ $field }}</code></th><td><del>{!! nl2br(e(implode("\n", $d['before']))) !!}</del></td><td><ins>{!! nl2br(e(implode("\n", $d['after']))) !!}</ins></td></tr>@endforeach</tbody></table>
        @endif
    @endif

    @if($shown)
        <h2>{{ __('Version :n', ['n' => $shown->version_number]) }}</h2>
        @php($s = $shown->snapshot)
        <h3>{{ __('Names') }}</h3><ul>@foreach($s['names'] ?? [] as $n)<li><span class="meta">{{ $n['type'] }} · {{ $n['language'] }}</span> {{ $n['value'] }}</li>@endforeach</ul>
        <h3>{{ __('Values') }}</h3><dl>@foreach($s['values'] ?? [] as $val)<dt>{{ $val['element'] }} <span class="meta">{{ $val['language'] }}</span></dt><dd>{!! nl2br(e($val['value'])) !!}</dd>@endforeach</dl>
        @if($s['dates'] ?? [])<h3>{{ __('Dates') }}</h3><ul>@foreach($s['dates'] as $d)<li>{{ $d['property'] }} {{ $d['normalized'] }} {{ $d['expressed'] }}</li>@endforeach</ul>@endif
        @if($s['identifiers'] ?? [])<h3>{{ __('Identifiers') }}</h3><ul>@foreach($s['identifiers'] as $i)<li>{{ $i['type'] }}: {{ $i['value'] }}</li>@endforeach</ul>@endif
        @if($s['relations'] ?? [])<h3>{{ __('Relations') }}</h3><ul>@foreach($s['relations'] as $r)<li>{{ $r['type'] }} · {{ $r['direction'] }} · <code>{{ $r['with'] }}</code></li>@endforeach</ul>@endif
    @endif
@endsection
