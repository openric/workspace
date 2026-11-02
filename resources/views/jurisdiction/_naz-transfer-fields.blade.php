{{-- OpenRiC Workspace - the fields of a transfer to the National Archives of Zimbabwe. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p><label for="{{ $x }}-agency">{{ __('Transferring agency') }}</label> <input id="{{ $x }}-agency" name="transferring_agency" size="40" required value="{{ $t?->transferring_agency }}">
    <label for="{{ $x }}-type">{{ __('Type') }}</label> <select id="{{ $x }}-type" name="transfer_type">{!! $options($transferTypes, $t?->transferType?->notation ?? 'scheduled') !!}</select>
    <label for="{{ $x }}-sched">{{ __('Schedule') }}</label> <select id="{{ $x }}-sched" name="schedule_id"><option value="">-</option>@foreach($schedules as $s)<option value="{{ $s->id }}" @selected($t?->schedule_id === $s->id)>{{ $s->schedule_number }} {{ $s->record_series }}</option>@endforeach</select></p>
<p><label for="{{ $x }}-contact">{{ __('Contact') }}</label> <input id="{{ $x }}-contact" name="agency_contact" size="24" value="{{ $t?->agency_contact }}">
    <label for="{{ $x }}-email">{{ __('Email') }}</label> <input id="{{ $x }}-email" name="agency_email" type="email" size="24" value="{{ $t?->agency_email }}">
    <label for="{{ $x }}-phone">{{ __('Telephone') }}</label> <input id="{{ $x }}-phone" name="agency_phone" size="14" value="{{ $t?->agency_phone }}"></p>
<p><label for="{{ $x }}-from">{{ __('Records dated from') }}</label> <input id="{{ $x }}-from" name="date_range_start" type="date" value="{{ $t?->date_range_start?->format('Y-m-d') }}">
    <label for="{{ $x }}-to">{{ __('to') }}</label> <input id="{{ $x }}-to" name="date_range_end" type="date" value="{{ $t?->date_range_end?->format('Y-m-d') }}">
    <label for="{{ $x }}-m">{{ __('Linear metres') }}</label> <input id="{{ $x }}-m" name="quantity_linear_metres" type="number" step="0.01" min="0" value="{{ $t?->quantity_linear_metres }}">
    <label for="{{ $x }}-boxes">{{ __('Boxes') }}</label> <input id="{{ $x }}-boxes" name="quantity_boxes" type="number" min="0" value="{{ $t?->quantity_boxes }}">
    <label for="{{ $x }}-items">{{ __('Items') }}</label> <input id="{{ $x }}-items" name="quantity_items" type="number" min="0" value="{{ $t?->quantity_items }}"></p>
<p><label><input type="checkbox" name="contains_restricted" value="1" @checked($t?->contains_restricted)> {{ __('contains restricted records') }}</label>
    <label for="{{ $x }}-rd">{{ __('Which, and why') }}</label> <input id="{{ $x }}-rd" name="restriction_details" size="40" value="{{ $t?->restriction_details }}"></p>
<p><label for="{{ $x }}-proposed">{{ __('Proposed') }}</label> <input id="{{ $x }}-proposed" name="proposed_date" type="date" value="{{ $t?->proposed_date?->format('Y-m-d') }}">
    <label for="{{ $x }}-actual">{{ __('Transferred') }}</label> <input id="{{ $x }}-actual" name="actual_date" type="date" value="{{ $t?->actual_date?->format('Y-m-d') }}">
    <label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">{!! $options($transferStatuses, $t?->status?->notation ?? 'proposed') !!}</select></p>
<p><label for="{{ $x }}-acc">{{ __('Accession number') }}</label> <input id="{{ $x }}-acc" name="accession_number" size="16" value="{{ $t?->accession_number }}">
    <label for="{{ $x }}-loc">{{ __('Location assigned') }}</label> <input id="{{ $x }}-loc" name="location_assigned" size="20" value="{{ $t?->location_assigned }}">
    <label for="{{ $x }}-rej">{{ __('Reason rejected') }}</label> <input id="{{ $x }}-rej" name="rejection_reason" size="24" value="{{ $t?->rejection_reason }}"></p>
<p><label for="{{ $x }}-desc">{{ __('Description') }}</label><br><textarea id="{{ $x }}-desc" name="description" rows="2" cols="70">{{ $t?->description }}</textarea></p>
<p><label for="{{ $x }}-notes">{{ __('Notes') }}</label><br><textarea id="{{ $x }}-notes" name="notes" rows="2" cols="70">{{ $t?->notes }}</textarea></p>
