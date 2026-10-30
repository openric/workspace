{{-- OpenRiC Workspace - the fields of a work queue (Heratio's ahg_workflow_queue). Needs $q (or null), $p. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0 1rem">
    <div><label for="{{ $p }}-name">{{ __('Name') }}</label><input id="{{ $p }}-name" name="name" required maxlength="100" value="{{ $q?->name }}"></div>
    <div><label for="{{ $p }}-slug">{{ __('Short name') }}</label><input id="{{ $p }}-slug" name="slug" maxlength="100" value="{{ $q?->slug }}"></div>
    <div><label for="{{ $p }}-days">{{ __('Service level (days)') }}</label><input id="{{ $p }}-days" name="sla_days" inputmode="numeric" value="{{ $q?->sla_days }}"></div>
    <div><label for="{{ $p }}-color">{{ __('Colour') }}</label><input id="{{ $p }}-color" name="color" type="color" value="{{ $q?->color ?? '#6c757d' }}"></div>
</div>
<label for="{{ $p }}-description">{{ __('Description') }}</label><input id="{{ $p }}-description" name="description" maxlength="2000" value="{{ $q?->description }}">
<input type="hidden" name="is_active" value="0"><label style="font-weight: normal"><input type="checkbox" name="is_active" value="1" @checked($q?->is_active ?? true) style="width: auto"> {{ __('Active') }}</label>
