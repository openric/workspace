{{-- OpenRiC Workspace - a risk of an AI system, added or changed (roadmap phase 17f, Heratio's ai_risk_register). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<form method="post" action="{{ $action }}">@csrf
    <label for="{{ $prefix }}-desc">{{ __('The risk') }}</label><input id="{{ $prefix }}-desc" name="description" required maxlength="512" value="{{ $risk?->description }}">
    <label for="{{ $prefix }}-sev">{{ __('Severity') }}</label><select id="{{ $prefix }}-sev" name="severity">{!! $pick('severity', $levels, $risk?->severity?->notation ?? 'medium') !!}</select>
    <label for="{{ $prefix }}-like">{{ __('Likelihood') }}</label><select id="{{ $prefix }}-like" name="likelihood">{!! $pick('likelihood', $levels, $risk?->likelihood?->notation ?? 'medium') !!}</select>
    <label for="{{ $prefix }}-use">{{ __('Arises from') }}</label><select id="{{ $prefix }}-use" name="use">{!! $pick('use', $uses, $risk?->use?->notation ?? 'intended') !!}</select>
    <label for="{{ $prefix }}-group">{{ __('Who is affected') }}</label><input id="{{ $prefix }}-group" name="affected_group" maxlength="255" value="{{ $risk?->affected_group }}">
    <label for="{{ $prefix }}-mit">{{ __('Mitigation') }}</label><textarea id="{{ $prefix }}-mit" name="mitigation" rows="2">{{ $risk?->mitigation }}</textarea>
    <label for="{{ $prefix }}-res">{{ __('Residual risk') }}</label><select id="{{ $prefix }}-res" name="residual">{!! $pick('residual', $levels, $risk?->residual?->notation ?? 'low') !!}</select>
    <label for="{{ $prefix }}-st">{{ __('Status') }}</label><select id="{{ $prefix }}-st" name="status">{!! $pick('status', $statuses, $risk?->status?->notation ?? 'active') !!}</select>
    <button type="submit">{{ __('Save') }}</button>
</form>
