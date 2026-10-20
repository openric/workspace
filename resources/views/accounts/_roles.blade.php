{{-- OpenRiC Workspace - role checkboxes. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<fieldset>
    <legend>Roles</legend>
    @foreach($roles as $role)
        <label style="font-weight: normal">
            <input type="checkbox" name="roles[]" value="{{ $role->id }}" style="width: auto" @checked(in_array($role->id, $selected, true))>
            {{ $role->name() }}@if($description = $role->labels->firstWhere('language', 'en')?->description): {{ $description }}@endif
        </label>
    @endforeach
    <p><small>Every account also has the Signed in and Public roles.</small></p>
</fieldset>
