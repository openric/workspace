{{-- OpenRiC Workspace - the system map: how a record moves through the Workspace, stage by stage (Heratio's system map; roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends($layout)
@section('title', __('System map'))
@section('content')
    @include('help._nav')
    <h1>{{ __('System map') }}</h1>
    <p>{{ __('How a record moves through the Workspace, from what arrives to what researchers use. Open a stage to see its parts, and a part to see its details; each links to the guide that explains it.') }}</p>
    <ol class="meta" style="display: flex; flex-wrap: wrap; gap: .4rem 1.4rem; padding-left: 1.2rem">@foreach($map as $stage)<li>{{ __($stage['label']) }}</li>@endforeach</ol>
    @include('help._tree', ['nodes' => $map, 'depth' => 0])
@endsection
