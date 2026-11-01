{{-- OpenRiC Workspace - kinds of researcher and what each may do (Heratio's researcher types; roadmap phase 19b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Kinds of researcher'))
@section('content')
    <h1>{{ __('Kinds of researcher') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @foreach($types as $t)<details><summary>{{ $t->name }} ({{ trans_choice(':count researcher|:count researchers', $t->researchers_count, ['count' => $t->researchers_count]) }})@unless($t->is_active) ({{ __('inactive') }})@endunless</summary>
        <form method="post" action="{{ route('reading-room.types.update', $t) }}">@csrf @include('reading-room._type-fields', ['t' => $t, 'p' => 't'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>@endforeach
    <details><summary>{{ __('Add a kind of researcher') }}</summary><form method="post" action="{{ route('reading-room.types.store') }}">@csrf @include('reading-room._type-fields', ['t' => null, 'p' => 't-new'])<button type="submit">{{ __('Add') }}</button></form></details>
@endsection
