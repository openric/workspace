{{-- OpenRiC Workspace - what an export leaves out of one entity, read from the export itself (roadmap phase 23). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- $lost: ExportLoss rows; $format: the format's name; $json: the machine-readable report. --}}
<details class="left-out">
    <summary>{{ $lost === [] ? __('Everything of this record you may read is in the :format export.', ['format' => $format]) : trans_choice('{1} :count thing of this record is not in the :format export.|[2,*] :count things of this record are not in the :format export.', count($lost), ['count' => count($lost), 'format' => $format]) }}</summary>
    @if($lost !== [])
        <ul>@foreach($lost as $item)<li>{{ $item['what'] }}: {{ \Illuminate\Support\Str::limit($item['value'], 120) }}</li>@endforeach</ul>
    @endif
    <p><small>{{ __('Found by reading the export itself.') }} <a href="{{ $json }}">{{ __('The same as JSON') }}</a></small></p>
</details>
