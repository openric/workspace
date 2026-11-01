{{-- OpenRiC Workspace - a snapshot of a project's records, and how it compares with another (roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $snapshot->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('analysis._nav')
    <h1>{{ $snapshot->title }}</h1>
    <p>{{ $snapshot->status->label($lang) }} · {{ trans_choice(':count record|:count records', $snapshot->item_count, ['count' => $snapshot->item_count]) }} · SHA-256 <code>{{ $snapshot->hash_sha256 }}</code>@if($snapshot->citation_id) · {{ __('cite as') }} <code>{{ $snapshot->citation_id }}</code>@endif</p>
    @if($snapshot->description)<p>{{ $snapshot->description }}</p>@endif
    @if($snapshot->rights_state)<p class="meta">{{ __(':public public, :embargoed under an embargo, when it was taken.', $snapshot->rights_state) }}</p>@endif
    <table><thead><tr><th>{{ __('Reference code') }}</th><th>{{ __('Title') }}</th><th>{{ __('Checksum') }}</th></tr></thead><tbody>
    @foreach($snapshot->items as $i)<tr><td>{{ $i->reference_code }}</td><td>@if($i->record)<a href="{{ route('records.show', $i->record) }}">{{ $i->title }}</a>@else{{ $i->title }}@endif</td><td><code>{{ substr($i->metadata_version['hash'] ?? '', 0, 12) }}</code></td></tr>@endforeach
    </tbody></table>
    @if($others->isNotEmpty())<form method="get" action="{{ route('research.analysis.snapshot', $snapshot) }}"><label for="sc-with">{{ __('Compare with') }}</label> <select id="sc-with" name="with">@foreach($others as $o)<option value="{{ $o->id }}" @selected($compare && $compare['with']->is($o))>{{ $o->title }}</option>@endforeach</select> <button type="submit">{{ __('Compare') }}</button></form>@endif
    @if($compare)
        <h2>{{ __('Since :title', ['title' => $compare['with']->title]) }}</h2>
        @foreach(['added' => 'Came', 'removed' => 'Went', 'changed' => 'Changed'] as $k => $l)<p><strong>{{ __($l) }}</strong> ({{ count($compare['diff'][$k]) }})@foreach($compare['diff'][$k] as $r)<br>{{ trim($r['reference'].' '.$r['title']) }}@endforeach</p>@endforeach
        <p class="meta">{{ __(':count unchanged', ['count' => $compare['diff']['unchanged']]) }}</p>
    @endif
    @if($level >= 2)
        @if($snapshot->status->notation === 'active')<form method="post" action="{{ route('research.analysis.snapshots.step', [$project, 'retake', $snapshot]) }}" style="display: inline">@csrf<button type="submit">{{ __('Take it again') }}</button></form>
            <form method="post" action="{{ route('research.analysis.snapshots.step', [$project, 'freeze', $snapshot]) }}" style="display: inline">@csrf<button type="submit">{{ __('Freeze it, to cite') }}</button></form>@endif
        @if($snapshot->status->notation !== 'archived')<form method="post" action="{{ route('research.analysis.snapshots.step', [$project, 'archive', $snapshot]) }}" style="display: inline">@csrf<button type="submit">{{ __('Archive') }}</button></form>@endif
        <form method="post" action="{{ route('research.analysis.snapshots.step', [$project, 'delete', $snapshot]) }}" style="display: inline">@csrf<button type="submit">{{ __('Delete') }}</button></form>
    @endif
@endsection
