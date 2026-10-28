{{-- OpenRiC Workspace - the rights of a unit of description or an instantiation, each a rico:Rule (WS-007, roadmap phase 2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs: $subject (RecordResource or Instantiation), $kind (records or instantiations), $canUpdate. After Heratio's rights panel (ahg-extended-rights). --}}
@php($rights = app(\App\Rights\RightsService::class)->of($subject))
@php($choices = fn (string $vocabulary) => \App\Models\Concept::choices($vocabulary)->get())
@php($lang = \App\Support\Vocab::languages())
<h2 id="rights">{{ __('Rights') }} @include('description._ric', ['term' => 'rico:Rule'])</h2>
@error('rights')<p class="error" role="alert">{{ $message }}</p>@enderror
@forelse($rights as $right)
    @php($summary = collect([$right->basis->label($lang), $right->statement?->label($lang), $right->licence?->label($lang)])->filter()->join(' · '))
    <section style="border-top: 1px solid var(--line); padding: .3rem 0">
        <p><strong>{{ $summary }}</strong>
            @if($right->statement) <a href="{{ $right->statement->iri }}" rel="noopener">{{ $right->statement->notation }}</a>@endif
            @if($right->licence) <a href="{{ $right->licence->iri }}" rel="noopener">{{ $right->licence->notation }}</a>@endif
            @if(! $right->inForce()) <span class="meta">({{ __('not in force today') }})</span>@endif
            <br><small>{{ collect([
                $right->copyrightStatus ? __('Copyright status').': '.$right->copyrightStatus->label($lang) : null,
                $right->holder ? __('Rights holder').': '.$right->holder->described('agent-authorised-name', $lang) : null,
                $right->jurisdiction ? __('Jurisdiction').': '.$right->jurisdiction : null,
                $right->starts_on || $right->ends_on ? __(':from to :to', ['from' => $right->starts_on?->toDateString() ?? '?', 'to' => $right->ends_on?->toDateString() ?? '?']) : null,
                $right->citation,
                $right->licence_terms ? __('Licence terms').': '.$right->licence_terms : null,
                $right->policy_identifier ? __('Policy identifier').': '.$right->policy_identifier : null,
            ])->filter()->join('; ') }}</small>
            @if($right->holder) @include('description._ric', ['term' => 'rico:hasOrHadIntellectualPropertyRightsHolder'])@endif</p>
        @if($right->grants->isNotEmpty())
            <ul>@foreach($right->grants as $grant)<li>{{ $grant->act->label($lang) }}: {{ mb_strtolower($grant->restriction->label($lang)) }}@if($grant->note) ({{ $grant->note }})@endif</li>@endforeach</ul>
        @endif
        @if($right->note)<p lang="{{ $right->note_language }}">{{ $right->note }}</p>@endif
        @if($canUpdate)
            <details>
                <summary>{{ __('- edit or remove') }}</summary>
                <form method="post" action="{{ route('rights.update', [$kind, $subject->getKey(), $right]) }}">
                    @csrf
                    @include('description._right-fields', ['right' => $right, 'prefix' => 'r'.$loop->index, 'choices' => $choices])
                    <button type="submit">{{ __('Save right') }}</button>
                </form>
                <form method="post" action="{{ route('rights.destroy', [$kind, $subject->getKey(), $right]) }}">@csrf<button type="submit">{{ __('Remove right') }}</button></form>
                <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $right->iri }}</code></p>
            </details>
        @endif
    </section>
@empty
    <p>{{ __('No rights recorded.') }}</p>
@endforelse
@if($canUpdate)
    <details>
        <summary>{{ __('Add a right') }}</summary>
        <form method="post" action="{{ route('rights.store', [$kind, $subject->getKey()]) }}">
            @csrf
            @include('description._right-fields', ['right' => null, 'prefix' => 'new-right', 'choices' => $choices])
            <button type="submit">{{ __('Add right') }}</button>
        </form>
    </details>
@endif
