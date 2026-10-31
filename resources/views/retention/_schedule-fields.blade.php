{{-- OpenRiC Workspace - the fields of a retention schedule (Heratio's rm_retention_schedule). Needs: $s. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p><label for="s-ref">{{ __('Reference') }}</label> <input id="s-ref" name="reference" required maxlength="100" value="{{ $s?->reference }}">
    <label for="s-title">{{ __('Title') }}</label> <input id="s-title" name="title" required maxlength="255" size="40" value="{{ $s?->title }}"></p>
<p><label for="s-auth">{{ __('Disposal authority (such as a NARSSA standing disposal authority)') }}</label><br><input id="s-auth" name="authority" size="70" value="{{ $s?->authority }}"></p>
<p><label for="s-jur">{{ __('Jurisdiction') }}</label> <input id="s-jur" name="jurisdiction" value="{{ $s?->jurisdiction }}">
    <label for="s-eff">{{ __('In effect from') }}</label> <input id="s-eff" type="date" name="effective_on" value="{{ $s?->effective_on?->format('Y-m-d') }}">
    <label for="s-rev">{{ __('Review on') }}</label> <input id="s-rev" type="date" name="review_on" value="{{ $s?->review_on?->format('Y-m-d') }}">
    <label for="s-exp">{{ __('Expires on') }}</label> <input id="s-exp" type="date" name="expires_on" value="{{ $s?->expires_on?->format('Y-m-d') }}"></p>
<p><label for="s-desc">{{ __('Description') }}</label><br><textarea id="s-desc" name="description" rows="2" cols="70">{{ $s?->description }}</textarea></p>
