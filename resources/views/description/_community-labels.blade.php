{{-- OpenRiC Workspace - the Traditional Knowledge and Biocultural Labels of Local Contexts on a record (WS-007, roadmap phase 2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs: $subject, $kind, $canUpdate. After Heratio's ICIP labels (ahg-icip). --}}
@php($service = app(\App\Rights\CommunityLabelService::class))
@php($applied = $service->of($subject))
@php($lang = \App\Support\Vocab::languages())
<h2 id="labels">{{ __('Traditional Knowledge and Biocultural Labels') }} @include('description._ric', ['term' => 'rico:Rule'])</h2>
@error('labels')<p class="error" role="alert">{{ $message }}</p>@enderror
@forelse($applied as $label)
    <p><a href="{{ $label->label->iri }}" rel="noopener"><strong>{{ $label->label->label($lang) }}</strong></a>
        <br><small>{{ collect([
            __('Applied by :who', ['who' => mb_strtolower($label->appliedBy->label($lang))]),
            $label->community ? __('Community').': '.$label->community->described('agent-authorised-name', $lang) : null,
            $label->local_contexts_project ? __('Local Contexts project').': '.$label->local_contexts_project : null,
        ])->filter()->join('; ') }}</small>
        @if($label->note)<br>{{ $label->note }}@endif
        @if($canUpdate)<form method="post" action="{{ route('community-labels.destroy', [$kind, $subject->getKey(), $label]) }}" style="display: inline">@csrf<button type="submit" style="margin: 0; padding: .1rem .5rem">{{ __('Remove') }}</button></form>@endif</p>
@empty
    <p>{{ __('None applied.') }}</p>
@endforelse
@if($canUpdate)
    <details>
        <summary>{{ __('Apply a Label') }}</summary>
        <form method="post" action="{{ route('community-labels.store', [$kind, $subject->getKey()]) }}">
            @csrf
            <label for="label-{{ $subject->getKey() }}">{{ __('Label') }}</label>
            <select id="label-{{ $subject->getKey() }}" name="label" required>
                @foreach($service->choices() as $group => $labels)
                    <optgroup label="{{ $labels->first()->broader->label($lang) }}">
                        @foreach($labels as $choice)<option value="{{ $choice->notation }}" @selected(old('label') === $choice->notation)>{{ $choice->label($lang) }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
            <label for="applied-{{ $subject->getKey() }}">{{ __('Applied by') }}</label>
            <select id="applied-{{ $subject->getKey() }}" name="applied_by">
                @foreach(\App\Models\Concept::choices(\App\Rights\CommunityLabelService::APPLIERS)->get() as $applier)<option value="{{ $applier->notation }}">{{ $applier->label($lang) }}</option>@endforeach
            </select>
            <label for="community-{{ $subject->getKey() }}">{{ __('Community') }}</label>
            <select id="community-{{ $subject->getKey() }}" name="community"><option value="">{{ __('Not given') }}</option>
                @foreach(\App\Models\Agent::where('institution_id', auth()->user()->institution_id)->whereNull('deleted_at')->with('names')->get()->sortBy(fn ($a) => mb_strtolower((string) $a->described('agent-authorised-name', ['en']))) as $agent)
                    <option value="{{ $agent->id }}">{{ $agent->described('agent-authorised-name', $lang) }}</option>
                @endforeach
            </select>
            <label for="project-{{ $subject->getKey() }}">{{ __('Local Contexts Hub project (optional)') }}</label><input id="project-{{ $subject->getKey() }}" name="project">
            <label for="label-note-{{ $subject->getKey() }}">{{ __('Note') }}</label><textarea id="label-note-{{ $subject->getKey() }}" name="note" rows="2"></textarea>
            <button type="submit">{{ __('Apply the Label') }}</button>
        </form>
    </details>
@endif
