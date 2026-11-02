{{-- OpenRiC Workspace - a captured e-mail: what was read from it, its place in the file plan, and its declaration as a record (roadmap phase 13c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $email->subject ?? __('[No subject]'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('records-management.emails') }}">{{ __('Captured e-mail') }}</a></p>
    <h1>{{ $email->subject ?? __('[No subject]') }}</h1>
    @include('records-management._nav')
    <dl>
        <dt>{{ __('From') }}</dt><dd>{{ $email->from_address }}</dd>
        @if($email->to_addresses)<dt>{{ __('To') }}</dt><dd>{{ $email->to_addresses }}</dd>@endif
        @if($email->cc_addresses)<dt>{{ __('Cc') }}</dt><dd>{{ $email->cc_addresses }}</dd>@endif
        <dt>{{ __('Sent') }}</dt><dd>{{ $email->sent_at?->timezone(config('workspace.timezone'))->format('j F Y H:i') ?? '-' }}</dd>
        <dt>{{ __('Message ID') }}</dt><dd><code>{{ $email->message_id }}</code></dd>
        <dt>{{ __('As captured') }}</dt><dd><a href="{{ route('records-management.email.original', $email) }}">{{ $email->original_name }}</a> · {{ $email->source->label($lang) }} · {{ number_format($email->size_bytes / 1024, 1) }} KB · SHA-256 <code>{{ $email->sha256 }}</code></dd>
        @if($email->attachments)<dt>{{ __('Attachments') }}</dt><dd>@foreach($email->attachments as $a){{ $a['name'] }} ({{ $a['mime_type'] }}, {{ number_format($a['size_bytes'] / 1024, 1) }} KB)@if(! $loop->last); @endif @endforeach</dd>@endif
        <dt>{{ __('Status') }}</dt><dd>{{ $email->status->label($lang) }}@if($email->node) · {{ $email->node->code }} {{ $email->node->title }}@endif @if($email->disposalClass) · {{ $email->disposalClass->reference }}@endif
            @if($email->record) · <a href="{{ route('records.show', $email->record) }}">{{ __('the record') }}</a>@endif</dd>
    </dl>
    <pre style="white-space: pre-wrap; border: 1px solid var(--line); padding: .5rem">{{ $email->body_text ?? html_entity_decode(strip_tags((string) $email->body_html)) }}</pre>
    @unless($email->record)
        <h2>{{ __('Place it in the file plan') }}</h2>
        <form method="post" action="{{ route('records-management.email.step', [$email, 'classify']) }}">@csrf
            <label for="ec-node">{{ __('Node') }}</label> @include('description._ric', ['term' => null]) <select id="ec-node" name="file_plan_node_id" required>@foreach($nodes as $n)<option value="{{ $n->id }}" @selected($email->file_plan_node_id === $n->id)>{{ $n->code }} {{ $n->title }}</option>@endforeach</select>
            <label for="ec-class">{{ __('Disposal class') }}</label> @include('description._ric', ['term' => null]) <select id="ec-class" name="disposal_class_id"><option value="">{{ __('The node\'s') }}</option>@foreach($classes as $c)<option value="{{ $c->id }}" @selected($email->disposal_class_id === $c->id)>{{ $c->reference }} {{ $c->title }}</option>@endforeach</select>
            <button type="submit">{{ __('Place') }}</button></form>
        @if($email->node)
            <h2>{{ __('Declare it a record') }}</h2>
            <p>{{ __('It becomes an item described from the message, dated when it was sent, with the message as captured as its instantiation, in its place in the file plan.') }}</p>
            <form method="post" action="{{ route('records-management.email.step', [$email, 'declare']) }}">@csrf
                <label for="ed-parent">{{ __('Inside the record set (its persistent IRI), or at the top') }}</label> <input id="ed-parent" name="parent_iri" size="50">
                <label for="ed-lang">{{ __('Language') }}</label> <input id="ed-lang" name="language" size="5" value="{{ app()->getLocale() }}"> <button type="submit">{{ __('Declare') }}</button></form>
        @endif
    @endunless
@endsection
