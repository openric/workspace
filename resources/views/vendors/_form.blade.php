{{-- OpenRiC Workspace - a vendor profile's fields (Heratio's ahg-vendor _form). Needs $vendor (or null), $statuses, $types, $services, $currencies. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($v = fn ($f) => old($f, $vendor?->{$f} instanceof \DateTimeInterface ? $vendor->{$f}->format('Y-m-d') : $vendor?->{$f}))
<fieldset><legend>{{ __('Vendor') }}</legend>
    <label for="vendor_code">{{ __('Vendor code') }}</label><small id="code-help">{{ __('Left empty, the next code is given, VND and the year, as Heratio numbers them.') }}</small>
    <input id="vendor_code" name="vendor_code" value="{{ $v('vendor_code') }}" maxlength="50" aria-describedby="code-help" @if($vendor) readonly @endif>
    <label for="vendor_type">{{ __('Type') }}</label><select id="vendor_type" name="vendor_type">@foreach($types as $o)<option value="{{ $o->notation }}" @selected(old('vendor_type', $vendor?->vendorType->notation) === $o->notation)>{{ $o->label($lang) }}</option>@endforeach</select>
    <label for="status">{{ __('Status') }}</label><select id="status" name="status">@foreach($statuses as $o)<option value="{{ $o->notation }}" @selected(old('status', $vendor?->status->notation ?? 'active') === $o->notation)>{{ $o->label($lang) }}</option>@endforeach</select>
    <label for="registration_number">{{ __('Registration number') }}</label><input id="registration_number" name="registration_number" value="{{ $v('registration_number') }}" maxlength="100">
    <label for="vat_number">{{ __('VAT number') }}</label><input id="vat_number" name="vat_number" value="{{ $v('vat_number') }}" maxlength="50">
    <label><input type="checkbox" name="is_preferred" value="1" @checked(old('is_preferred', $vendor?->is_preferred))> {{ __('Preferred vendor') }}</label>
    <label><input type="checkbox" name="is_bbbee_compliant" value="1" @checked(old('is_bbbee_compliant', $vendor?->is_bbbee_compliant))> {{ __('B-BBEE compliant') }}</label>
</fieldset>
<fieldset><legend>{{ __('Bank details') }}</legend>
    <small>{{ __('Kept encrypted, as Heratio keeps them, and never in the audit log.') }}</small>
    @foreach(['bank_name' => __('Bank'), 'bank_branch' => __('Branch'), 'bank_account_number' => __('Account number'), 'bank_branch_code' => __('Branch code'), 'bank_account_type' => __('Account type')] as $f => $label)
        <label for="{{ $f }}">{{ $label }}</label><input id="{{ $f }}" name="{{ $f }}" value="{{ $v($f) }}" maxlength="100" autocomplete="off">
    @endforeach
</fieldset>
<fieldset><legend>{{ __('Insurance') }}</legend>
    <label><input type="checkbox" name="has_insurance" value="1" @checked(old('has_insurance', $vendor?->has_insurance))> {{ __('Insured') }}</label>
    <label for="insurance_provider">{{ __('Insurer') }}</label><input id="insurance_provider" name="insurance_provider" value="{{ $v('insurance_provider') }}" maxlength="255">
    <label for="insurance_policy_number">{{ __('Policy number') }}</label><input id="insurance_policy_number" name="insurance_policy_number" value="{{ $v('insurance_policy_number') }}" maxlength="100">
    <label for="insurance_expiry_date">{{ __('Cover ends on') }}</label><input type="date" id="insurance_expiry_date" name="insurance_expiry_date" value="{{ $v('insurance_expiry_date') }}">
    <label for="insurance_coverage_amount">{{ __('Cover') }}</label><input id="insurance_coverage_amount" name="insurance_coverage_amount" value="{{ $v('insurance_coverage_amount') }}" inputmode="decimal">
</fieldset>
<fieldset><legend>{{ __('Ratings') }}</legend>
    @foreach(['quality_rating' => __('Quality'), 'reliability_rating' => __('Reliability'), 'price_rating' => __('Price')] as $f => $label)
        <label for="{{ $f }}">{{ $label }}</label><select id="{{ $f }}" name="{{ $f }}"><option value="">{{ __('Not rated') }}</option>@for($i = 1; $i <= 5; $i++)<option value="{{ $i }}" @selected((string) $v($f) === (string) $i)>{{ trans_choice(':count star|:count stars', $i, ['count' => $i]) }}</option>@endfor</select>
    @endforeach
</fieldset>
<fieldset><legend>{{ __('Services offered') }}</legend>
    @foreach($services as $s)
        @php($own = $vendor?->services->firstWhere('service_type_id', $s->id))
        @php($n = $s->notation)
        <details @if($own) open @endif><summary><label style="display: inline"><input type="checkbox" name="services[{{ $n }}][offered]" value="1" @checked(old("services.$n.offered", (bool) $own))> {{ $s->label($lang) }}</label></summary>
            <label for="s-{{ $n }}-h">{{ __('Hourly rate') }}</label><input id="s-{{ $n }}-h" name="services[{{ $n }}][hourly_rate]" value="{{ old("services.$n.hourly_rate", $own?->hourly_rate) }}" inputmode="decimal">
            <label for="s-{{ $n }}-f">{{ __('Fixed rate') }}</label><input id="s-{{ $n }}-f" name="services[{{ $n }}][fixed_rate]" value="{{ old("services.$n.fixed_rate", $own?->fixed_rate) }}" inputmode="decimal">
            <label for="s-{{ $n }}-c">{{ __('Currency') }}</label><select id="s-{{ $n }}-c" name="services[{{ $n }}][currency]">@foreach($currencies as $c)<option value="{{ $c->notation }}" @selected(old("services.$n.currency", $own?->currency?->notation ?? 'ZAR') === $c->notation)>{{ $c->notation }}</option>@endforeach</select>
            <label><input type="checkbox" name="services[{{ $n }}][is_preferred]" value="1" @checked(old("services.$n.is_preferred", $own?->is_preferred))> {{ __('Preferred for this service') }}</label>
            <label for="s-{{ $n }}-n">{{ __('Notes') }}</label><input id="s-{{ $n }}-n" name="services[{{ $n }}][notes]" value="{{ old("services.$n.notes", $own?->notes) }}" maxlength="1000"></details>
    @endforeach
</fieldset>
<label for="notes">{{ __('Notes') }}</label><textarea id="notes" name="notes" rows="3">{{ $v('notes') }}</textarea>
