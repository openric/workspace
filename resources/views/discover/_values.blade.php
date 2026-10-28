{{-- OpenRiC Workspace - an entity's published values, element by element, each in every language it has; empty elements are left out; each area is a collapsible section. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $subject, $standard, $view (ExportView, the public). The authorised name is shown by the page itself; parallel and other names, and alternate titles, here. --}}
@foreach(\App\Description\Elements::byArea($standard) as $area => $elements)
    @php($shown = $elements->reject(fn ($e) => \App\Description\Elements::nameType($e->notation) === 'authorised')->map(fn ($e) => [$e, $view->values($subject, $e->notation)])->filter(fn ($pair) => $pair[1]->isNotEmpty()))
    @if($shown->isNotEmpty())
        <details class="area" open>
            <summary><h2>{{ $area }}</h2></summary>
            <dl class="values">
                @foreach($shown as [$element, $values])
                    <dt>{{ $element->label(\App\Support\Vocab::languages()) }} @include('description._ric', ['term' => $element])</dt>
                    @foreach($values as $value)
                        @if($codeKind = \App\Description\Elements::codeKind($element->notation))
                            <dd>{{ \App\Description\IsoCodes::names($codeKind, $value->value) }}</dd>
                        @else
                            @include('description._long', ['value' => $value, 'tag' => 'dd'])
                        @endif
                    @endforeach
                @endforeach
            </dl>
        </details>
    @endif
@endforeach
