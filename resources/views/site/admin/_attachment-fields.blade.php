{{-- OpenRiC Workspace - an article attachment's kind, section, title, description and place (roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $f (an ArticleAttachment or null), $id and $kinds. --}}
@php($lang = \App\Support\Vocab::languages())
<label for="af-kind-{{ $id }}">{{ __('Kind') }}</label><select id="af-kind-{{ $id }}" name="kind">@foreach($kinds as $k)<option value="{{ $k->notation }}" @selected(($f?->kind?->notation ?? 'guide') === $k->notation)>{{ $k->label($lang) }}</option>@endforeach</select>
<label for="af-group-{{ $id }}">{{ __('Section') }}</label><input id="af-group-{{ $id }}" name="group_label" maxlength="150" value="{{ $f?->group_label }}">
<label for="af-title-{{ $id }}">{{ __('Title') }}</label><input id="af-title-{{ $id }}" name="title" maxlength="255" value="{{ $f?->title }}" placeholder="{{ __('The file name, when left empty') }}">
<label for="af-desc-{{ $id }}">{{ __('Description') }}</label><input id="af-desc-{{ $id }}" name="description" maxlength="500" value="{{ $f?->description }}">
<label for="af-order-{{ $id }}">{{ __('Place in the order') }}</label><input id="af-order-{{ $id }}" name="sort_order" type="number" value="{{ $f?->sort_order }}">
