{{-- OpenRiC Workspace - role checkboxes. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<fieldset>
    <legend>Roles</legend>
    @foreach($roles as $role)
        <label style="font-weight: normal">
            <input type="checkbox" name="roles[]" value="{{ $role->iri }}" style="width: auto" @checked(in_array($role->iri, $selected, true))>
            {{ $role->label(['en', 'und']) }}: {{ $role->labels->firstWhere('language', 'en')?->definition }}
        </label>
    @endforeach
</fieldset>
