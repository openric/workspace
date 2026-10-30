{{-- OpenRiC Workspace - the fields of a workflow step (Heratio's step form, PSIS's edit step). Needs $s (or null), $p (id prefix), $stepTypes, $actions, $roles, $users. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 0 1rem">
    <div><label for="{{ $p }}-name">{{ __('Name') }}</label><input id="{{ $p }}-name" name="name" required maxlength="255" value="{{ $s?->name }}"></div>
    <div><label for="{{ $p }}-type">{{ __('Step type') }}</label><select id="{{ $p }}-type" name="step_type" style="font: inherit; padding: .5rem">@foreach($stepTypes as $c)<option value="{{ $c->notation }}" @selected(($s?->stepType->notation ?? 'review') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select></div>
    <div><label for="{{ $p }}-action">{{ __('Action required') }}</label><select id="{{ $p }}-action" name="action" style="font: inherit; padding: .5rem">@foreach($actions as $c)<option value="{{ $c->notation }}" @selected(($s?->action->notation ?? 'approve-reject') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select></div>
    <div><label for="{{ $p }}-role">{{ __('Required role') }}</label><select id="{{ $p }}-role" name="required_role" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>@foreach($roles as $r)<option value="{{ $r->id }}" @selected($s?->required_role_id === $r->id)>{{ $r->name($lang) }}</option>@endforeach</select></div>
    <div><label for="{{ $p }}-auto">{{ __('Assign automatically to') }}</label><select id="{{ $p }}-auto" name="auto_assign_user" style="font: inherit; padding: .5rem"><option value="">{{ __('Nobody') }}</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected($s?->auto_assign_user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div><label for="{{ $p }}-days">{{ __('Due after (days)') }}</label><input id="{{ $p }}-days" name="escalation_days" inputmode="numeric" value="{{ $s?->escalation_days }}"></div>
    <div><label for="{{ $p }}-esc">{{ __('Escalate to') }}</label><select id="{{ $p }}-esc" name="escalation_user" style="font: inherit; padding: .5rem"><option value="">{{ __('Nobody') }}</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected($s?->escalation_user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div><label for="{{ $p }}-clearance">{{ __('Required clearance level (phase 18)') }}</label><input id="{{ $p }}-clearance" name="required_clearance_level" inputmode="numeric" maxlength="1" value="{{ $s?->required_clearance_level }}"></div>
    <div><label for="{{ $p }}-template">{{ __('Notification template') }}</label><input id="{{ $p }}-template" name="notification_template" maxlength="100" value="{{ $s?->notification_template }}" placeholder="default"></div>
</div>
<label for="{{ $p }}-roles">{{ __('Roles that may take it') }}</label>
<select id="{{ $p }}-roles" name="allowed_roles[]" multiple size="4" style="width: 100%">@foreach($roles as $r)<option value="{{ $r->id }}" @selected(in_array($r->id, $s?->allowed_role_ids ?? [], true))>{{ $r->name($lang) }}</option>@endforeach</select>
<label for="{{ $p }}-users">{{ __('Accounts that may take it') }}</label>
<select id="{{ $p }}-users" name="allowed_users[]" multiple size="4" style="width: 100%">@foreach($users as $u)<option value="{{ $u->id }}" @selected(in_array($u->id, $s?->allowed_user_ids ?? [], true))>{{ $u->name }}</option>@endforeach</select>
<label for="{{ $p }}-description">{{ __('Description') }}</label><textarea id="{{ $p }}-description" name="description" rows="2" style="width: 100%; font: inherit">{{ $s?->description }}</textarea>
<label for="{{ $p }}-instructions">{{ __('Instructions') }}</label><textarea id="{{ $p }}-instructions" name="instructions" rows="3" style="width: 100%; font: inherit">{{ $s?->instructions }}</textarea>
<label for="{{ $p }}-checklist">{{ __('Checklist, one item per line') }}</label><textarea id="{{ $p }}-checklist" name="checklist" rows="3" style="width: 100%; font: inherit">{{ implode("\n", $s?->checklist ?? []) }}</textarea>
@foreach(['pool_enabled' => ['Others may claim it from the pool', true], 'is_optional' => ['Optional', false], 'is_active' => ['Active', true]] as $flag => [$text, $default])
    <input type="hidden" name="{{ $flag }}" value="0">
    <label style="font-weight: normal"><input type="checkbox" name="{{ $flag }}" value="1" @checked($s ? $s->{$flag} : $default) style="width: auto"> {{ __($text) }}</label>
@endforeach
