{{-- OpenRiC Workspace - a unit of description's embargoes (roadmap phase 13a, ADR 0015), after Heratio's embargo panel (ahg-extended-rights): what each withholds, why, from when to when, who it is opened to; impose, change, lift. Needs: $record, $canUpdate. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($embargoes = \App\Models\Embargo::where('record_resource_id', $record->id)->with('type', 'reason', 'exceptions.user', 'exceptions.role')->orderByDesc('starts_on')->get())
@php($lang = \App\Support\Vocab::languages())
@php($types = \App\Models\Concept::choices(\App\Rights\Embargoes::TYPES)->get())
@php($reasons = \App\Models\Concept::choices(\App\Rights\Embargoes::REASONS)->get())
<section id="embargoes">
    <h2>{{ __('Embargoes') }}</h2>
    @error('embargo')<p class="error" role="alert">{{ $message }}</p>@enderror
    @error('exception')<p class="error" role="alert">{{ $message }}</p>@enderror
    @forelse($embargoes as $e)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $e->type->label($lang) }}</strong>@if($e->reason) · {{ $e->reason->label($lang) }}@endif ·
                {{ __(':from to :to', ['from' => $e->starts_on->format('Y-m-d'), 'to' => $e->ends_on?->format('Y-m-d') ?? __('until lifted')]) }}
                @if($e->lifted_at) <span class="meta">({{ __('lifted on :date', ['date' => $e->lifted_at->format('Y-m-d')]) }}@if($e->lift_reason): {{ $e->lift_reason }}@endif)</span>
                @elseif($e->inForce()) <span class="meta">({{ __('in force') }})</span>@else <span class="meta">({{ __('not in force today') }})</span>@endif
                @if($e->note)<br><small style="white-space: pre-line">{{ $e->note }}</small>@endif
                @if($e->public_message)<br><small>{{ __('The public is told') }}: {{ $e->public_message }}</small>@endif</p>
            @if($e->exceptions->isNotEmpty())
                <ul>@foreach($e->exceptions as $x)
                    <li>{{ $x->user?->name ?? $x->role?->name($lang) ?? ($x->ip_from === $x->ip_to ? $x->ip_from : $x->ip_from.' - '.$x->ip_to) }}
                        @if($x->valid_from || $x->valid_until)({{ __(':from to :to', ['from' => $x->valid_from?->format('Y-m-d') ?? '?', 'to' => $x->valid_until?->format('Y-m-d') ?? '?']) }})@endif
                        @if($x->note): {{ $x->note }}@endif
                        @if($canUpdate)<form method="post" action="{{ route('records.embargoes.exceptions.remove', [$record, $e, $x]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</li>
                @endforeach</ul>
            @endif
            @if($canUpdate && $e->lifted_at === null)
                <details><summary>{{ __('Change, lift or open it') }}</summary>
                    <form method="post" action="{{ route('records.embargoes.update', [$record, $e]) }}">@csrf @include('records._embargo-fields', ['e' => $e, 'p' => 'e'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                    <form method="post" action="{{ route('records.embargoes.lift', [$record, $e]) }}">@csrf
                        <label for="lift-{{ $e->id }}">{{ __('Reason for lifting it') }}</label> <input id="lift-{{ $e->id }}" name="lift_reason" size="40"> <button type="submit">{{ __('Lift now') }}</button></form>
                    <form method="post" action="{{ route('records.embargoes.exceptions.add', [$record, $e]) }}">@csrf
                        <p>{{ __('Open it to one account, one role or one IP range (such as the reading room\'s):') }}</p>
                        <p><label for="xu-{{ $e->id }}">{{ __('Account') }}</label> <select id="xu-{{ $e->id }}" name="user_id"><option value="">-</option>@foreach(\App\Models\User::where('institution_id', $record->institution_id)->whereNull('disabled_at')->orderBy('name')->get() as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                            <label for="xr-{{ $e->id }}">{{ __('Role') }}</label> <select id="xr-{{ $e->id }}" name="role_id"><option value="">-</option>@foreach(\App\Models\Role::where('institution_id', $record->institution_id)->get()->sortBy(fn ($r) => $r->name($lang)) as $r)<option value="{{ $r->id }}">{{ $r->name($lang) }}</option>@endforeach</select></p>
                        <p><label for="xf-{{ $e->id }}">{{ __('IP addresses from') }}</label> <input id="xf-{{ $e->id }}" name="ip_from" size="16"> <label for="xt-{{ $e->id }}">{{ __('to') }}</label> <input id="xt-{{ $e->id }}" name="ip_to" size="16"></p>
                        <p><label for="xvf-{{ $e->id }}">{{ __('From') }}</label> <input id="xvf-{{ $e->id }}" type="date" name="valid_from"> <label for="xvu-{{ $e->id }}">{{ __('until') }}</label> <input id="xvu-{{ $e->id }}" type="date" name="valid_until">
                            <label for="xn-{{ $e->id }}">{{ __('Note') }}</label> <input id="xn-{{ $e->id }}" name="note" size="30"></p>
                        <button type="submit">{{ __('Add the exception') }}</button></form>
                </details>
            @endif
        </section>
    @empty<p>{{ __('No embargo.') }}</p>@endforelse
    @if($canUpdate)
        <details><summary>{{ __('Impose an embargo') }}</summary>
            <form method="post" action="{{ route('records.embargoes.store', $record) }}">@csrf @include('records._embargo-fields', ['e' => null, 'p' => 'new'])
                <p><label><input type="checkbox" name="inside" value="1"> {{ __('And on everything inside it, each its own embargo') }}</label></p>
                <button type="submit">{{ __('Impose') }}</button></form>
        </details>
    @endif
</section>
