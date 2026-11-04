{{-- OpenRiC Workspace - preservation planning: normalisation rules, conversions, migration pathways and plans, replication (Heratio's ahg-preservation; roadmap phase 15c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Preservation'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d?->timezone(config('workspace.timezone'))->format('Y-m-d H:i'))
    <h1>{{ __('Preservation') }}</h1>
    <p><a href="{{ route('formats.index') }}">{{ __('File formats') }}</a> · <a href="{{ route('fixity.schedules') }}">{{ __('Fixity') }}</a> · <a href="#rules">{{ __('Normalisation rules') }}</a> · <a href="#conversions">{{ __('Conversions') }}</a> · <a href="#pathways">{{ __('Migration pathways') }}</a> · <a href="#plans">{{ __('Migration plans') }}</a> · <a href="#replication">{{ __('Replication') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A master is kept as it was received. Normalisation makes a preservation master beside it in an open, long-lived format, and an access copy most readers can open; a migration plan carries the files of a format at risk into another. Each is kept in the instantiation\'s OCFL object and is a PREMIS event. Replication copies the whole object store to another place.') }}</p>

    <h2 id="rules">{{ __('Normalisation rules') }}</h2>
    <form method="post" action="{{ route('preservation.settings') }}">@csrf
        <label><input type="checkbox" name="normalise_on_ingest" value="1" @checked($normaliseOnIngest)> {{ __('Normalise each master by these rules when it is kept') }}</label>
        <button type="submit" style="margin-top: 0">{{ __('Save') }}</button></form>
    <p>{{ trans_choice(':count master has no preservation master or access copy.|:count masters have no preservation master or access copy.', $without, ['count' => $without]) }}
        @if($without)<form method="post" action="{{ route('preservation.normalise-all') }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0">{{ __('Normalise them now') }}</button></form>@endif</p>
    <p class="meta">{{ __('Tools on this server:') }} @foreach($installed as $tool => $here){{ $choices['conversion-tool']->firstWhere('notation', $tool)?->label($lang) }} {{ $here ? __('installed') : __('not installed') }}@unless($loop->last), @endunless @endforeach</p>
    <table><thead><tr><th scope="col">{{ __('Files') }}</th><th scope="col">{{ __('Purpose') }}</th><th scope="col">{{ __('Into') }}</th><th scope="col">{{ __('Tool') }}</th><th scope="col">{{ __('Priority') }}</th><th scope="col">{{ __('Active') }}</th></tr></thead>
        <tbody>@forelse($rules as $r)<tr><td>{{ $r->source_puid ?? $r->source_mime }}</td><td>{{ $r->purpose->label($lang) }}</td><td>{{ $r->target_format }} (.{{ $r->target_ext }})</td>
            <td>{{ $r->tool->label($lang) }}</td><td>{{ $r->priority }}</td><td>{{ $r->is_active ? __('yes') : __('no') }}</td></tr>
        @empty<tr><td colspan="6">{{ __('None.') }}</td></tr>@endforelse</tbody></table>
    @if($operator)
        @foreach($rules as $r)
            <details><summary>{{ __('Change') }}: {{ $r->source_puid ?? $r->source_mime }} · {{ $r->purpose->label($lang) }} · {{ $r->target_format }}</summary>
                <form method="post" action="{{ route('preservation.rules.update', $r) }}">@csrf @include('preservation._rule', ['r' => $r, 'x' => 'r'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('preservation.rules.delete', $r) }}">@csrf<button type="submit">{{ __('Remove the rule') }}</button></form></details>
        @endforeach
        <details><summary>{{ __('Add a rule') }}</summary><form method="post" action="{{ route('preservation.rules.store') }}">@csrf @include('preservation._rule', ['r' => null, 'x' => 'rnew'])<button type="submit">{{ __('Add') }}</button></form></details>
    @else
        <p class="meta">{{ __('The rules are set by the installation\'s operators, as they hold for every institution on it.') }}</p>
    @endif

    <h2 id="conversions">{{ __('Conversions') }}</h2>
    <p>{{ trans_choice(':count conversion failed.|:count conversions failed.', $failed, ['count' => $failed]) }}</p>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('Into') }}</th><th scope="col">{{ __('Tool') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($conversions as $c)<tr><td>{{ $when($c->started_at) }}</td>
            <td>@if($c->instantiation_id && $c->source)<a href="{{ route('instantiations.show', $c->instantiation_id) }}">{{ $c->source->original_name }}</a>@else{{ $c->source_mime_type }}@endif</td>
            <td>{{ $c->purpose?->label($lang) }}, {{ $c->target_format }}@if($c->output) · {{ \Illuminate\Support\Number::fileSize((int) $c->output_size, 1) }}@endif</td>
            <td>{{ $c->tool->label($lang) }} {{ $c->tool_version }}</td>
            <td>{{ $c->status->label($lang) }}@if($c->error_message)<br><small>{{ \Illuminate\Support\Str::limit($c->error_message, 200) }}</small>@endif
                @if($c->status->notation === 'failed' && $c->instantiation_id && ! $c->migration_plan_id)<form method="post" action="{{ route('preservation.normalise', $c->instantiation_id) }}">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Try again') }}</button></form>@endif</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>

    <h2 id="pathways">{{ __('Migration pathways') }}</h2>
    <table><thead><tr><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('Into') }}</th><th scope="col">{{ __('Tool') }}</th><th scope="col">{{ __('Quality') }}</th><th scope="col">{{ __('Fidelity') }}</th><th scope="col">{{ __('Recommended') }}</th></tr></thead>
        <tbody>@forelse($pathways as $p)<tr><td>{{ $p->source_puid }}</td><td>{{ $p->target_puid }} (.{{ $p->target_ext }})</td><td>{{ $p->tool->label($lang) }}</td><td>{{ $p->qualityImpact->label($lang) }}</td>
            <td>{{ $p->fidelity_score !== null ? $p->fidelity_score.'%' : '' }}</td><td>{{ $p->is_recommended ? __('yes') : '' }}@if($p->is_automated) · {{ __('automated') }}@endif</td></tr>
        @empty<tr><td colspan="6">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    @if($operator)
        @foreach($pathways as $p)
            <details><summary>{{ __('Change') }}: {{ $p->source_puid }} → {{ $p->target_puid }}</summary>
                <form method="post" action="{{ route('preservation.pathways.update', $p) }}">@csrf @include('preservation._pathway', ['p' => $p, 'x' => 'p'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
        @endforeach
        <details><summary>{{ __('Add a pathway') }}</summary><form method="post" action="{{ route('preservation.pathways.store') }}">@csrf @include('preservation._pathway', ['p' => null, 'x' => 'pnew'])<button type="submit">{{ __('Add') }}</button></form></details>
    @endif

    <h2 id="plans">{{ __('Migration plans') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Plan') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('Into') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($plans as $plan)<tr><td><a href="{{ route('preservation.plans.show', $plan) }}">{{ $plan->name }}</a></td><td>{{ $plan->source_puid }}</td><td>{{ $plan->target_puid }}</td><td>{{ $plan->status->label($lang) }}</td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    @if($pathways->isNotEmpty())
        <details><summary>{{ __('Draw up a plan') }}</summary><form method="post" action="{{ route('preservation.plans.store') }}">@csrf @include('preservation._plan', ['plan' => null])<button type="submit">{{ __('Save as a draft') }}</button></form></details>
    @else
        <p class="meta">{{ __('A plan follows a migration pathway; there is none yet.') }}</p>
    @endif

    <h2 id="replication">{{ __('Replication') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Target') }}</th><th scope="col">{{ __('Where') }}</th><th scope="col">{{ __('How often') }}</th><th scope="col">{{ __('Last copied') }}</th></tr></thead>
        <tbody>@forelse($targets as $t)<tr><td>{{ $t->name }}@unless($t->is_active) · <strong>{{ __('off') }}</strong>@endunless</td>
            <td>{{ $t->type->label($lang) }}: {{ $t->host ? ($t->username ? $t->username.'@' : '').$t->host.':' : '' }}{{ $t->path }}</td><td>{{ $t->frequency->label($lang) }}</td>
            <td>@if($t->last_sync_at){{ $when($t->last_sync_at) }}, {{ $t->lastStatus?->label($lang) }}, {{ trans_choice(':count file|:count files', (int) $t->last_sync_files, ['count' => (int) $t->last_sync_files]) }}@else{{ __('not yet') }}@endif</td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    @if($operator)
        @foreach($targets as $t)
            <details><summary>{{ __('Change') }}: {{ $t->name }}</summary>
                <form method="post" action="{{ route('preservation.targets.update', $t) }}">@csrf @include('preservation._target', ['t' => $t, 'x' => 't'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('preservation.targets.run', [$t, 'sync']) }}" style="display: inline">@csrf<button type="submit">{{ __('Copy now') }}</button></form>
                <form method="post" action="{{ route('preservation.targets.run', [$t, 'verify']) }}" style="display: inline">@csrf<button type="submit">{{ __('Compare the copy') }}</button></form></details>
        @endforeach
        <details><summary>{{ __('Add a target') }}</summary><form method="post" action="{{ route('preservation.targets.store') }}">@csrf @include('preservation._target', ['t' => null, 'x' => 'tnew'])<button type="submit">{{ __('Add') }}</button></form></details>
    @else
        <p class="meta">{{ __('Replication targets are set by the installation\'s operators: the object store is the installation\'s.') }}</p>
    @endif
    <h3>{{ __('Runs') }}</h3>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Target') }}</th><th scope="col">{{ __('Operation') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Files') }}</th></tr></thead>
        <tbody>@forelse($runs as $run)<tr><td>{{ $when($run->started_at) }}</td><td>{{ $run->target->name }}</td><td>{{ $run->operation->label($lang) }}</td>
            <td>{{ $run->status->label($lang) }}@if($run->error_message)<br><small>{{ \Illuminate\Support\Str::limit($run->error_message, 200) }}</small>@endif</td>
            <td>{{ __(':synced of :total, :failed differing', ['synced' => $run->files_synced, 'total' => $run->files_total, 'failed' => $run->files_failed]) }}</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
