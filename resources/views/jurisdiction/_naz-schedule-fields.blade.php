{{-- OpenRiC Workspace - the fields of a National Archives of Zimbabwe records schedule. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p><label for="{{ $x }}-agency">{{ __('Agency') }}</label> <input id="{{ $x }}-agency" name="agency_name" size="40" required value="{{ $s?->agency_name }}">
    <label for="{{ $x }}-code">{{ __('Agency code') }}</label> <input id="{{ $x }}-code" name="agency_code" size="10" value="{{ $s?->agency_code }}"></p>
<p><label for="{{ $x }}-series">{{ __('Series of records') }}</label> <input id="{{ $x }}-series" name="record_series" size="50" required value="{{ $s?->record_series }}"></p>
<p><label for="{{ $x }}-active">{{ __('Years active') }}</label> <input id="{{ $x }}-active" name="retention_active_years" type="number" min="0" value="{{ $s?->retention_active_years ?? 0 }}">
    <label for="{{ $x }}-semi">{{ __('Years semi-active') }}</label> <input id="{{ $x }}-semi" name="retention_semi_active_years" type="number" min="0" value="{{ $s?->retention_semi_active_years ?? 0 }}">
    <label for="{{ $x }}-action">{{ __('Then') }}</label> <select id="{{ $x }}-action" name="disposal_action">{!! $options($actions, $s?->disposalAction?->notation ?? 'review') !!}</select></p>
<p><label for="{{ $x }}-class">{{ __('Records class') }}</label> <select id="{{ $x }}-class" name="records_class"><option value="">-</option>{!! $options($recordsClasses, $s?->recordsClass?->notation) !!}</select>
    <label for="{{ $x }}-access">{{ __('Access') }}</label> <select id="{{ $x }}-access" name="access_restriction"><option value="">-</option>{!! $options($accessRestrictions, $s?->accessRestriction?->notation) !!}</select>
    <label for="{{ $x }}-dclass">{{ __('Disposal class') }}</label> <select id="{{ $x }}-dclass" name="disposal_class_id"><option value="">-</option>@foreach($classes as $c)<option value="{{ $c->id }}" @selected($s?->disposal_class_id === $c->id)>{{ $c->reference }} {{ $c->title }}</option>@endforeach</select></p>
<p><label for="{{ $x }}-legal">{{ __('Legal authority') }}</label> <input id="{{ $x }}-legal" name="legal_authority" size="40" value="{{ $s?->legal_authority }}">
    <label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">{!! $options($scheduleStatuses, $s?->status?->notation ?? 'draft') !!}</select></p>
<p><label for="{{ $x }}-by">{{ __('Approved by') }}</label> <input id="{{ $x }}-by" name="approved_by" size="30" value="{{ $s?->approved_by }}">
    <label for="{{ $x }}-on">{{ __('on') }}</label> <input id="{{ $x }}-on" name="approval_date" type="date" value="{{ $s?->approval_date?->format('Y-m-d') }}">
    <label for="{{ $x }}-eff">{{ __('Effective') }}</label> <input id="{{ $x }}-eff" name="effective_date" type="date" value="{{ $s?->effective_date?->format('Y-m-d') }}">
    <label for="{{ $x }}-rev">{{ __('Review') }}</label> <input id="{{ $x }}-rev" name="review_date" type="date" value="{{ $s?->review_date?->format('Y-m-d') }}"></p>
<p><label for="{{ $x }}-desc">{{ __('Description') }}</label><br><textarea id="{{ $x }}-desc" name="description" rows="2" cols="70">{{ $s?->description }}</textarea></p>
<p><label for="{{ $x }}-notes">{{ __('Notes') }}</label><br><textarea id="{{ $x }}-notes" name="notes" rows="2" cols="70">{{ $s?->notes }}</textarea></p>
