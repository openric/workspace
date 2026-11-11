{{-- OpenRiC Workspace - an order (Heratio's order confirmation; roadmap phase 19a): its lines, paying, its downloads once paid, its payments, and its status for who manages the shop. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Order :reference', ['reference' => $order->reference]))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p>@if($manages)<a href="{{ route('shop.orders') }}">{{ __('Orders') }}</a>@else<a href="{{ route('shop.orders.mine') }}">{{ __('My orders') }}</a>@endif</p>
    <h1>{{ __('Order :reference', ['reference' => $order->reference]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p><strong>{{ $order->status->label($lang) }}</strong> · {{ __('placed on :date', ['date' => $order->created_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i')]) }}@if($order->paid_at) · {{ __('paid on :date', ['date' => $order->paid_at->format('Y-m-d')]) }}@endif</p>
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('Quantity') }}</th><th scope="col">{{ __('Price') }}</th><th scope="col">{{ __('Downloads') }}</th></tr></thead>
        <tbody>@foreach($order->items as $item)<tr><td>{{ $item->record_title }}</td><td>{{ $item->product_name }}</td><td>{{ $item->quantity }}</td><td>{{ $order->currency }} {{ number_format((float) $item->line_total, 2) }}</td>
            <td>@if($order->user_id === auth()->id() && ($d = $item->downloads->first()))
                    @if($d->expires_at->isPast() || $d->download_count >= $d->max_downloads){{ __('no longer open') }}@else
                        @foreach($shop->deliverables($item) as $f)<a href="{{ route('shop.download', [$shop->downloadToken($item), $f->id]) }}">{{ $f->original_name }}</a>@if(! $loop->last), @endif @endforeach
                        <br><small>{{ __('until :date; :left downloads left', ['date' => $d->expires_at->format('Y-m-d'), 'left' => $d->max_downloads - $d->download_count]) }}</small>@endif
                @endif</td></tr>@endforeach</tbody></table>
    <p>{{ __('Total') }}: <strong>{{ $order->currency }} {{ number_format((float) $order->total, 2) }}</strong> ({{ __('including VAT of :currency :amount', ['currency' => $order->currency, 'amount' => number_format((float) $order->vat_amount, 2)]) }})</p>
    @if($order->status->notation === 'pending' && $order->user_id === auth()->id())
        <form method="post" action="{{ route('shop.orders.pay', $order) }}">@csrf<button type="submit">{{ __('Pay with PayFast') }}</button></form>
    @endif
    <h2>{{ __('Details') }}</h2>
    <dl><dt>{{ __('Name') }}</dt><dd>{{ $order->customer_name }}</dd><dt>{{ __('Email') }}</dt><dd>{{ $order->customer_email }}</dd>
        @if($order->customer_phone)<dt>{{ __('Telephone') }}</dt><dd>{{ $order->customer_phone }}</dd>@endif
        @if($order->billing_address)<dt>{{ __('Billing address') }}</dt><dd style="white-space: pre-line">{{ $order->billing_address }}</dd>@endif
        @if($order->shipping_address)<dt>{{ __('Address to send the prints to') }}</dt><dd style="white-space: pre-line">{{ $order->shipping_address }}</dd>@endif
        @if($order->notes)<dt>{{ __('Notes') }}</dt><dd>{{ $order->notes }}</dd>@endif</dl>
    @if($manages)
        <h2>{{ __('Payments') }}</h2>
        @forelse($order->payments as $p)<p>{{ $p->gateway }} · {{ $p->status->label($lang) }}@if($p->amount !== null) · {{ $p->currency }} {{ number_format((float) $p->amount, 2) }}@endif @if($p->transaction_id) · {{ $p->transaction_id }}@endif @if($p->refused_because) · <strong>{{ __('refused: :why', ['why' => $p->refused_because]) }}</strong>@endif · {{ $p->created_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</p>
        @empty<p>{{ __('None.') }}</p>@endforelse
        <form method="post" action="{{ route('shop.orders.status', $order) }}">@csrf
            <label for="o-status">{{ __('Change the status to') }}</label> <select id="o-status" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}">{{ $s->label($lang) }}</option>@endforeach</select>
            <button type="submit">{{ __('Change') }}</button></form>
    @endif
@endsection
