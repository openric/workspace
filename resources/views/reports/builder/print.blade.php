{{-- OpenRiC Workspace - a built report laid out for PDF (roadmap phase 8; PSIS's PdfExporter). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #262626; }
        h1 { font-size: 16pt; margin: 0 0 4pt; }
        h2 { font-size: 12pt; margin: 14pt 0 4pt; }
        .meta { color: #555; font-size: 8.5pt; }
        table { border-collapse: collapse; width: 100%; margin: 4pt 0; }
        th, td { border: 0.5pt solid #999; padding: 3pt 4pt; text-align: left; vertical-align: top; }
        thead th { background: #10373E; color: #fff; font-weight: bold; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>{{ $document['title'] }}</h1>
    @if($document['description'] !== '')<p>{{ $document['description'] }}</p>@endif
    <p class="meta">{{ $document['source'] }} · {{ $document['status'] }} · {{ __('Generated') }} {{ $document['generated'] }}</p>
    @include('reports.builder._document', ['document' => $document])
</body>
</html>
