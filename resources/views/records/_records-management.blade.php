{{-- OpenRiC Workspace - a record's place in the file plan, its declaration and whether it is vital (roadmap phase 13c, ADR 0015). Needs: $record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($placement = \App\Models\FilePlanPlacement::where('record_resource_id', $record->id)->with('node.activity', 'rule')->first())
@php($declaration = \App\Models\RecordDeclaration::where('record_resource_id', $record->id)->with('status', 'declaredBy')->first())
@php($vital = \App\Models\VitalRecord::where(['record_resource_id' => $record->id, 'is_active' => true])->first())
@php($nodes = \App\Models\FilePlanNode::where('institution_id', $record->institution_id)->whereHas('status', fn ($q) => $q->where('iri', \App\Support\Vocab::BASE.'file-plan-status/active'))->orderBy('code')->get())
<section id="records-management">
    <h2>{{ __('Records management') }} @include('description._ric', ['term' => null])</h2>
    @error('records')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p>{{ __('File plan') }}: @if($placement)<a href="{{ route('records-management.node', $placement->node) }}">{{ $placement->node->code }} {{ $placement->node->title }}</a>@if($placement->node->activity) <span class="meta">({{ __('documents :activity', ['activity' => \App\Relations\EntityKinds::label($placement->node->activity)]) }}, rico:documents)</span>@endif @if($placement->rule) <span class="meta">{{ __('by the rule :name', ['name' => $placement->rule->name]) }}</span>@endif @else{{ __('not placed') }}@endif</p>
    @if($nodes->isNotEmpty())
        <form method="post" action="{{ route('records.records-management', [$record, 'place']) }}" style="display: inline">@csrf<label for="rm-node">{{ __('Place in') }}</label> @include('description._ric', ['term' => 'rico:documents'])
            <select id="rm-node" name="file_plan_node_id">@foreach($nodes as $n)<option value="{{ $n->id }}" @selected($placement?->file_plan_node_id === $n->id)>{{ $n->code }} {{ $n->title }}</option>@endforeach</select> <button type="submit">{{ __('Place') }}</button></form>
        @unless($placement)<form method="post" action="{{ route('records.records-management', [$record, 'classify']) }}" style="display: inline">@csrf<button type="submit">{{ __('Place by the rules') }}</button></form>@endunless
        @if($placement)<form method="post" action="{{ route('records.records-management', [$record, 'unplace']) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove from the file plan') }}</button></form>@endif
    @endif
    <p>{{ __('Declaration') }}: @if($declaration){{ $declaration->status->label($lang) }}@if($declaration->declared_at) · {{ $declaration->declared_at->format('j M Y') }} · {{ $declaration->declaredBy?->name }}@endif @else{{ __('not declared') }}@endif</p>
    @if(! $declaration || $declaration->status->notation === 'rejected')
        <form method="post" action="{{ route('records.records-management', [$record, 'declare']) }}">@csrf<label for="rm-notes">{{ __('Notes') }}</label> @include('description._ric', ['term' => null]) <input id="rm-notes" name="notes" size="40"> <button type="submit">{{ __('Ask to declare it a record') }}</button></form>
    @endif
    <p>{{ __('Vital record') }}: @if($vital){{ $vital->reason }} · {{ __('next review :date', ['date' => $vital->next_review_on->format('Y-m-d')]) }}@else{{ __('no') }}@endif</p>
    @unless($vital)
        <details><summary>{{ __('Flag as a vital record') }}</summary><form method="post" action="{{ route('records.records-management', [$record, 'vital']) }}">@csrf
            <label for="rm-reason">{{ __('Why it is vital') }}</label> @include('description._ric', ['term' => null]) <input id="rm-reason" name="reason" required size="50">
            <label for="rm-cycle">{{ __('Review every (days)') }}</label> @include('description._ric', ['term' => null]) <input id="rm-cycle" name="review_cycle_days" type="number" min="1" max="3650" value="365" style="width: 6rem"> <button type="submit">{{ __('Flag') }}</button></form></details>
    @endunless
</section>
