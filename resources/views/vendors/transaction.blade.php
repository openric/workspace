{{-- OpenRiC Workspace - work sent to a vendor: its records, status, history and documents (Heratio's ahg-vendor view-transaction). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $transaction->transaction_number)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    @php($money = fn ($amount) => $amount === null ? '' : $transaction->currency?->notation.' '.number_format((float) $amount, 2))
    <h1>{{ $transaction->transaction_number }} @include('description._ric', ['term' => 'rico:Activity'])</h1>
    @include('vendors._nav')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <dl>
        <dt>{{ __('Vendor') }} @include('description._ric', ['term' => 'rico:isOrWasPerformedBy'])</dt><dd><a href="{{ route('vendors.show', $transaction->vendor) }}">{{ \App\Relations\EntityKinds::label($transaction->vendor->agent) }}</a></dd>
        <dt>{{ __('Service') }} @include('description._ric', ['term' => 'rico:hasActivityType'])</dt><dd>{{ $transaction->serviceType->label($lang) }}
            @if($service?->requires_insurance) · {{ __('needs insurance') }}@if(! $insured && ! $transaction->insurance_arranged) <strong>{{ __('(none in force or arranged)') }}</strong>@endif @endif
            @if($service?->requires_valuation) · {{ __('needs a valuation') }}@endif</dd>
        <dt>{{ __('Status') }}</dt><dd>{{ $transaction->status->label($lang) }}@if($transaction->overdue()) · <strong>{{ __('overdue') }}</strong>@endif</dd>
        <dt>{{ __('Dates') }}</dt><dd>{{ collect([__('requested :date', ['date' => $transaction->request_date->format('Y-m-d')]),
            $transaction->approval_date ? __('approved :date', ['date' => $transaction->approval_date->format('Y-m-d')]) : null,
            $transaction->dispatch_date ? __('sent :date', ['date' => $transaction->dispatch_date->format('Y-m-d')]) : null,
            $transaction->expected_return_date ? __('due back :date', ['date' => $transaction->expected_return_date->format('Y-m-d')]) : null,
            $transaction->completion_date ? __('work done :date', ['date' => $transaction->completion_date->format('Y-m-d')]) : null,
            $transaction->actual_return_date ? __('back :date', ['date' => $transaction->actual_return_date->format('Y-m-d')]) : null])->filter()->join(' · ') }}</dd>
        <dt>{{ __('Cost and payment') }}</dt><dd>{{ collect([$transaction->estimated_cost ? __('estimated :amount', ['amount' => $money($transaction->estimated_cost)]) : null,
            $transaction->actual_cost ? __('actual :amount', ['amount' => $money($transaction->actual_cost)]) : null, $transaction->paymentStatus->label($lang),
            $transaction->quote_reference ? __('quote :ref', ['ref' => $transaction->quote_reference]) : null, $transaction->invoice_reference ? __('invoice :ref', ['ref' => $transaction->invoice_reference]) : null])->filter()->join(' · ') }}</dd>
        @if($transaction->total_insured_value || $transaction->insurance_arranged)<dt>{{ __('Insurance') }}</dt><dd>{{ collect([$money($transaction->total_insured_value), $transaction->insurance_arranged ? __('arranged for this work') : null, $transaction->insurance_reference])->filter()->join(' · ') }}</dd>@endif
        @if($transaction->courier_company || $transaction->tracking_number || $transaction->shipping_method)<dt>{{ __('Shipping') }}</dt><dd>{{ collect([$transaction->shipping_method, $transaction->courier_company, $transaction->tracking_number])->filter()->join(' · ') }}</dd>@endif
        @foreach(['dispatch_notes' => __('Dispatch notes'), 'vendor_notes' => __('The vendor\'s notes'), 'return_notes' => __('Return notes'), 'internal_notes' => __('Internal notes')] as $f => $label)
            @if($transaction->{$f})<dt>{{ $label }}</dt><dd>{{ $transaction->{$f} }}</dd>@endif
        @endforeach
    </dl>
    @if($canUpdate)
        <p><a href="{{ route('vendors.transaction.edit', $transaction) }}">{{ __('Edit') }}</a></p>
        @unless(in_array($transaction->status->notation, \App\Vendors\Vendors::CLOSED, true))
            <form method="post" action="{{ route('vendors.transaction.status', $transaction) }}">
                @csrf
                <label for="status">{{ __('Move it on to') }}</label>
                <select id="status" name="status">@foreach($statuses->reject(fn ($s) => $s->id === $transaction->status_id) as $s)<option value="{{ $s->notation }}">{{ $s->label($lang) }}</option>@endforeach</select>
                <label for="status-notes">{{ __('Notes') }}</label><input id="status-notes" name="notes" maxlength="1000">
                <button type="submit">{{ __('Change the status') }}</button>
            </form>
        @endunless
    @endif

    <h2>{{ __('Records') }} @include('description._ric', ['term' => 'rico:affectsOrAffected'])</h2>
    @forelse($transaction->items as $item)
        <section style="border-top: 1px solid var(--line); padding: .5rem 0">
            <h3 style="margin: .2rem 0"><a href="{{ route('records.show', $item->record) }}">{{ \App\Relations\EntityKinds::label($item->record) }}</a>@if($item->record->identifier) ({{ $item->record->identifier }})@endif</h3>
            <p>{{ collect([$item->gradeBefore ? __('before: :grade', ['grade' => $item->gradeBefore->label($lang)]) : null, $item->gradeAfter ? __('after: :grade', ['grade' => $item->gradeAfter->label($lang)]) : null,
                $item->declared_value ? __('declared value :amount', ['amount' => $money($item->declared_value)]) : null, $item->service_completed ? __('work done') : null,
                $item->dispatched_at ? __('sent :date', ['date' => $item->dispatched_at->timezone($tz)->format('Y-m-d')]) : null, $item->returned_at ? __('back :date', ['date' => $item->returned_at->timezone($tz)->format('Y-m-d')]) : null])->filter()->join(' · ') }}</p>
            @if($canUpdate)
                <details><summary>{{ __('Condition and work') }}</summary>
                    <form method="post" action="{{ route('vendors.items.update', [$transaction, $item]) }}">@csrf @include('vendors._item', ['item' => $item, 'x' => 'i'.$loop->index]) <button type="submit">{{ __('Save') }}</button></form>
                    @unless($item->dispatched_at)<form method="post" action="{{ route('vendors.items.remove', [$transaction, $item]) }}">@csrf<button type="submit">{{ __('Remove from this work') }}</button></form>@endunless
                </details>
            @endif
        </section>
    @empty
        <p>{{ __('No records yet.') }}</p>
    @endforelse
    @if($canUpdate && ! $transaction->dispatch_date)
        <form method="get" action="{{ route('vendors.transaction', $transaction) }}"><label for="q">{{ __('Find a record to send, by title or identifier') }}</label><input id="q" name="q" value="{{ $q }}" maxlength="200"> <button type="submit">{{ __('Find') }}</button></form>
        @if($q !== '')
            <form method="post" action="{{ route('vendors.items.add', $transaction) }}">
                @csrf
                <fieldset><legend>{{ __('Record') }}</legend>
                    @forelse($candidates as $c)<label><input type="radio" name="record" value="{{ $c->id }}" required @checked($loop->first)> {{ \App\Relations\EntityKinds::label($c) }}@if($c->identifier) ({{ $c->identifier }})@endif</label>@empty<p>{{ __('Nothing found. Try part of a title or an identifier.') }}</p>@endforelse
                </fieldset>
                @if($candidates->isNotEmpty())@include('vendors._item', ['item' => null, 'x' => 'new']) <button type="submit">{{ __('Add the record') }}</button>@endif
            </form>
        @endif
    @endif

    <h2>{{ __('Documents') }}</h2>
    @forelse($transaction->attachments as $a)
        <p><a href="{{ route('vendors.attachment', [$transaction, $a]) }}">{{ $a->original_name }}</a> · {{ $a->type->label($lang) }} · {{ \Illuminate\Support\Number::fileSize($a->size_bytes, 1) }}@if($a->description) · {{ $a->description }}@endif</p>
    @empty
        <p>{{ __('No documents yet.') }}</p>
    @endforelse
    @if($canUpdate)
        <form method="post" action="{{ route('vendors.attach', $transaction) }}" enctype="multipart/form-data">
            @csrf
            <label for="doc-type">{{ __('Kind of document') }}</label><select id="doc-type" name="type">@foreach($attachmentTypes as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select>
            <label for="doc-file">{{ __('File') }}</label><input type="file" id="doc-file" name="file" required>
            <label for="doc-description">{{ __('Description') }}</label><input id="doc-description" name="description" maxlength="1000">
            <button type="submit">{{ __('Keep the document') }}</button>
        </form>
    @endif

    <h2>{{ __('History') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('To') }}</th><th scope="col">{{ __('By') }}</th><th scope="col">{{ __('Notes') }}</th></tr></thead>
        <tbody>@foreach($transaction->history as $h)<tr><td>{{ $h->created_at->timezone($tz)->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</td><td>{{ $h->from?->label($lang) }}</td><td>{{ $h->to->label($lang) }}</td><td>{{ $h->changedBy?->name }}</td><td>{{ $h->notes }}</td></tr>@endforeach</tbody></table>
@endsection
