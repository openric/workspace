{{-- OpenRiC Workspace - how the institution shows its 3D models by default (roadmap phase 21, after Heratio's ahg-3d-model settings and viewer_3d_settings). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('3D models'))
@section('content')
    <h1>{{ __('How 3D models are shown') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('The institution\'s defaults. A model may change any of them on its own page; left empty here, each is as Heratio shows a model.') }}</p>
    @include('models._settings', ['action' => route('models.defaults.save'), 'own' => $own, 'effective' => \App\Files\ModelViews::DEFAULTS, 'placements' => $placements])
@endsection
