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
        <label for="ai-chat">{{ __('Language model that writes summaries') }}</label><input id="ai-chat" name="chat_model" maxlength="200" value="{{ $settings->chat_model }}" placeholder="{{ config('workspace.ai.chat_model') }}">
        <label for="ai-tr">{{ __('Language descriptions are translated into by default') }}</label>
        <select id="ai-tr" name="translation_target"><option value="">{{ __('The other of English and Afrikaans') }}</option>@foreach((array) config('workspace.ai.translation_targets') as $code)<option value="{{ $code }}" @selected($settings->translation_target === $code)>{{ \App\Support\InterfaceLanguages::name($code) }} ({{ $code }})</option>@endforeach</select>
        <fieldset><legend>{{ __('Elements offered for translation') }}</legend>
            @foreach(app(\App\Ai\Translate::class)->elements() as $e)<label><input type="checkbox" name="translation_elements[]" value="{{ $e->notation }}" style="width: auto" @checked(in_array($e->notation, $settings->translation_elements ?: \App\Ai\Translate::DEFAULT_ELEMENTS, true))> {{ $e->label(\App\Support\Vocab::languages()) }}</label>@endforeach
        </fieldset>
        <fieldset><legend>{{ __('Search') }}</legend>
            @foreach(['by_meaning' => __('Search by meaning as well as by the words (each entry of the index turned into a vector by the gateway)'),
                'expand_synonyms' => __('Widen a search with the other labels of the concepts its words name'), 'expand_with_model' => __('Widen a search with terms a language model suggests'),
                'rerank_history' => __('Put first, for a signed-in account, what matches its recent searches')] as $key => $label)
                <label><input type="hidden" name="search[{{ $key }}]" value="0"><input type="checkbox" name="search[{{ $key }}]" value="1" style="width: auto" @checked($search->{$key})> {{ $label }}</label>
            @endforeach
            <label for="ai-exp">{{ __('At most so many terms added to a search') }}</label><input id="ai-exp" name="search[expansion_limit]" type="number" min="0" max="20" value="{{ $search->expansion_limit }}">
            <label for="ai-sim">{{ __('How alike an entry must be to be found by meaning (0 to 0.99)') }}</label><input id="ai-sim" name="search[min_similarity]" type="number" min="0" max="0.99" step="0.01" value="{{ $search->min_similarity }}">
            <label for="ai-emb">{{ __('Embedding model') }}</label><input id="ai-emb" name="search[embedding_model]" maxlength="200" value="{{ $search->embedding_model }}" placeholder="{{ \App\Search\Meaning::DEFAULT_MODEL }}">
            <p class="meta">{{ __(':vectors of :entries entries of the search index are turned into vectors; the rest are every ten minutes, while search by meaning is on.', ['vectors' => $vectors, 'entries' => $entries]) }}</p>
        </fieldset>
        {{-- Annif (phase 17e): subjects suggested from the institution's vocabulary, each waiting for review. --}}
        <fieldset id="annif"><legend>{{ __('Subjects suggested by Annif') }}</legend>
            <p class="meta">{{ __('Annif runs behind the AI gateway. Each subject it suggests for a record waits in the review queue; the subjects reviewers accept are taught back to it each night.') }}</p>
            <p><label for="ai-annif-project">{{ __('Annif project') }}</label> <input id="ai-annif-project" name="annif_project" maxlength="100" value="{{ old('annif_project', $settings->annif_project) }}" placeholder="openric-institution-subjects">
                <label for="ai-annif-vocabulary">{{ __('The vocabulary it suggests from') }}</label> <select id="ai-annif-vocabulary" name="annif_vocabulary_id"><option value="">{{ __('None') }}</option>@foreach($annifVocabularies as $v)<option value="{{ $v->id }}" @selected($settings->annif_vocabulary_id === $v->id)>{{ $v->title }}</option>@endforeach</select></p>
            <p><label for="ai-annif-limit">{{ __('At most so many subjects a record') }}</label> <input id="ai-annif-limit" name="annif_limit" type="number" min="1" max="20" value="{{ $settings->annif_limit ?? 5 }}" style="width: 5rem">
                <label for="ai-annif-threshold">{{ __('How sure it must be (0 to 0.99)') }}</label> <input id="ai-annif-threshold" name="annif_threshold" type="number" step="0.01" min="0" max="0.99" value="{{ $settings->annif_threshold ?? 0.2 }}" style="width: 5rem"></p>
        </fieldset>
        {{-- The catalogue's assistant (phase 17f): staff may ask it while AI is on; the public only when chosen here. --}}
        <fieldset id="assistant"><legend>{{ __('The catalogue\'s assistant') }}</legend>
            <p class="meta">{{ __('Answers questions from the records the reader may read, citing them; its answers are marked as written by AI, and readers may flag one as wrong for review.') }}</p>
            <p><label><input type="checkbox" name="chatbot_public" value="1" @checked($settings->chatbot_public)> {{ __('Offer it in the public catalogue') }}</label></p>
            <label for="ai-chat-days">{{ __('Delete a conversation so many days after its last message') }}</label><input id="ai-chat-days" name="chatbot_retention_days" type="number" min="1" max="3650" value="{{ $settings->chatbot_retention_days ?? 90 }}">
            <label for="ai-chat-rules">{{ __('Instructions to the assistant (empty: the Workspace\'s own)') }}</label>
            <textarea id="ai-chat-rules" name="chatbot_instructions" rows="6" maxlength="5000" placeholder="{{ \Illuminate\Support\Str::limit(\App\Ai\Chatbot::RULES, 300) }}">{{ $settings->chatbot_instructions }}</textarea>
            <p><a href="{{ route('ai.compliance') }}">{{ __('AI register and compliance') }}</a> · <a href="{{ route('ai.answers') }}">{{ __('Flagged answers') }}</a></p>
        </fieldset>
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
    <h2>{{ __('Annif trials') }}</h2>
    <p>{{ __('Before its suggestions are relied on, try the project on published records that already have subjects of the vocabulary: precision is how many of its first five were subjects the record has, recall how many of the subjects it has it found.') }}</p>
    @if($settings->annif_project && $settings->annif_vocabulary_id)<form method="post" action="{{ route('ai.annif-trial') }}">@csrf<button type="submit">{{ __('Try Annif now') }}</button></form>@endif
    @if($annifTrials->isEmpty())<p>{{ __('None yet.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Project') }}</th><th scope="col">{{ __('Records') }}</th><th scope="col">{{ __('Precision') }}</th><th scope="col">{{ __('Recall') }}</th></tr></thead>
        <tbody>@foreach($annifTrials as $t)<tr><td>{{ \Illuminate\Support\Carbon::parse($t->run_at)->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</td><td>{{ $t->project }}</td><td>{{ $t->records }}</td><td>{{ number_format($t->precision, 2) }}</td><td>{{ number_format($t->recall, 2) }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
