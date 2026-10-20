{{-- OpenRiC Workspace - the typed levels of the placement form, Building to Shelf. Needs $levels (notation => label) and $current (notation => name). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<fieldset aria-describedby="levels-help">
    <legend>Where it is kept @include('description._ric', ['term' => 'rico:hasOrHadLocation'])</legend>
    <small id="levels-help">Type the levels you know, from the top; leave the others empty. Each is found under the one above it, and made if it is not there yet (if you may make places). A bare number or code below building level is named with its level, so 12 under Room is Room 12. Emptying every field does not take the container out of storage: use Move for that.</small>
    <div style="display: flex; gap: .75rem; flex-wrap: wrap">
        @foreach($levels as $level => $label)
            <div><label for="level-{{ $level }}">{{ $label }}</label>
                <input id="level-{{ $level }}" name="levels[{{ $level }}]" maxlength="255" style="max-width: 11rem" value="{{ old('levels.'.$level, $current[$level] ?? '') }}"></div>
        @endforeach
    </div>
</fieldset>
