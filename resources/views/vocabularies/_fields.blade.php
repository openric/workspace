{{-- OpenRiC Workspace - the title and description of a vocabulary. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="title">{{ __('Title') }}</label>
<input id="title" name="title" required maxlength="1024" value="{{ old('title', $vocabulary?->title) }}">
<label for="description">{{ __('Description (optional)') }}</label>
<textarea id="description" name="description" rows="3" style="width: 100%; font: inherit; box-sizing: border-box">{{ old('description', $vocabulary?->description) }}</textarea>
<label for="language">{{ __('Language of the title and description (BCP 47)') }}</label>
<input id="language" name="language" required maxlength="35" value="{{ old('language', $vocabulary?->language ?? 'en') }}">
