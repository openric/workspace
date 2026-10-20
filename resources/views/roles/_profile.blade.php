{{-- OpenRiC Workspace - role profile fields. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $parents, $role (or null), $language. --}}
@php($label = $role?->labels->firstWhere('language', $language))
<label for="language">Language of the name (BCP 47)</label>
<input id="language" name="language" required value="{{ old('language', $language) }}">
<label for="name">Name</label>
<input id="name" name="name" required value="{{ old('name', $label?->name) }}">
<label for="description">Description</label>
<textarea id="description" name="description" rows="2" style="width: 100%; font: inherit; box-sizing: border-box">{{ old('description', $label?->description) }}</textarea>
<label for="parent">Inherits from</label>
<select id="parent" name="parent" style="font: inherit; padding: .5rem" @disabled($role?->is_system)>
    <option value="">Nothing</option>
    @foreach($parents as $parent)
        <option value="{{ $parent->id }}" @selected(old('parent', $role?->parent_id ?? $parents->first(fn ($p) => $p->isTemplate('signed-in'))?->id) === $parent->id)>{{ $parent->name() }}</option>
    @endforeach
</select>
@if($role?->is_system)<p><small>A system role's place in the hierarchy is fixed.</small></p>@endif
