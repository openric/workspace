{{-- OpenRiC Workspace - labels a form or view laid out by a traditional standard as a compatibility view (roadmap phase 24, decided by Dr Johan Pieterse on 2 November 2026 after the design review). Needs $standard. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($standardName = ['isad-g' => 'ISAD(G)', 'isaar-cpf' => 'ISAAR(CPF)', 'isdiah' => 'ISDIAH', 'dacs' => 'DACS', 'rad' => 'RAD', 'dc' => 'Dublin Core', 'mods' => 'MODS'][$standard] ?? null)
@if($standardName)
    <p class="meta compatibility-view"><strong>{{ __('Compatibility view') }}:</strong> {{ __('laid out in the areas of :standard. The description is kept as RiC entities, its names, dates and relations with their evidence, and is exported as RiC-O; this layout is one way of reading and entering it.', ['standard' => $standardName]) }}</p>
@endif
