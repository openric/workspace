{{-- OpenRiC Workspace - a sheet of labels to print (Heratio's ahg-label; roadmap phase 22a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Labels') }}</title>
    <style>
        @page { size: {{ $page[0] }}mm {{ $page[1] }}mm; margin: 0; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; font-size: {{ $template->font_size_pt }}pt; color: #000; background: #fff; }
        .controls { padding: 1rem; font-size: 12pt; background: #f2f2f2; }
        .sheet { width: {{ $page[0] }}mm; min-height: {{ $page[1] }}mm; box-sizing: border-box; padding: {{ $template->margin_mm }}mm; display: grid;
            grid-template-columns: repeat({{ $template->columns }}, {{ $template->label_width_mm }}mm); grid-auto-rows: {{ $template->label_height_mm }}mm;
            gap: {{ $template->gutter_mm }}mm; align-content: start; page-break-after: always; }
        .label { overflow: hidden; box-sizing: border-box; padding: 1.5mm; outline: 0.1mm dashed #bbb; display: flex; gap: 1.5mm; }
        .label .text { flex: 1; min-width: 0; }
        .label .title { font-weight: bold; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .label .barcode svg { width: 100%; height: auto; max-height: {{ $template->label_height_mm * 0.45 }}mm; }
        .label .qr svg { width: {{ min($template->label_height_mm - 3, 25) }}mm; height: auto; }
        @media print { .controls { display: none; } .label { outline: none; } }
    </style>
</head>
<body>
    <form class="controls" method="get">
        @foreach($query as $k => $v)@if(is_array($v))@foreach($v as $item)<input type="hidden" name="{{ $k }}[]" value="{{ $item }}">@endforeach @else<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
        <label for="template">{{ __('Template') }}</label> <select id="template" name="template">@foreach($templates as $t)<option value="{{ $t->id }}" @selected($t->id === $template->id)>{{ $t->name }}</option>@endforeach</select>
        <label for="skip">{{ __('Labels already used on the sheet') }}</label> <input id="skip" name="skip" type="number" min="0" max="300" value="{{ $skip }}" style="width: 4rem">
        <button type="submit">{{ __('Show') }}</button> <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        {{ trans_choice(':count label|:count labels', count($labels), ['count' => count($labels)]) }}
    </form>
    @php($perSheet = $template->columns * $template->rows)
    @foreach(collect(array_fill(0, $skip, null))->concat($labels)->chunk($perSheet) as $sheet)
        <div class="sheet">@foreach($sheet as $label)
            <div class="label">@if($label)
                <div class="text">
                    @if($template->show_title)<div class="title">{{ $label['title'] }}</div>@endif
                    @if($template->show_identifier && $label['identifier'])<div>{{ $label['identifier'] }}</div>@endif
                    @if($template->show_repository && $label['repository'])<div>{{ $label['repository'] }}</div>@endif
                    @if($label['barcode'])<div class="barcode">{!! $label['barcode'] !!}</div>@endif
                </div>
                @if($label['qr'])<div class="qr">{!! $label['qr'] !!}</div>@endif
            @endif</div>
        @endforeach</div>
    @endforeach
</body>
</html>
