{{-- OpenRiC Workspace - the fields of a provenance step (Heratio's provenance entry form). Needs $step (or null), $p (id prefix), $choices, $agents. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($select = function (string $name, ?string $current, bool $optional = true) use ($choices, $lang, $p) {
    $html = '<select id="'.e($p.'-'.$name).'" name="'.e($name).'" style="font: inherit; padding: .5rem">'.($optional ? '<option value="">'.e(__('Not set')).'</option>' : '');
    foreach ($choices[$name] as $c) {
        $html .= '<option value="'.e($c->notation).'"'.($current === $c->notation ? ' selected' : '').'>'.e($c->label($lang)).'</option>';
    }

    return new \Illuminate\Support\HtmlString($html.'</select>');
})
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 0 1rem">
    <div><label for="{{ $p }}-owner">{{ __('Authority record') }} @include('description._ric', ['term' => 'rico:hasOrHadParticipant'])</label>
        <select id="{{ $p }}-owner" name="owner" style="font: inherit; padding: .5rem; width: 100%"><option value="">{{ __('None: the name only') }}</option>
            @foreach($agents as $a)<option value="{{ $a->id }}" @selected($step?->owner_id === $a->id)>{{ $a->described('agent-authorised-name', $lang) }}</option>@endforeach</select></div>
    <div><label for="{{ $p }}-owner_name">{{ __('Owner Name') }}</label><input id="{{ $p }}-owner_name" name="owner_name" maxlength="500" value="{{ $step?->owner_name }}" placeholder="{{ __('The authority record\'s name when empty') }}"></div>
    <div><label for="{{ $p }}-owner_type">{{ __('Owner Type') }}</label>{{ $select('owner_type', $step?->ownerType?->notation) }}</div>
    <div><label for="{{ $p }}-owner_location">{{ __('Location') }}</label><input id="{{ $p }}-owner_location" name="owner_location" maxlength="255" value="{{ $step?->owner_location }}"></div>
    <div><label for="{{ $p }}-owner_location_tgn">{{ __('Getty TGN identifier') }}</label><input id="{{ $p }}-owner_location_tgn" name="owner_location_tgn" maxlength="100" value="{{ $step?->owner_location_tgn }}"></div>
    <div><label for="{{ $p }}-transfer_type">{{ __('Transfer Method') }} @include('description._ric', ['term' => 'rico:hasActivityType'])</label>{{ $select('transfer_type', $step?->transferType->notation ?? 'unknown', false) }}</div>
    <div><label for="{{ $p }}-start_date">{{ __('Start Date') }} (EDTF) @include('description._ric', ['term' => 'rico:hasBeginningDate'])</label>
        <div style="display: flex; gap: .3rem">{{ $select('start_qualifier', $step?->startQualifier?->notation) }}<input id="{{ $p }}-start_date" name="start_date" maxlength="64" value="{{ $step?->start_date }}" placeholder="1920-05" style="flex: 1"></div></div>
    <div><label for="{{ $p }}-end_date">{{ __('End Date') }} (EDTF) @include('description._ric', ['term' => 'rico:hasEndDate'])</label>
        <div style="display: flex; gap: .3rem">{{ $select('end_qualifier', $step?->endQualifier?->notation) }}<input id="{{ $p }}-end_date" name="end_date" maxlength="64" value="{{ $step?->end_date }}" placeholder="1945" style="flex: 1"></div></div>
    <div><label for="{{ $p }}-certainty">{{ __('Certainty') }}</label>{{ $select('certainty', $step?->certainty?->notation) }}</div>
    <div><label for="{{ $p }}-sale_price">{{ __('Sale Price') }}</label><input id="{{ $p }}-sale_price" name="sale_price" inputmode="decimal" value="{{ $step?->sale_price }}"></div>
    <div><label for="{{ $p }}-sale_currency">{{ __('Currency') }}</label><input id="{{ $p }}-sale_currency" name="sale_currency" maxlength="3" value="{{ $step?->sale_currency }}" placeholder="ZAR"></div>
    <div><label for="{{ $p }}-auction_house">{{ __('Auction House') }}</label><input id="{{ $p }}-auction_house" name="auction_house" maxlength="255" value="{{ $step?->auction_house }}"></div>
    <div><label for="{{ $p }}-auction_lot">{{ __('Lot #') }}</label><input id="{{ $p }}-auction_lot" name="auction_lot" maxlength="50" value="{{ $step?->auction_lot }}"></div>
    <div><label for="{{ $p }}-evidence_type">{{ __('Evidence type') }}</label>{{ $select('evidence_type', $step?->evidenceType?->notation) }}</div>
</div>
@foreach(['transfer_details' => 'Transfer details', 'sources' => 'Sources/Documentation', 'evidence_description' => 'Evidence', 'notes' => 'Notes'] as $field => $text)
    <label for="{{ $p }}-{{ $field }}">{{ __($text) }}</label><textarea id="{{ $p }}-{{ $field }}" name="{{ $field }}" rows="2" style="width: 100%; font: inherit">{{ $step?->{$field} }}</textarea>
@endforeach
<input type="hidden" name="is_gap" value="0">
<label style="font-weight: normal"><input type="checkbox" name="is_gap" value="1" @checked($step?->is_gap) style="width: auto"> {{ __('Mark as provenance gap') }}</label>
<label for="{{ $p }}-gap_explanation">{{ __('Gap explanation') }}</label><textarea id="{{ $p }}-gap_explanation" name="gap_explanation" rows="2" style="width: 100%; font: inherit">{{ $step?->gap_explanation }}</textarea>
