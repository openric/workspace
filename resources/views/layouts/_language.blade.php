{{-- OpenRiC Workspace - the interface language switch (WS-005): posts to /language, which keeps the choice and returns to this page. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Each language is named in itself, as Heratio's culture switcher names them; works without JavaScript (the button), and submits on change with it. --}}
@if(count(config('workspace.interface_languages')) > 1)
    <form method="post" action="{{ route('language') }}" style="display: flex; gap: .25rem; align-items: center; margin: 0">
        @csrf
        <input type="hidden" name="redirect_to" value="{{ '/'.ltrim(request()->path(), '/').(request()->getQueryString() ? '?'.request()->getQueryString() : '') }}">
        <label for="interface-language" style="margin: 0; position: absolute; left: -999px">{{ __('Language') }}</label>
        <select id="interface-language" name="lang" onchange="this.form.submit()" style="margin: 0; width: auto">
            @foreach(config('workspace.interface_languages') as $tag => $name)
                <option value="{{ $tag }}" lang="{{ $tag }}" @selected(app()->getLocale() === $tag)>{{ $name }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" style="margin: 0">{{ __('Change') }}</button></noscript>
    </form>
@endif
