{{-- OpenRiC Workspace - the review queue of proposals sent through the API (ADR 0007). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Proposals to review'))
@section('content')
    <h1>{{ __('Proposals to review') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('What people and their tools proposed for records through the API, with the evidence they gave. Nothing here is part of a record until you accept it; accepting writes it as a draft, which is published as any other change. Change the text before accepting if it needs it.') }}</p>

    <form method="get" action="{{ route('proposals.index') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: end">
        <label>{{ __('Status') }}
            <select name="status">@foreach(['proposed' => 'Waiting', 'accepted' => 'Accepted', 'rejected' => 'Rejected'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select>
        </label>
        <label>{{ __('Kind') }}
            <select name="kind"><option value="">{{ __('All') }}</option>@foreach(['agent' => 'People and organisations', 'place' => 'Places', 'date' => 'Dates', 'value' => 'Values', 'transcript' => 'Transcripts', 'relation' => 'Relations'] as $value => $label)<option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>@endforeach</select>
        </label>
        @if($record)<input type="hidden" name="record" value="{{ $record }}">@endif
        <button type="submit">{{ __('Show') }}</button>
        @if($record)<a href="{{ route('proposals.index', ['status' => $status, 'kind' => $kind]) }}">{{ __('All records') }}</a>@endif
    </form>

    @if($kind !== 'relation')
        <h2>{{ __('Proposals') }}</h2>
        @forelse($proposals as $p)
            @php($k = $p->kind->notation)
            <section id="proposal-{{ $p->id }}" style="border: 1px solid var(--line); padding: 0 1rem; margin-bottom: 1rem">
                <h3 style="font-size: 1.05rem">
                    @php($heading = $p->kind->label(\App\Support\Vocab::languages()).($k === 'value' ? ': '.$p->element->label(\App\Support\Vocab::languages()) : '').($k === 'agent' && $p->agentType ? ' ('.mb_strtolower($p->agentType->label(\App\Support\Vocab::languages())).')' : ''))
                    {{ $heading }}
                    {{ __('for') }} <a href="{{ route('records.show', $p->record) }}">{{ \App\Relations\EntityKinds::label($p->record) }}</a>
                    <small>(<a href="{{ route('proposals.index', ['record' => $p->record_resource_id, 'status' => $status]) }}">{{ __('all for this record') }}</a>)</small>
                </h3>
                @php($facts = array_filter(['Proposed by '.$p->proposedBy->name.', through '.$p->origin_label.', '.$p->created_at->format('Y-m-d H:i'),
                    $p->confidence !== null ? 'confidence '.number_format($p->confidence, 2) : null, $p->language ? 'language '.$p->language : null]))
                <p><small>{{ implode('; ', $facts) }}
                    @if($k === 'transcript' && $p->instantiation); {{ __('of') }} <a href="{{ route('instantiations.show', $p->instantiation) }}">{{ $p->instantiation->described('instantiation-title', ['en']) ?? __('the instantiation') }}</a> @endif
                </small></p>
                @if($p->evidence)<p><strong>{{ __('Evidence') }}:</strong> {{ $p->evidence }}</p>@endif
                @if($p->sources)<p><strong>{{ __('Sources') }}:</strong> @foreach($p->sources as $url) <a href="{{ $url }}" rel="noopener noreferrer nofollow">{{ $url }}</a> @endforeach</p>@endif
                @if($p->authority)<p><strong>{{ __('Known of them') }}:</strong> {{ collect($p->authority)->map(fn ($text, $field) => str_replace('_', ' ', $field).': '.$text)->implode('; ') }}</p>@endif
                @if($p->link_reason)<p><strong>{{ __('Why the proposed link') }}:</strong> {{ $p->link_reason }}</p>@endif

                @if($p->status->notation === 'proposed')
                    <form method="post" action="{{ route('proposals.accept', $p) }}">
                        @csrf
                        <label>{{ __('Text to write') }}
                            @if(in_array($k, ['value', 'transcript'], true))
                                <textarea name="value" rows="{{ $k === 'transcript' ? 10 : 4 }}" style="width: 100%">{{ old('value', $p->value) }}</textarea>
                            @else
                                <input name="value" value="{{ $p->value }}" style="width: 100%">
                            @endif
                        </label>
                        @if(in_array($k, ['value', 'transcript', 'date', 'agent', 'place'], true))
                            <label>{{ __('Language') }} <input name="language" value="{{ $p->language ?? $p->record->originalLanguage('title') }}" size="8"></label>
                        @endif
                        @if($k === 'date')
                            <label>{{ __('Normalised (EDTF, optional)') }} <input name="normalized" placeholder="{{ __('such as 1901-05-03') }}" size="16"></label>
                        @endif
                        @if(in_array($k, ['agent', 'place'], true))
                            <fieldset>
                                <legend>{{ __('Link it to') }}</legend>
                                @if($p->linked_id)
                                    @php($proposed = ($k === 'agent' ? \App\Models\Agent::class : \App\Models\Place::class)::find($p->linked_id))
                                    @if($proposed)<label><input type="radio" name="link" value="{{ $proposed->id }}" checked> {{ __($k === 'agent' ? 'the proposed authority record' : 'the proposed place') }}: <a href="{{ route($k === 'agent' ? 'agents.show' : 'places.show', $proposed) }}">{{ \App\Relations\EntityKinds::label($proposed) }}</a></label><br>@endif
                                @endif
                                <label><input type="radio" name="link" value="new" @checked(! $p->linked_id)> {{ __($k === 'agent' ? 'a new authority record of this name' : 'a new place of this name') }}</label>
                                @if($k === 'agent')
                                    <select name="agent_type" aria-label="{{ __('Type of the new authority record') }}">@foreach($agentTypes as $type)<option value="{{ $type->notation }}" @selected($type->id === $p->agent_type_id)>{{ $type->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
                                @endif
                            </fieldset>
                        @endif
                        <label>{{ __('Note (optional)') }} <input name="note" size="40"></label>
                        <button type="submit">{{ __('Accept') }}</button>
                    </form>
                    <form method="post" action="{{ route('proposals.reject', $p) }}">
                        @csrf
                        <label>{{ __('Why not (optional)') }} <input name="note" size="40"></label>
                        <button type="submit">{{ __('Reject') }}</button>
                    </form>
                    <p></p>
                @else
                    <p>{{ $p->status->label(\App\Support\Vocab::languages()) }} by {{ $p->reviewedBy?->name }}, {{ $p->reviewed_at?->format('Y-m-d H:i') }}{{ $p->review_note ? ': '.$p->review_note : '' }}</p>
                @endif
            </section>
        @empty
            <p>{{ __('None.') }}</p>
        @endforelse
    @endif

    @if($kind === null || $kind === 'relation')
        <h2>{{ __('Relations') }}</h2>
        @forelse($assertions as $a)
            @php([$from, $to] = $ends($a))
            @php($record = $a->relation->evidence->first()?->record)
            <section id="assertion-{{ $a->id }}" style="border: 1px solid var(--line); padding: 0 1rem; margin-bottom: 1rem">
                <h3 style="font-size: 1.05rem">
                    @if($from)<a href="{{ \App\Relations\EntityKinds::route($from) }}">{{ \App\Relations\EntityKinds::label($from) }}</a>@endif
                    {{ $a->relation->phrase() }}
                    @if($to)<a href="{{ \App\Relations\EntityKinds::route($to) }}">{{ \App\Relations\EntityKinds::label($to) }}</a>@endif
                </h3>
                <p><small>{{ __('Proposed by :name, :date', ['name' => $a->asserter(), 'date' => $a->asserted_at->format('Y-m-d H:i')]) }} @if($record), {{ __('from') }} <a href="{{ route('records.show', $record) }}">{{ \App\Relations\EntityKinds::label($record) }}</a>@endif</small></p>
                @if($a->basis)<p style="white-space: pre-line"><strong>{{ __('Basis') }}:</strong> {{ $a->basis }}</p>@endif
                @if($a->status->notation === 'proposed')
                    <form method="post" action="{{ route('proposals.relation', $a) }}" style="display: flex; gap: .5rem">
                        @csrf
                        <button type="submit" name="decision" value="accept">{{ __('Accept') }}</button>
                        <button type="submit" name="decision" value="reject">{{ __('Reject') }}</button>
                    </form>
                    <p></p>
                @else
                    <p>{{ $a->status->label(\App\Support\Vocab::languages()) }} by {{ $a->reviewer?->name }}, {{ $a->reviewed_at?->format('Y-m-d H:i') }}</p>
                @endif
            </section>
        @empty
            <p>{{ __('None.') }}</p>
        @endforelse
    @endif
@endsection
