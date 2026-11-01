{{-- OpenRiC Workspace - feedback received (Heratio's submit-success). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends(auth()->check() ? 'layouts.app' : 'layouts.public')
@section('title', __('Feedback'))
@section('content')
    <h1>{{ __('Thank you') }}</h1>
    <p>{{ __('Thank you for your feedback. We will review it shortly.') }}</p>
    @if($record)<p><a href="{{ route('discover.record', $record->id) }}">{{ __('Back to the record') }}</a></p>@endif
@endsection
