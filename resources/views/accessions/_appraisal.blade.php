{{-- OpenRiC Workspace - an accession's appraisals with their scored criteria, and its valuations (PSIS's accessionAppraisal appraisal and valuation, Heratio's accession appraisal and valuation). Needs $accession, $care, $canUpdate. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($label = fn ($c) => $c?->label($lang))
@php($money = fn ($value, $currency) => $value === null ? null : $currency.' '.number_format((float) $value, 2))
@php($select = function (string $name, $choices, ?string $current, bool $optional, string $id) use ($lang) {
    $html = '<select id="'.e($id).'" name="'.e($name).'" style="font: inherit; padding: .5rem">'.($optional ? '<option value="">'.e(__('Not set')).'</option>' : '');
    foreach ($choices as $c) {
        $html .= '<option value="'.e($c->notation).'"'.($current === $c->notation ? ' selected' : '').'>'.e($c->label($lang)).'</option>';
    }

    return new \Illuminate\Support\HtmlString($html.'</select>');
})
@php($appraisalFields = function (?\App\Models\AccessionAppraisal $a, string $p) use ($care, $select) {
    return view('accessions._appraisal-fields', ['a' => $a, 'p' => $p, 'care' => $care, 'select' => $select]);
})
<section id="appraisal">
    <h2>{{ __('Appraisal') }}</h2>
    @forelse($accession->appraisals as $a)
        @php($score = $a->weightedScore())
        <h3 style="margin-bottom: .2rem">{{ $label($a->type) }}: {{ $label($a->recommendation) }}</h3>
        <p style="margin: .2rem 0">{{ collect([
            $a->significance ? __('Significance').': '.$label($a->significance) : null,
            $a->monetary_value !== null ? __('Value').': '.$money($a->monetary_value, $a->currency) : null,
            $a->appraiser ? __('Appraiser').': '.$a->appraiser->described('agent-authorised-name', $lang) : null,
            $a->appraised_on ? __('Appraised on').' '.$a->appraised_on->format('Y-m-d') : null,
            $score !== null ? __('Weighted score').': '.number_format($score, 2).' / 5' : null,
            $a->recordedBy ? __('recorded by :name', ['name' => $a->recordedBy->name]) : null,
        ])->filter()->join('; ') }}</p>
        @if($a->summary)<p style="margin: .2rem 0; white-space: pre-line"><em>{{ __('Summary') }}:</em> {{ $a->summary }}</p>@endif
        @if($a->detailed_notes)<p style="margin: .2rem 0; white-space: pre-line"><em>{{ __('Notes') }}:</em> {{ $a->detailed_notes }}</p>@endif
        @if($a->criteria->isNotEmpty())
            <table style="border-collapse: collapse; margin: .4rem 0">
                <thead><tr><th scope="col" style="text-align: left; padding-right: 1rem">{{ __('Criterion') }}</th><th scope="col" style="padding-right: 1rem">{{ __('Weight') }}</th><th scope="col" style="padding-right: 1rem">{{ __('Score (1 to 5)') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th></tr></thead>
                <tbody>
                @foreach($a->criteria as $c)
                    <tr>
                        <td style="padding-right: 1rem">{{ $c->name }}@if($c->notes)<br><small>{{ $c->notes }}</small>@endif</td>
                        <td style="padding-right: 1rem; text-align: center">{{ rtrim(rtrim((string) $c->weight, '0'), '.') }}</td>
                        <td style="padding-right: 1rem; text-align: center">
                            @if($canUpdate)
                                <form method="post" action="{{ route('accessions.appraisals.criteria', [$accession, $a->id]) }}" style="display: inline-flex; gap: .3rem">
                                    @csrf<input type="hidden" name="action" value="score"><input type="hidden" name="criterion" value="{{ $c->id }}">
                                    <select name="score" aria-label="{{ __('Score for :name', ['name' => $c->name]) }}" style="font: inherit; padding: .2rem"><option value="">-</option>@foreach(range(1, 5) as $n)<option value="{{ $n }}" @selected($c->score === $n)>{{ $n }}</option>@endforeach</select>
                                    <button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Save') }}</button>
                                </form>
                            @else{{ $c->score ?? '-' }}@endif
                        </td>
                        <td>@if($canUpdate)<form method="post" action="{{ route('accessions.appraisals.criteria', [$accession, $a->id]) }}" style="display: inline">@csrf<input type="hidden" name="action" value="remove"><input type="hidden" name="criterion" value="{{ $c->id }}"><button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        @if($canUpdate)
            <details>
                <summary>{{ __('Edit the appraisal or its criteria') }}</summary>
                <form method="post" action="{{ route('accessions.appraisals.save', [$accession, $a->id]) }}">
                    @csrf
                    {{ $appraisalFields($a, 'ap'.$loop->index) }}
                    <button type="submit">{{ __('Save') }}</button>
                </form>
                <form method="post" action="{{ route('accessions.appraisals.criteria', [$accession, $a->id]) }}" style="display: flex; gap: .4rem; flex-wrap: wrap; align-items: end">
                    @csrf<input type="hidden" name="action" value="add">
                    <div><label for="cr{{ $loop->index }}-name" style="margin-top: 0">{{ __('Add a criterion') }}</label><input id="cr{{ $loop->index }}-name" name="name" required maxlength="500"></div>
                    <div><label for="cr{{ $loop->index }}-weight" style="margin-top: 0">{{ __('Weight') }}</label><input id="cr{{ $loop->index }}-weight" name="weight" inputmode="decimal" value="1" style="max-width: 5rem"></div>
                    <button type="submit" style="margin-top: 0">{{ __('Add') }}</button>
                </form>
                @if($care['templates']->isNotEmpty())
                    <form method="post" action="{{ route('accessions.appraisals.criteria', [$accession, $a->id]) }}" style="display: flex; gap: .4rem; align-items: end">
                        @csrf<input type="hidden" name="action" value="template">
                        <div><label for="ct{{ $loop->index }}" style="margin-top: 0">{{ __('Replace the criteria with a template') }}</label>
                            <select id="ct{{ $loop->index }}" name="template" style="font: inherit; padding: .5rem">@foreach($care['templates'] as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
                        <button type="submit" style="margin-top: 0">{{ __('Apply') }}</button>
                    </form>
                @endif
                <form method="post" action="{{ route('accessions.appraisals.destroy', [$accession, $a->id]) }}">@csrf<button type="submit">{{ __('Delete the appraisal') }}</button></form>
            </details>
        @endif
    @empty
        <p>{{ __('Not appraised yet.') }}</p>
    @endforelse
    @if($canUpdate)
        <details>
            <summary>{{ __('Add an appraisal') }}</summary>
            <form method="post" action="{{ route('accessions.appraisals.save', $accession) }}">
                @csrf
                {{ $appraisalFields(null, 'ap-new') }}
                <label for="ap-new-template">{{ __('Criteria from the template') }}</label>
                <select id="ap-new-template" name="template" style="font: inherit; padding: .5rem"><option value="">{{ __('None') }}</option>@foreach($care['templates'] as $t)<option value="{{ $t->id }}" @selected($t->is_default)>{{ $t->name }}</option>@endforeach</select>
                <button type="submit">{{ __('Add') }}</button>
            </form>
        </details>
    @endif
</section>

<section id="valuation">
    <h2>{{ __('Valuation') }}</h2>
    <p class="meta">{{ __('Its value over time, for GRAP 103 and IPSAS 45: the latest is its current value.') }} <a href="{{ route('accessions.valuation-report') }}">{{ __('Valuation report') }}</a></p>
    @if($current = $accession->valuations->first())<p><strong>{{ __('Current value') }}:</strong> {{ $money($current->monetary_value, $current->currency) }} ({{ $current->valued_on->format('Y-m-d') }})</p>@endif
    @forelse($accession->valuations as $v)
        <p style="margin: .2rem 0">{{ $v->valued_on->format('Y-m-d') }}: <strong>{{ $money($v->monetary_value, $v->currency) }}</strong>, {{ collect([$label($v->type), $label($v->method), $v->valuer, $v->reference_document])->filter()->join(', ') }}
            @if($canUpdate)<form method="post" action="{{ route('accessions.valuations.destroy', [$accession, $v->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
        @if($v->notes)<p style="margin: 0 0 .4rem; white-space: pre-line">{{ $v->notes }}</p>@endif
    @empty
        <p>{{ __('Not valued yet.') }}</p>
    @endforelse
    @if($canUpdate)
        <details>
            <summary>{{ __('Record a valuation') }}</summary>
            <form method="post" action="{{ route('accessions.valuations.store', $accession) }}">
                @csrf
                <label for="va-type">{{ __('Type') }}</label>{{ $select('type', $care['valuationTypes'], 'initial', false, 'va-type') }}
                <div style="display: flex; gap: .75rem; flex-wrap: wrap">
                    <div><label for="va-amount">{{ __('Amount') }}</label><input id="va-amount" name="monetary_value" required inputmode="decimal" style="max-width: 10rem"></div>
                    <div><label for="va-currency">{{ __('Currency') }}</label><input id="va-currency" name="currency" maxlength="3" value="ZAR" style="max-width: 5rem"></div>
                    <div><label for="va-date">{{ __('Date') }}</label><input id="va-date" name="valued_on" type="date" value="{{ now()->toDateString() }}"></div>
                </div>
                <label for="va-method">{{ __('Method') }}</label>{{ $select('method', $care['methods'], null, true, 'va-method') }}
                <label for="va-valuer">{{ __('Valuator') }}</label><input id="va-valuer" name="valuer" maxlength="255">
                <label for="va-reference">{{ __('Reference document') }}</label><input id="va-reference" name="reference_document" maxlength="255">
                <label for="va-notes">{{ __('Notes') }}</label><textarea id="va-notes" name="notes" rows="2" style="width: 100%; font: inherit"></textarea>
                <button type="submit">{{ __('Record') }}</button>
            </form>
        </details>
    @endif
</section>
