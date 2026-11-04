{{-- OpenRiC Workspace - the fields of a union catalogue member (Heratio's federation_member; roadmap phase 16b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-name">{{ __('Name') }}</label><input id="{{ $x }}-name" name="name" value="{{ $m?->name }}" required>
@unless($m?->is_self)<label for="{{ $x }}-base">{{ __('Its public catalogue\'s address') }}</label><input id="{{ $x }}-base" name="base_url" value="{{ $m?->base_url }}" placeholder="https://archives.example.org">@endunless
<label for="{{ $x }}-contact">{{ __('Contact') }}</label><input id="{{ $x }}-contact" name="contact" value="{{ $m?->contact }}">
<label for="{{ $x }}-scope">{{ __('What it shares, and on what terms') }}</label><textarea id="{{ $x }}-scope" name="share_scope" rows="2">{{ $m?->share_scope }}</textarea>
<label><input type="checkbox" name="is_enabled" value="1" @checked($m?->is_enabled)> {{ __('In the union') }}</label>
