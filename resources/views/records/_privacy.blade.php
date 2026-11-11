{{-- OpenRiC Workspace - a unit of description's privacy (roadmap phase 18b, ADR 0016), after Heratio's PII scan of an object and its field redaction panel (ahg-privacy): its latest scan for personal information and what it found, its fields redacted for the public, and, where screening is required, whether its files reach the public. Needs: $record. Shown to who may manage privacy. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($screening = app(\App\Privacy\Screening::class))
@php($latest = $screening->latest($record)?->load('jurisdiction', 'scannedBy', 'decidedBy'))
@php($required = $screening->requiresScreening($record))
@php($redactions = app(\App\Privacy\Redaction::class)->of($record))
@php($jurisdictions = \App\Http\Controllers\PrivacyScreeningController::jurisdictions())
@php($fieldLabel = fn (string $n) => \App\Models\Concept::firstWhere('iri', \App\Support\Vocab::element($n))?->label($lang) ?? $n)
<section id="privacy">
    <h2>{{ __('Privacy') }} @include('description._ric', ['term' => null])</h2>
    @error('privacy')<p class="error" role="alert">{{ $message }}</p>@enderror
    @error('redaction')<p class="error" role="alert">{{ $message }}</p>@enderror
    @error('screening')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($required)
        <p><strong>{{ __('Its files must be screened before the public receives them.') }}</strong>
            {{ $screening->cleared($record) ? __('They are cleared.') : __('They are withheld from the public until they are cleared.') }}</p>
    @endif

    <h3>{{ __('Personal information found') }}</h3>
    @if($latest === null)
        <p>{{ __('Not scanned yet.') }}</p>
    @else
        <p>{{ __('Scanned on :date under :law by :who', ['date' => $latest->started_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i'), 'law' => $latest->jurisdiction->name, 'who' => $latest->scannedBy?->name ?? '-']) }}
            · {{ $latest->coverage->label($lang) }} · <strong>{{ $latest->status->label($lang) }}</strong>
            @if($latest->decidedBy) ({{ __('by :who on :date', ['who' => $latest->decidedBy->name, 'date' => $latest->decided_at->format('Y-m-d')]) }})@endif
            @if($latest->finished_at === null)<br><span class="error">{{ __('A pattern could not be evaluated; the scan is not complete.') }}</span>@endif
            @if($latest->notes)<br><small style="white-space: pre-line">{{ $latest->notes }}</small>@endif</p>
        @if($latest->hits_total === 0)
            <p>{{ __('Nothing found.') }}</p>
        @else
            <p>{{ trans_choice(':count finding|:count findings', $latest->hits_total, ['count' => $latest->hits_total]) }}:
                @foreach((array) $latest->hits_by_type as $type => $n){{ $type }} {{ $n }}@if(! $loop->last), @endif @endforeach</p>
            <details><summary>{{ __('What was found, and where') }}</summary>
                <table><thead><tr><th scope="col">{{ __('Field') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Value') }}</th><th scope="col">{{ __('Confidence') }}</th></tr></thead>
                    <tbody>@foreach((array) $latest->findings as $f)<tr><td>{{ $f['field'] }}</td><td>{{ $f['type'] }}</td><td><code>{{ $f['value'] }}</code></td><td>{{ $f['confidence'] }}</td></tr>@endforeach</tbody></table>
            </details>
        @endif
    @endif
    <form method="post" action="{{ route('records.privacy.scan', $record) }}">@csrf
        <label for="pii-law">{{ __('Look for personal information under') }}</label> @include('description._ric', ['term' => null])
        <select id="pii-law" name="jurisdiction">@foreach($jurisdictions as $j)<option value="{{ $j->code }}" @selected($j->code === ($latest?->jurisdiction->code ?? 'popia'))>{{ $j->name }} ({{ $j->country }})</option>@endforeach</select>
        <button type="submit">{{ $latest ? __('Scan again') : __('Scan for personal information') }}</button></form>

    @if($required || $latest)
        <details><summary>{{ __('Release or withhold its files') }}</summary>
            <form method="post" action="{{ route('records.privacy.files', $record) }}">@csrf
                <p><label for="files-decision">{{ __('Decision') }}</label> @include('description._ric', ['term' => null])
                    <select id="files-decision" name="decision" required>
                        <option value="release">{{ __('Release them: the risk is accepted') }}</option>
                        <option value="redacted">{{ __('Release them: what was found is redacted') }}</option>
                        <option value="withhold">{{ __('Withhold them from the public') }}</option></select>
                    <label for="files-ground">{{ __('Ground') }}</label> @include('description._ric', ['term' => null])
                    <select id="files-ground" name="ground">@foreach($jurisdictions as $j)<option value="{{ $j->code }}" @selected($j->code === ($latest?->jurisdiction->code ?? 'popia'))>{{ $j->name }}</option>@endforeach</select></p>
                <p><label for="files-note">{{ __('Why (required to release them)') }}</label> @include('description._ric', ['term' => null])<br><textarea id="files-note" name="note" rows="2" cols="60"></textarea></p>
                <button type="submit">{{ __('Decide') }}</button></form>
        </details>
    @endif

    <h3>{{ __('Fields redacted for the public') }}</h3>
    <p class="meta">{{ __('The public, and accounts that may not change the record, read the redacted value; the record itself keeps the original.') }}</p>
    @forelse($redactions as $r)
        <p><strong>{{ $fieldLabel($r->field) }}</strong> · {{ $r->type->label($lang) }}@if($r->pattern) ({{ $r->pattern->label($lang) }})@endif · {{ $r->reason }}@if($r->legal_basis) · {{ $r->legal_basis }}@endif @if($r->is_sensitive) · {{ __('special personal information') }}@endif
            <form method="post" action="{{ route('records.privacy.unredact', [$record, $r]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form></p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('Redact a field') }}</summary>
        <form method="post" action="{{ route('records.privacy.redact', $record) }}">@csrf
            <p><label for="red-field">{{ __('Field') }}</label> <small class="ric">{{ __('each field with its RiC term') }}</small>
                <select id="red-field" name="field" required>@foreach(app(\App\Privacy\Redaction::class)->fieldsOf($record) as $n)@php($c = \App\Models\Concept::firstWhere('iri', \App\Support\Vocab::element($n)))<option value="{{ $n }}">{{ $c?->label($lang) ?? $n }}@if($c?->ricTerm()) ({{ $c->ricTerm() }})@endif</option>@endforeach</select>
                <label for="red-type">{{ __('How') }}</label> @include('description._ric', ['term' => null])
                <select id="red-type" name="type" required>@foreach(\App\Models\Concept::choices(\App\Privacy\Redaction::TYPES)->get() as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select>
                <label for="red-pattern">{{ __('Keep (for a partial redaction)') }}</label> @include('description._ric', ['term' => null])
                <select id="red-pattern" name="pattern"><option value="">-</option>@foreach(\App\Models\Concept::choices(\App\Privacy\Redaction::PATTERNS)->get() as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select></p>
            <p><label for="red-reason">{{ __('Reason') }}</label> @include('description._ric', ['term' => null]) <input id="red-reason" name="reason" required size="60"></p>
            <p><label for="red-basis">{{ __('Legal basis reference') }}</label> @include('description._ric', ['term' => null]) <input id="red-basis" name="legal_basis" size="40" placeholder="POPIA s.26">
                <label><input type="hidden" name="is_sensitive" value="0"><input type="checkbox" name="is_sensitive" value="1"> {{ __('Special personal information (POPIA s.26)') }}</label> @include('description._ric', ['term' => null])</p>
            <button type="submit">{{ __('Redact') }}</button></form>
    </details>
</section>
