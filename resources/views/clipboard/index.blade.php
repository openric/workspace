{{-- OpenRiC Workspace - the clipboard, signed in. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Clipboard')
@section('content')
    @include('clipboard._body', ['rows' => $rows])
@endsection
