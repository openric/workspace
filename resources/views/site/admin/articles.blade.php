{{-- OpenRiC Workspace - the institution's articles (Heratio's articles admin; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Articles'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Articles') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p><a href="{{ route('site.articles.create') }}">{{ __('New article') }}</a> · <a href="{{ route('site.comments') }}">{{ __('Comments') }}@if($pending) ({{ trans_choice(':count waiting|:count waiting', $pending, ['count' => $pending]) }})@endif</a> · <a href="{{ route('site.articles-public') }}">{{ __('On the public site') }}</a></p>
    <table><thead><tr><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Group') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Published') }}</th><th scope="col">{{ __('Reads') }}</th><th scope="col">{{ __('Files') }}</th><th scope="col">{{ __('Comments') }}</th></tr></thead>
        <tbody>@forelse($articles as $a)<tr><td><a href="{{ route('site.articles.edit', $a) }}">{{ $a->title }}</a></td><td>{{ $a->article_group }}</td><td>{{ $a->status->label($lang) }}</td>
            <td>{{ $a->published_at?->format('Y-m-d') }}</td><td>{{ $a->view_count }}</td><td>{{ $a->attachments_count }}</td><td>{{ $a->comments_count }}</td></tr>
        @empty<tr><td colspan="7">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
