{{-- OpenRiC Workspace - one value as running text; a long one starts shortened to a few lines, with "more" to show all of it. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $value (a value row). Optional $tag (p by default, dd in a list), $style. The whole text is always in the page, so it is searched and read aloud in full; only its height is limited. --}}
@php
    $tag = $tag ?? 'p';
    $text = (string) $value->value;
    $long = mb_strlen($text) > 600 || substr_count($text, "\n") > 6;
@endphp
<{{ $tag }} lang="{{ $value->language }}" @isset($style) style="{{ $style }}" @endisset @if($long) class="long" @endif>@if($long)<span class="long-text">{!! nl2br(e($text)) !!}</span><details><summary><span class="long-more">{{ __('more') }}</span><span class="long-less">{{ __('less') }}</span></summary></details>@else{!! nl2br(e($text)) !!}@endif</{{ $tag }}>
