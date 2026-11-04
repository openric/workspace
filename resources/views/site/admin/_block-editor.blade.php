{{-- OpenRiC Workspace - one block of a page being built: its settings, how it is shown, and its place; a row block's columns with theirs (roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $block, $page, $pages, $types, $repositories, $ids and $i. A setting that is a list is written as JSON. --}}
@php($lang = \App\Support\Vocab::languages())
@php($type = $block->type->notation)
@php($c = $pages->config($block))
<fieldset id="block-{{ $block->id }}" style="margin: 1rem 0; @if(! $block->is_visible) opacity: .6; @endif">
    <legend><strong>{{ $block->type->label($lang) }}</strong>@if($block->title): {{ $block->title }}@endif @unless($block->is_visible) ({{ __('hidden') }})@endunless</legend>
    @include('site.admin._move', ['ids' => $ids, 'i' => $i, 'action' => route('landing.blocks.order', $page)])
    <form method="post" action="{{ route('landing.blocks.toggle', $block) }}" style="display: inline">@csrf<button type="submit" style="margin: 0; padding: .1rem .5rem">{{ $block->is_visible ? __('Hide') : __('Show') }}</button></form>
    <form method="post" action="{{ route('landing.blocks.duplicate', $block) }}" style="display: inline">@csrf<button type="submit" style="margin: 0; padding: .1rem .5rem">{{ __('Copy') }}</button></form>
    <form method="post" action="{{ route('landing.blocks.destroy', $block) }}" style="display: inline" onsubmit="return confirm(@json(__('Remove this block?')))">@csrf @method('DELETE')<button type="submit" style="margin: 0; padding: .1rem .5rem">{{ __('Remove') }}</button></form>
    <details><summary>{{ __('Settings') }}</summary>
        <form method="post" action="{{ route('landing.blocks.update', $block) }}">@csrf @method('PUT')
            <input type="hidden" name="is_visible" value="{{ $block->is_visible ? 1 : 0 }}">
            <label for="bt-{{ $block->id }}">{{ __('Name in the editor') }}</label><input id="bt-{{ $block->id }}" name="title" maxlength="255" value="{{ $block->title }}">
            @foreach(\App\Site\LandingPages::DEFAULTS[$type] as $key => $default)
                @php($fid = 'bc-'.$block->id.'-'.$key)
                @php($label = __(\App\Site\LandingPages::settingLabel($key)))
                @if(is_bool($default))
                    <input type="hidden" name="config[{{ $key }}]" value="0"><label><input type="checkbox" name="config[{{ $key }}]" value="1" @checked($c[$key])> {{ $label }}</label>
                @elseif($key === 'repository_id')
                    <label for="{{ $fid }}">{{ __('Repository') }}</label><select id="{{ $fid }}" name="config[{{ $key }}]"><option value="">{{ __('Any') }}</option>@foreach($repositories as $r)<option value="{{ $r->id }}" @selected($c[$key] === $r->id)>{{ \App\Relations\EntityKinds::label($r) }}</option>@endforeach</select>
                @elseif(is_array($default))
                    <label for="{{ $fid }}">{{ $label }} (JSON)</label><textarea id="{{ $fid }}" name="config[{{ $key }}]" rows="4" spellcheck="false">{{ json_encode($c[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</textarea>
                @elseif($key === 'content')
                    <label for="{{ $fid }}">{{ __('Text') }} ({{ __('Markdown') }})</label><textarea id="{{ $fid }}" name="config[{{ $key }}]" rows="8">{{ $c[$key] }}</textarea>
                @elseif(is_int($default) || is_float($default))
                    <label for="{{ $fid }}">{{ $label }}</label><input id="{{ $fid }}" name="config[{{ $key }}]" type="number" step="{{ is_float($default) ? '0.1' : '1' }}" value="{{ $c[$key] }}">
                @else
                    <label for="{{ $fid }}">{{ $label }}</label><input id="{{ $fid }}" name="config[{{ $key }}]" value="{{ $c[$key] }}">
                @endif
            @endforeach
            @if($type === 'image-carousel')<p class="meta">{{ __('Source: collections (the catalogue\'s collections with pictures) or images (the list of images, each {"url", "caption", "link"}).') }}</p>@endif
            @if($type === 'featured-items')<p class="meta">{{ __('Items: the identifiers of the archival descriptions to show, as a JSON list.') }}</p>@endif
            @if(in_array($type, ['statistics', 'browse-panels'], true))<p class="meta">{{ __('Kinds: records, agents, repositories, places, activities, rules.') }}</p>@endif
            <details><summary>{{ __('How it is shown') }}</summary>
                <label><input type="checkbox" name="full_width" value="1" @checked($block->full_width)> {{ __('The full width of the window') }}</label>
                <label for="bb-{{ $block->id }}">{{ __('Background colour') }}</label><input id="bb-{{ $block->id }}" name="background_color" value="{{ $block->background_color }}" pattern="#[0-9A-Fa-f]{6}" placeholder="#ffffff">
                <label for="bx-{{ $block->id }}">{{ __('Text colour') }}</label><input id="bx-{{ $block->id }}" name="text_color" value="{{ $block->text_color }}" pattern="#[0-9A-Fa-f]{6}" placeholder="#262626">
                <label for="bp-{{ $block->id }}">{{ __('Space above (rem)') }}</label><input id="bp-{{ $block->id }}" name="padding_top" type="number" min="0" max="6" step="0.5" value="{{ $block->padding_top }}">
                <label for="bq-{{ $block->id }}">{{ __('Space below (rem)') }}</label><input id="bq-{{ $block->id }}" name="padding_bottom" type="number" min="0" max="6" step="0.5" value="{{ $block->padding_bottom }}">
                <label for="bk-{{ $block->id }}">{{ __('Style classes') }}</label><input id="bk-{{ $block->id }}" name="css_classes" maxlength="255" value="{{ $block->css_classes }}">
            </details>
            <button type="submit">{{ __('Save') }}</button>
        </form>
    </details>
    @if(isset(\App\Site\LandingPages::ROWS[$type]))
        @for($col = 1; $col <= \App\Site\LandingPages::ROWS[$type]; $col++)
            @php($kids = $block->children->where('column_slot', $col)->values())
            <div style="margin-left: 1.5rem; border-left: 3px solid var(--line); padding-left: .8rem">
                <p class="meta">{{ __('Column :n', ['n' => $col]) }}</p>
                @php($kidIds = $kids->pluck('id')->all())
                @foreach($kids as $k => $child)@include('site.admin._block-editor', ['block' => $child, 'ids' => $kidIds, 'i' => $k])@endforeach
                <form method="post" action="{{ route('landing.blocks.store', $page) }}">@csrf<input type="hidden" name="parent" value="{{ $block->id }}"><input type="hidden" name="column" value="{{ $col }}">
                    <label for="ba-{{ $block->id }}-{{ $col }}" class="meta">{{ __('Add to this column') }}</label>
                    <select id="ba-{{ $block->id }}-{{ $col }}" name="type">@foreach($types->reject(fn ($t) => isset(\App\Site\LandingPages::ROWS[$t->notation])) as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Add') }}</button></form>
            </div>
        @endfor
    @endif
</fieldset>
