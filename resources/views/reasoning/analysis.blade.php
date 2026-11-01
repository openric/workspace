{{-- OpenRiC Workspace - a project's analysis results, their claims, and its theme tags and memos (Heratio's analysis bridge; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Analysis results').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('reasoning._nav')
    <h1>{{ __('Analysis results') }}</h1>
    @forelse($results as $r)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $r->title }}</strong> <span class="meta">{{ $r->resultType->label($lang) }}@if($r->generated_at) · {{ $r->generated_at->timezone(config('workspace.timezone'))->format('j M Y') }}@endif · {{ $r->createdBy?->name }}</span>
                @if($r->artifact) · <a href="{{ route('research.reasoning.analysis.step', [$r, 'artifact']) }}">{{ $r->artifact->original_name }}</a>@endif</p>
            @foreach(['source_data_ref' => 'Data', 'source_data_version' => 'Version of the data', 'method' => 'Method', 'code_ref' => 'Code', 'researcher_decision' => 'What it means'] as $f => $l)@if($r->{$f})<p><em>{{ __($l) }}:</em> {{ $r->{$f} }}</p>@endif @endforeach
            @foreach($r->claimLinks as $link)<p style="margin-left: 1.5rem">{{ $link->relationship->label($lang) }}: <a href="{{ route('research.reasoning.claim', [$project, $link->claim]) }}">{{ \Illuminate\Support\Str::limit($link->claim->object_value, 100) }}</a>@if($link->note) · {{ $link->note }}@endif
                @if($level >= 2)<form method="post" action="{{ route('research.reasoning.analysis.unlink', $link) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>@endforeach
            @if($level >= 2 && $claims->isNotEmpty())<form method="post" action="{{ route('research.reasoning.analysis.step', [$r, 'claim']) }}">@csrf
                <label for="ac-rel-{{ $loop->index }}">{{ __('It') }}</label> <select id="ac-rel-{{ $loop->index }}" name="relationship">@foreach($relationships as $k)<option value="{{ $k->notation }}">{{ $k->label($lang) }}</option>@endforeach</select>
                <label for="ac-claim-{{ $loop->index }}">{{ __('the claim') }}</label> <select id="ac-claim-{{ $loop->index }}" name="claim_id">@foreach($claims as $c)<option value="{{ $c->id }}">{{ \Illuminate\Support\Str::limit($c->object_value, 70) }}</option>@endforeach</select>
                <input name="note" size="25" aria-label="{{ __('Note') }}"> <button type="submit">{{ __('Link') }}</button></form>@endif
            @if($level >= 3 || $level >= 2 && $r->created_by_id === auth()->id())<form method="post" action="{{ route('research.reasoning.analysis.step', [$r, 'delete']) }}">@csrf<button type="submit">{{ __('Delete') }}</button></form>@endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)
        <h2>{{ __('Add a result') }}</h2>
        <form method="post" action="{{ route('research.reasoning.analysis.save', $project) }}" enctype="multipart/form-data">@csrf
            <label for="an-title">{{ __('Title') }}</label> <input id="an-title" name="title" required size="50">
            <label for="an-type">{{ __('Kind') }}</label> <select id="an-type" name="result_type">@foreach($types as $k)<option value="{{ $k->notation }}">{{ $k->label($lang) }}</option>@endforeach</select><br>
            <label for="an-data">{{ __('Data') }}</label> <input id="an-data" name="source_data_ref" size="40"> <label for="an-ver">{{ __('Version of the data') }}</label> <input id="an-ver" name="source_data_version" size="15"><br>
            <label for="an-method">{{ __('Method') }}</label> <input id="an-method" name="method" size="50"> <label for="an-code">{{ __('Code') }}</label> <input id="an-code" name="code_ref" size="30"><br>
            <label for="an-at">{{ __('Made on') }}</label> <input id="an-at" name="generated_at" type="date"> <label for="an-file">{{ __('File') }}</label> <input id="an-file" name="artifact" type="file"><br>
            <label for="an-dec">{{ __('What it means') }}</label><br><textarea id="an-dec" name="researcher_decision" rows="2" cols="80"></textarea><br><button type="submit">{{ __('Add') }}</button></form>
    @endif
    <h2>{{ __('Theme tags and memos') }}</h2>
    @forelse($codes as $c)<p><span class="meta">{{ $c->kind->label($lang) }}</span> <strong>{{ $c->label }}</strong>@if($c->body) · {{ $c->body }}@endif
        @if($level >= 2)<form method="post" action="{{ route('research.reasoning.analysis.code', [$project, $c]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<form method="post" action="{{ route('research.reasoning.analysis.code', $project) }}">@csrf<label for="co-kind">{{ __('Kind') }}</label> <select id="co-kind" name="kind">@foreach($codeKinds as $k)<option value="{{ $k->notation }}">{{ $k->label($lang) }}</option>@endforeach</select>
        <label for="co-label">{{ __('Label') }}</label> <input id="co-label" name="label" required> <label for="co-body">{{ __('Text') }}</label> <input id="co-body" name="body" size="40"> <button type="submit">{{ __('Add') }}</button></form>@endif
@endsection
