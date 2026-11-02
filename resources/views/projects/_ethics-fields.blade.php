{{-- OpenRiC Workspace - an ethics approval's fields (Heratio's research_ethics; roadmap phase 19h). Needs $a (or null), $x, $types, $statuses, $bases, $sensitivities, $plans. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-title">{{ __('Title') }}</label> <input id="{{ $x }}-title" name="title" required size="40" value="{{ $a?->title }}">
<label for="{{ $x }}-type">{{ __('Covers') }}</label> @include('projects._select', ['id' => $x.'-type', 'name' => 'approval_type', 'choices' => $types, 'current' => $a?->approvalType->notation, 'default' => 'human-subjects'])
<label for="{{ $x }}-status">{{ __('Status') }}</label> @include('projects._select', ['id' => $x.'-status', 'name' => 'status', 'choices' => $statuses, 'current' => $a?->status->notation, 'default' => 'pending'])<br>
<label for="{{ $x }}-committee">{{ __('Committee') }}</label> <input id="{{ $x }}-committee" name="committee_name" size="30" value="{{ $a?->committee_name }}">
<label for="{{ $x }}-ref">{{ __('Reference number') }}</label> <input id="{{ $x }}-ref" name="reference_number" size="20" value="{{ $a?->reference_number }}"><br>
<label for="{{ $x }}-decided">{{ __('Decided on') }}</label> <input id="{{ $x }}-decided" name="decision_date" type="date" value="{{ $a?->decision_date?->toDateString() }}">
<label for="{{ $x }}-expiry">{{ __('Expires on') }}</label> <input id="{{ $x }}-expiry" name="expiry_date" type="date" value="{{ $a?->expiry_date?->toDateString() }}"><br>
<label for="{{ $x }}-basis">{{ __('Consent basis') }}</label> @include('projects._select', ['id' => $x.'-basis', 'name' => 'consent_basis', 'choices' => $bases, 'current' => $a?->consentBasis->notation, 'default' => 'informed-consent'])
<label for="{{ $x }}-sens">{{ __('Data sensitivity') }}</label> @include('projects._select', ['id' => $x.'-sens', 'name' => 'data_sensitivity', 'choices' => $sensitivities, 'current' => $a?->dataSensitivity->notation, 'default' => 'none'])
<label for="{{ $x }}-dmp">{{ __('Data management plan') }}</label> <select id="{{ $x }}-dmp" name="dmp_id"><option value="">{{ __('None') }}</option>@foreach($plans as $p)<option value="{{ $p->id }}" @selected($a?->dmp_id === $p->id)>{{ $p->title }}</option>@endforeach</select><br>
<label for="{{ $x }}-notes">{{ __('Notes and conditions') }}</label><br><textarea id="{{ $x }}-notes" name="notes" rows="2" cols="70">{{ $a?->notes }}</textarea><br>
