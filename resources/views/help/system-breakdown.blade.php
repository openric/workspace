{{-- OpenRiC Workspace - the system breakdown: what the Workspace does for each kind of entity (Heratio's system breakdown; roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends($layout)
@section('title', __('System breakdown'))
@section('content')
    @include('help._nav')
    <h1>{{ __('System breakdown') }}</h1>
    <p>{{ __('What the Workspace does for each kind of entity it describes: the entity, an aspect of it, and what that holds. Each links to the guide that explains it.') }}</p>
    @include('help._tree', ['nodes' => $tree, 'depth' => 0])
@endsection
