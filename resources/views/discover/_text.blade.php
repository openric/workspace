{{-- OpenRiC Workspace - plain text from Settings shown as paragraphs, with web addresses as links. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $text. Escaped first, then only http(s) addresses become links, so nothing typed can add markup. --}}
@foreach(preg_split('/\R\s*\R/', trim((string) $text)) as $paragraph)
    <p>{!! preg_replace('~https?://[^\s<]+[^\s<.,;:!?)]~', '<a href="$0">$0</a>', nl2br(e(trim($paragraph)))) !!}</p>
@endforeach
