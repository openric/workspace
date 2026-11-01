{{-- OpenRiC Workspace - feedback on the catalogue or a record (roadmap phase 19a), after Heratio's feedback form (ahg-feedback). Needs $types, $record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends(auth()->check() ? 'layouts.app' : 'layouts.public')
@section('title', __('Feedback'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Feedback') }}</h1>
    @if($record)<p>{{ __('About :record', ['record' => \App\Relations\EntityKinds::label($record, $lang)]) }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('feedback.store') }}">
        @csrf
        <input type="hidden" name="record" value="{{ $record?->id }}">
        {{-- People leave this empty; robots fill it. --}}
        <div style="position: absolute; left: -10000px" aria-hidden="true"><label for="fb-website">Website</label><input id="fb-website" name="website" tabindex="-1" autocomplete="off"></div>
        <p><label for="fb-type">{{ __('Type') }}</label> <select id="fb-type" name="type">@foreach($types as $t)<option value="{{ $t->notation }}" @selected(old('type', $record ? 'correction' : 'general') === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select></p>
        <p><label for="fb-subject">{{ __('Subject') }}</label><br><input id="fb-subject" name="subject" required maxlength="1024" size="60" value="{{ old('subject') }}"></p>
        <p><label for="fb-remarks">{{ __('Remarks') }}</label><br><textarea id="fb-remarks" name="remarks" required rows="6" cols="70">{{ old('remarks') }}</textarea></p>
        <p><label for="fb-name">{{ __('Name') }}</label> <input id="fb-name" name="name" required maxlength="100" value="{{ old('name', auth()->user()?->name) }}">
            <label for="fb-surname">{{ __('Surname') }}</label> <input id="fb-surname" name="surname" required maxlength="100" value="{{ old('surname') }}"></p>
        <p><label for="fb-email">{{ __('Email') }}</label> <input id="fb-email" name="email" type="email" required maxlength="255" value="{{ old('email', auth()->user()?->email) }}">
            <label for="fb-phone">{{ __('Telephone') }}</label> <input id="fb-phone" name="phone" maxlength="50" value="{{ old('phone') }}"></p>
        <p><label for="fb-relationship">{{ __('Your relationship to the record (optional)') }}</label><br><textarea id="fb-relationship" name="relationship" rows="2" cols="70">{{ old('relationship') }}</textarea></p>
        <p class="meta">{{ __('Your name and contact details are kept with your feedback so that staff can answer you, and are not published.') }}</p>
        <button type="submit">{{ __('Send') }}</button>
    </form>
@endsection
