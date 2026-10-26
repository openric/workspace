{{-- OpenRiC Workspace - the rights in force and the Local Contexts Labels of a unit or an instantiation, in the public catalogue (WS-007, roadmap phase 2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $subject, $view (ExportView, the public); optional $compact (one line, for a file). --}}
@php($public = new \App\Rights\PublicRights($view))
@php($rights = $public->rights($subject))
@php($labels = $public->labels($subject))
@php($lang = \App\Support\Vocab::languages())
@if($rights->isNotEmpty() || $labels->isNotEmpty())
    @if($compact ?? false)
        <span class="meta">@foreach($rights as $right)@if($right->statement)<a href="{{ $right->statement->iri }}" rel="noopener license">{{ $right->statement->label($lang) }}</a> @endif @if($right->licence)<a href="{{ $right->licence->iri }}" rel="noopener license">{{ $right->licence->notation }}</a> @endif @endforeach
            @foreach($labels as $label)<a href="{{ $label->label->iri }}" rel="noopener">{{ $label->label->label($lang) }}</a> @endforeach</span>
    @else
        <h2>{{ __('Rights') }} @include('description._ric', ['term' => 'rico:isOrWasRegulatedBy'])</h2>
        <ul>
            @foreach($rights as $right)
                <li>{{ $right->basis->label($lang) }}@if($right->statement): <a href="{{ $right->statement->iri }}" rel="noopener license">{{ $right->statement->label($lang) }}</a>@endif
                    @if($right->licence) · <a href="{{ $right->licence->iri }}" rel="noopener license">{{ $right->licence->label($lang) }}</a>@endif
                    @if($right->holder) · {{ __('Rights holder') }}: @if($url = \App\Discovery\Links::page($right->holder))<a href="{{ $url }}">{{ $view->label($right->holder) }}</a>@else{{ $view->label($right->holder) }}@endif @endif
                    @if($right->grants->isNotEmpty())<br><small>{{ $right->grants->map(fn ($g) => $g->act->label($lang).': '.mb_strtolower($g->restriction->label($lang)).($g->note ? ' ('.$g->note.')' : ''))->join('; ') }}</small>@endif
                    @if($right->note)<br><small lang="{{ $right->note_language }}">{{ $right->note }}</small>@endif</li>
            @endforeach
            @foreach($labels as $label)
                <li><a href="{{ $label->label->iri }}" rel="noopener"><strong>{{ $label->label->label($lang) }}</strong></a>@if($label->community) · {{ __('Community') }}: {{ $view->label($label->community) }}@endif
                    <br><small>{{ $label->label->labels->firstWhere('language', 'en')?->definition }}</small></li>
            @endforeach
        </ul>
    @endif
@endif
