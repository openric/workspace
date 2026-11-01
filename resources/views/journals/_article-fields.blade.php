{{-- OpenRiC Workspace - an article's fields (Heratio's research_journal_article; roadmap phase 19g). Needs $a (or null), $journal, $articleStatuses, $targets. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($x = $a ? 'ae' : 'an')
<label for="{{ $x }}-title">{{ __('Title') }}</label> <input id="{{ $x }}-title" name="title" required size="50" value="{{ $a?->title }}"><br>
<label for="{{ $x }}-authors">{{ __('Authors') }}</label> <input id="{{ $x }}-authors" name="authors" size="50" value="{{ $a?->authors }}">
<label for="{{ $x }}-issue">{{ __('Issue') }}</label> <select id="{{ $x }}-issue" name="issue_id"><option value="">{{ __('Not yet') }}</option>@foreach($journal->issues as $i)<option value="{{ $i->id }}" @selected($a?->issue_id === $i->id)>{{ trim($i->volume.' '.$i->number.' '.$i->title) }}</option>@endforeach</select><br>
<label for="{{ $x }}-abstract">{{ __('Abstract') }}</label><br><textarea id="{{ $x }}-abstract" name="abstract" rows="3" cols="90">{{ $a?->abstract }}</textarea><br>
<label for="{{ $x }}-body">{{ __('Text (Markdown)') }}</label><br><textarea id="{{ $x }}-body" name="body_markdown" rows="12" cols="90">{{ $a?->body_markdown }}</textarea><br>
<label for="{{ $x }}-kw">{{ __('Keywords') }}</label> <input id="{{ $x }}-kw" name="keywords" size="30" value="{{ $a?->keywords }}">
<label for="{{ $x }}-style">{{ __('Reference style') }}</label> <input id="{{ $x }}-style" name="reference_style" size="12" value="{{ $a?->reference_style }}">
<label for="{{ $x }}-doi">DOI</label> <input id="{{ $x }}-doi" name="doi" size="20" value="{{ $a?->doi }}"><br>
<label for="{{ $x }}-target">{{ __('Aimed at') }}</label> <select id="{{ $x }}-target" name="target_journal_id"><option value="">{{ __('As the journal') }}</option>@foreach($targets as $t)<option value="{{ $t->id }}" @selected($a?->target_journal_id === $t->id)>{{ $t->title }}</option>@endforeach</select>
<label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">@foreach($articleStatuses as $s)<option value="{{ $s->notation }}" @selected(($a?->status->notation ?? 'draft') === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select><br>
