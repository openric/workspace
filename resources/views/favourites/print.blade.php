{{-- OpenRiC Workspace - favourites to print, or as PDF (Heratio's printHtml; roadmap phase 19a). Needs $items, $folder. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><title>{{ $folder?->name ?? __('Favourites') }}</title>
<style>body { font-family: Arial, sans-serif; font-size: 11pt } table { border-collapse: collapse; width: 100% } th, td { border: 1px solid #999; padding: 4px; text-align: left; vertical-align: top } th { background: #10373E; color: #fff }</style></head>
<body><h1>{{ $folder?->name ?? __('Favourites') }}</h1><p>{{ now(config('workspace.timezone'))->format('j F Y') }}</p>
<table><thead><tr><th>{{ __('Title') }}</th><th>{{ __('Reference code') }}</th><th>{{ __('Address') }}</th><th>{{ __('Notes') }}</th></tr></thead>
<tbody>@foreach($items as $i)<tr><td>{{ $i['title'] }}</td><td>{{ $i['reference'] }}</td><td>{{ $i['entity']?->getAttribute('iri') ?? $i['url'] }}</td><td>{{ $i['favourite']->notes }}</td></tr>@endforeach</tbody></table>
</body></html>
