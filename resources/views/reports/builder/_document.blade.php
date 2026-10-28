{{-- OpenRiC Workspace - a built report's sections, on screen and in its PDF (roadmap phase 8). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $document (ReportBuilder::document). Optional $limit: on screen, a table shows its first rows only. --}}
@foreach($document['sections'] as $section)
    <section class="report-section">
        @if($section['title'] !== '')<h2>{{ $section['title'] }}</h2>@endif
        @if($section['type'] === 'text')
            @foreach($section['paragraphs'] as $paragraph)<p>{!! nl2br(e($paragraph)) !!}</p>@endforeach
        @elseif($section['type'] === 'summary')
            <table><tbody>@foreach($section['rows'] as [$label, $value])<tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table>
        @else
            @if($section['rows'] === [])<p>{{ __('None.') }}</p>@else
                <table><thead><tr>@foreach($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead><tbody>
                    @foreach(isset($limit) ? array_slice($section['rows'], 0, $limit) : $section['rows'] as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach
                </tbody></table>
                @if(isset($limit) && count($section['rows']) > $limit)<p class="meta">{{ __('The first :limit of :count; the exports have them all.', ['limit' => $limit, 'count' => count($section['rows'])]) }}</p>@endif
            @endif
        @endif
    </section>
@endforeach
@if($document['cut'])<p class="meta">{{ __('Only the first :limit entities are included.', ['limit' => \App\Reports\ReportBuilder::ROW_LIMIT]) }}</p>@endif
