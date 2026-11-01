{{-- OpenRiC Workspace - a reproduction request (Heratio's viewReproduction; roadmap phase 19c): its copies and their cost, sending it, and for staff its steps, payment, handling and the files made. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $reproduction->reference)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($state = $reproduction->status->notation)
    <p><a href="{{ route('research.reproductions.index') }}">{{ __('Reproductions') }}</a></p>
    <h1>{{ $reproduction->reference }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p><strong>{{ $reproduction->status->label($lang) }}</strong> · {{ $reproduction->deliveryMethod->label($lang) }}
        @if($reproduction->estimated_cost !== null) · {{ __('estimated :currency :amount', ['currency' => $reproduction->currency, 'amount' => number_format((float) $reproduction->estimated_cost, 2)]) }}@endif
        @if($reproduction->final_cost !== null) · {{ __('to pay :currency :amount', ['currency' => $reproduction->currency, 'amount' => number_format((float) $reproduction->final_cost, 2)]) }}@endif
        @if($reproduction->invoice_number) · {{ __('invoice :n of :date', ['n' => $reproduction->invoice_number, 'date' => $reproduction->invoice_date?->format('Y-m-d')]) }}@endif
        @if($reproduction->payment_date) · {{ __('paid on :date', ['date' => $reproduction->payment_date->format('Y-m-d')]) }}@if($reproduction->payment_reference) ({{ $reproduction->payment_reference }})@endif @endif</p>
    @if($staff)<p>{{ __('Researcher') }}: <a href="{{ route('reading-room.researchers.show', $reproduction->researcher) }}">{{ $reproduction->researcher->first_name }} {{ $reproduction->researcher->last_name }}</a></p>@endif
    <dl>@foreach(['purpose' => 'Purpose', 'intended_use' => 'Intended use', 'publication_details' => 'Publication details', 'delivery_address' => 'Address', 'delivery_email' => 'Email', 'notes' => 'Notes', 'closure_reason' => 'Cancelled because'] as $f => $l)@if($reproduction->{$f})<dt>{{ __($l) }}</dt><dd style="white-space: pre-line">{{ $reproduction->{$f} }}</dd>@endif @endforeach</dl>

    <h2>{{ __('Copies') }}</h2>
    @forelse($reproduction->items as $i)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ \App\Relations\EntityKinds::label($i->record, $lang) }}</strong>@if($code = $i->record->referenceCode()) <span class="meta">{{ $code }}</span>@endif ·
                {{ $i->reproductionType->label($lang) }} × {{ $i->quantity }}@if($i->colourMode) · {{ $i->colourMode->label($lang) }}@endif @if($i->resolution) · {{ $i->resolution }}@endif @if($i->format) · {{ $i->format }}@endif @if($i->page_range) · {{ __('pages :range', ['range' => $i->page_range]) }}@endif
                · {{ $i->status->label($lang) }}@if($i->total_price !== null) · {{ $reproduction->currency }} {{ number_format((float) $i->total_price, 2) }}@endif
                @if($i->special_instructions)<br><small>{{ $i->special_instructions }}</small>@endif</p>
            @foreach($i->files as $f)<p style="margin-left: 1rem">@if($reproduction->researcher->user_id === auth()->id() && (! $f->download_expires_at || $f->download_expires_at->isFuture()))<a href="{{ route('research.reproductions.download', $service->downloadToken($i, $f->research_file_id)) }}">{{ $f->file->original_name }}</a>@else{{ $f->file->original_name }}@endif
                <small>· {{ trans_choice(':count download|:count downloads', $f->download_count, ['count' => $f->download_count]) }}@if($f->download_expires_at) · {{ __('until :date', ['date' => $f->download_expires_at->format('Y-m-d')]) }}@endif</small></p>@endforeach
            @if($state === 'draft' && ! $staff)<form method="post" action="{{ route('research.reproductions.item', [$i, 'remove']) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif
            @if($staff && in_array($state, ['processing', 'awaiting-payment', 'in-production'], true))
                <form method="post" action="{{ route('research.reproductions.item', [$i, 'finish']) }}" enctype="multipart/form-data">@csrf
                    <select name="status" aria-label="{{ __('Status of the copy') }}">@foreach(['in-progress', 'completed', 'cancelled'] as $s)<option value="{{ $s }}" @selected($i->status->notation === $s)>{{ \App\Models\Concept::firstWhere('iri', \App\Support\Vocab::BASE.'reproduction-item-status/'.$s)?->label($lang) }}</option>@endforeach</select>
                    <input type="file" name="files[]" multiple aria-label="{{ __('The files made') }}"> <button type="submit">{{ __('Save') }}</button></form>
            @endif
        </section>
    @empty<p>{{ __('None yet.') }}</p>@endforelse

    @if($state === 'draft' && ! $staff)
        <details open><summary>{{ __('Add a copy') }}</summary>
            <form method="post" action="{{ route('research.reproductions.step', [$reproduction, 'item']) }}">@csrf
                <p><label for="it-iri">{{ __('The record (its persistent IRI)') }}</label> <input id="it-iri" name="iri" required size="50" value="{{ request('iri') }}"></p>
                <p><label for="it-type">{{ __('Kind') }}</label> <select id="it-type" name="reproduction_type">@foreach($types as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select>
                    <label for="it-qty">{{ __('How many (pages, images or files)') }}</label> <input id="it-qty" type="number" name="quantity" min="1" value="1" style="width: 5rem">
                    <label for="it-colour">{{ __('Colour') }}</label> <select id="it-colour" name="colour_mode"><option value="">-</option>@foreach($colours as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select></p>
                <p><label for="it-format">{{ __('Format') }}</label> <input id="it-format" name="format" size="10" placeholder="TIFF"> <label for="it-res">{{ __('Resolution') }}</label> <input id="it-res" name="resolution" size="10" placeholder="300 dpi">
                    <label for="it-pages">{{ __('Pages') }}</label> <input id="it-pages" name="page_range" size="10"></p>
                <p><label for="it-instr">{{ __('Special instructions') }}</label> <input id="it-instr" name="special_instructions" size="60"></p>
                <button type="submit">{{ __('Add the copy') }}</button></form>
        </details>
        @if($reproduction->items->isNotEmpty())<form method="post" action="{{ route('research.reproductions.step', [$reproduction, 'submit']) }}">@csrf<button type="submit">{{ __('Send the request') }}</button></form>@endif
    @endif

    @if($staff && ! in_array($state, ['draft', 'completed', 'cancelled'], true))
        <h2>{{ __('Steps') }}</h2>
        <form method="post" action="{{ route('research.reproductions.step', [$reproduction, 'status']) }}">@csrf
            <select name="status" aria-label="{{ __('Next step') }}">@foreach(['processing', 'awaiting-payment', 'in-production', 'completed', 'cancelled'] as $s)<option value="{{ $s }}">{{ \App\Models\Concept::firstWhere('iri', \App\Research\Reproductions::STATUSES.$s)?->label($lang) }}</option>@endforeach</select>
            <input name="notes" size="40" aria-label="{{ __('Notes') }}" placeholder="{{ __('Notes (the reason, to cancel)') }}"> <button type="submit">{{ __('Move on') }}</button></form>
        @if(in_array($state, ['processing', 'awaiting-payment'], true))
            <form method="post" action="{{ route('research.reproductions.step', [$reproduction, 'payment']) }}">@csrf
                <label for="py-amount">{{ __('Paid') }}</label> <input id="py-amount" name="amount" type="number" step="0.01" min="0" value="{{ $reproduction->final_cost ?? $reproduction->estimated_cost }}" style="width: 8rem">
                <label for="py-ref">{{ __('Reference') }}</label> <input id="py-ref" name="reference" size="16"> <label for="py-method">{{ __('Method') }}</label> <input id="py-method" name="method" size="12">
                <label for="py-date">{{ __('On') }}</label> <input id="py-date" type="date" name="date" value="{{ now()->format('Y-m-d') }}"> <button type="submit">{{ __('Record the payment') }}</button></form>
        @endif
        <details><summary>{{ __('Handling') }}</summary>
            <form method="post" action="{{ route('research.reproductions.step', [$reproduction, 'handle']) }}">@csrf
                <p><label for="hd-cost">{{ __('Cost to pay') }}</label> <input id="hd-cost" name="final_cost" type="number" step="0.01" min="0" value="{{ $reproduction->final_cost }}" style="width: 8rem">
                    <label for="hd-due">{{ __('Due by') }}</label> <input id="hd-due" type="date" name="sla_due_on" value="{{ $reproduction->sla_due_on?->format('Y-m-d') }}"></p>
                <p><label for="hd-notes">{{ __('Staff notes') }}</label><br><textarea id="hd-notes" name="admin_notes" rows="2" cols="70">{{ $reproduction->admin_notes }}</textarea></p>
                <button type="submit">{{ __('Save') }}</button></form>
        </details>
    @endif
    <details><summary>{{ __('History') }}</summary>@foreach($reproduction->statusChanges as $c)<p><small>{{ $c->created_at->format('Y-m-d H:i') }} · {{ $c->old_status ? __(':from to :to', ['from' => $c->old_status, 'to' => $c->new_status]) : $c->new_status }}@if($c->changedBy) · {{ $c->changedBy->name }}@endif @if($c->notes) · {{ $c->notes }}@endif</small></p>@endforeach</details>
@endsection
