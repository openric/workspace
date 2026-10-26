{{-- OpenRiC Workspace - the clipboard, in the public catalogue. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Clipboard'))
@section('content')
    @include('clipboard._body', ['rows' => $rows])
@endsection
