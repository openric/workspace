{{-- OpenRiC Workspace - AI: the gateway, the institution's settings and this month's calls (Heratio's AI services and governance pages; roadmap phase 17a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('AI'))
@section('content')
    <h1>{{ __('AI') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Every model the Workspace asks is reached through the AHG AI gateway. What a model says is never written to a description: it becomes a proposal, which a person accepts, corrects or rejects. Every call is kept in a log of inferences that is chained and signed, so it cannot be changed unnoticed.') }}</p>
    <p><strong>{{ __('Now') }}:</strong> {{ $refusal ?? __('AI is on for this institution.') }}</p>
    @unless($configured)<p class="meta">{{ __('The installation needs a key for the AI gateway, with the gateway scope, as AI_GATEWAY_KEY in its .env.') }}</p>@endunless

    <form method="post" action="{{ route('ai.save') }}">@csrf
        <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" style="width: auto" @checked($settings->enabled)> {{ __('Use AI for this institution') }}</label>
        <label for="ai-limit">{{ __('At most so many calls a month') }}</label><input id="ai-limit" name="monthly_limit" type="number" min="0" value="{{ $settings->monthly_limit }}" placeholder="{{ __('No limit beyond the gateway\'s') }}">
        <label for="ai-ocr">{{ __('Vision model that reads printed pages') }}</label><input id="ai-ocr" name="ocr_model" maxlength="200" value="{{ $settings->ocr_model }}" placeholder="{{ config('workspace.ai.ocr_model') }}">
        <label for="ai-ocr2">{{ __('Model that reads a strip again when the first disagrees with Tesseract') }}</label><input id="ai-ocr2" name="ocr_fallback_model" maxlength="200" value="{{ $settings->ocr_fallback_model }}" placeholder="{{ config('workspace.ai.ocr_fallback_model') }}">
        <button type="submit">{{ __('Save') }}</button></form>

    <h2>{{ __('This month') }}</h2>
    <p>{{ trans_choice(':count call|:count calls', $used, ['count' => $used]) }}@if($settings->monthly_limit !== null) {{ __('of :n', ['n' => $settings->monthly_limit]) }}@endif. <a href="{{ route('ai.inferences') }}">{{ __('The log of inferences') }}</a></p>
    @if($byService->isNotEmpty())
        <table><thead><tr><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Calls') }}</th><th scope="col">{{ __('Not answered') }}</th></tr></thead>
            <tbody>@foreach($byService as $row)<tr><td>{{ \App\Models\Concept::where('iri', \App\Support\Vocab::BASE.'ai-service/'.$row->notation)->first()?->label(\App\Support\Vocab::languages()) }}</td><td>{{ $row->n }}</td><td>{{ $row->failed }}</td></tr>@endforeach</tbody></table>
    @endif

    <h2>{{ __('The chain') }}</h2>
    @if($chain)<p role="status">{{ $chain['intact'] ? __('The log of inferences is intact: :n receipts, each hash and signature checked.', ['n' => $chain['rows']]) : __('The log of inferences is broken: :problem.', ['problem' => $chain['problem']]) }}</p>@endif
    <form method="post" action="{{ route('ai.verify') }}">@csrf<button type="submit">{{ __('Check every receipt') }}</button></form>
@endsection
