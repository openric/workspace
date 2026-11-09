{{-- OpenRiC Workspace - a conversation with the catalogue's assistant (roadmap phase 17f), after Heratio's ahg-ai-chatbot page: each answer marked as written by AI, with its sources linked and numbered as it cites them, a warning where it does not rest on them, and a way to flag it; a question asked by an ordinary form. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $messages, $public. --}}
@php($ask = $public ? 'discover.ask' : 'ask')
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
<p class="meta">{{ __('The assistant answers from the records you may read and cites them. Its answers are written by AI and may be wrong: check them against the records.') }}
    <a href="{{ route('accessibility.statement') }}">{{ __('Accessibility') }}</a></p>
<ol class="chat" aria-label="{{ __('The conversation') }}" style="list-style: none; padding: 0">
    @forelse($messages as $m)
        @php($assistant = $m->role->notation === 'assistant')
        <li @if($loop->last) id="latest" tabindex="-1" @endif style="margin: .75rem 0; padding: .6rem .8rem; border: 1px solid var(--line); {{ $assistant ? 'background: var(--panel, #f6f6f6)' : '' }}">
            <p class="meta" style="margin: 0 0 .3rem">{{ $assistant ? __('Assistant (written by AI)') : __('You') }}</p>
            <div style="white-space: pre-line">{{ $m->content }}</div>
            @if($assistant && $m->sources)
                <p class="meta" style="margin: .5rem 0 0">{{ __('Sources') }}</p>
                <ol style="margin: .2rem 0 0">@foreach($m->sources as $s)<li>@if($s['url'])<a href="{{ $s['url'] }}">{{ $s['label'] }}</a>@else{{ $s['label'] }}@endif</li>@endforeach</ol>
            @endif
            @if($assistant && isset($m->guardrail['grounded']) && ! $m->guardrail['grounded'])
                <p role="note" style="margin: .4rem 0 0"><strong>{{ __('Check this answer:') }}</strong> {{ __('much of it is not found in the sources above.') }}</p>
            @endif
            @if($assistant && $m->inference_id)
                @if($m->flagged_at)
                    <p class="meta" style="margin: .4rem 0 0">{{ __('Flagged for review.') }}</p>
                @else
                    <details style="margin-top: .4rem"><summary>{{ __('Report this answer as wrong') }}</summary>
                        <form method="post" action="{{ route($ask.'.flag', $m) }}">@csrf
                            <label for="note-{{ $m->id }}">{{ __('What is wrong (optional)') }}</label><textarea id="note-{{ $m->id }}" name="note" rows="2" maxlength="2000"></textarea>
                            <button type="submit">{{ __('Report') }}</button></form>
                    </details>
                @endif
            @endif
        </li>
    @empty
        <li>{{ __('Ask a question about the collection, such as who created a set of records, or what is held about a place or a year.') }}</li>
    @endforelse
</ol>
@if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
<form method="post" action="{{ route($ask.'.store') }}">@csrf
    <label for="question">{{ __('Your question') }}</label>
    <textarea id="question" name="question" rows="3" maxlength="2000" required>{{ old('question') }}</textarea>
    <button type="submit">{{ __('Ask') }}</button>
</form>
<form method="post" action="{{ route($ask.'.restart') }}" style="margin-top: .5rem">@csrf<button type="submit">{{ __('Start a new conversation') }}</button></form>
@if(session('asked'))
    {{-- After a question, the focus moves to the answer, so a screen reader reads it next. --}}
    <script>document.getElementById('latest')?.focus();</script>
@endif
