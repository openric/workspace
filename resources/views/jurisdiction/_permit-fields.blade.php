{{-- OpenRiC Workspace - the fields of an export permit application. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p><label for="p-purpose">{{ __('Purpose') }}</label> <input id="p-purpose" name="purpose" size="60" required value="{{ old('purpose', $p?->purpose) }}"></p>
<p><label for="p-authority">{{ __('Issuing authority') }}</label> <input id="p-authority" name="issuing_authority" size="30" value="{{ old('issuing_authority', $p?->issuing_authority ?? 'SAHRA') }}"></p>
<p><label for="p-dest">{{ __('Destination institution') }}</label> <input id="p-dest" name="destination_institution" size="40" value="{{ old('destination_institution', $p?->destination_institution) }}">
    <label for="p-country">{{ __('Country (ISO code)') }}</label> <input id="p-country" name="destination_country" size="2" maxlength="2" value="{{ old('destination_country', $p?->destination_country) }}"></p>
<p><label for="p-start">{{ __('From') }}</label> <input id="p-start" name="start_date" type="date" value="{{ old('start_date', $p?->start_date?->format('Y-m-d')) }}">
    <label for="p-end">{{ __('to') }}</label> <input id="p-end" name="end_date" type="date" value="{{ old('end_date', $p?->end_date?->format('Y-m-d')) }}">
    <label for="p-fee">{{ __('Fee') }}</label> <input id="p-fee" name="fee_amount" type="number" step="0.01" min="0" value="{{ old('fee_amount', $p?->fee_amount) }}"></p>
<p><label for="p-iris">{{ __('Records, by persistent IRI, one a line') }}</label><br><textarea id="p-iris" name="record_iris" rows="4" cols="70" required>{{ old('record_iris', $p?->records->map(fn ($r) => $r->record->iri)->join("\n")) }}</textarea></p>
<p><label for="p-desc">{{ __('Description') }}</label><br><textarea id="p-desc" name="description" rows="3" cols="70">{{ old('description', $p?->description) }}</textarea></p>
