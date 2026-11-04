{{-- OpenRiC Workspace - visitors' comments on the institution's articles, moderated (Heratio's comment moderation; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Article comments'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Article comments') }}</h1>
    <p><a href="{{ route('site.articles') }}">{{ __('Articles') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('Comments are shown under the article as soon as they are posted. Hold one as pending, or mark it as spam, to take it off the article.') }}</p>
    <table><thead><tr><th scope="col">{{ __('Article') }}</th><th scope="col">{{ __('Comment') }}</th><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($comments as $c)<tr><td><a href="{{ route('site.articles.edit', $c->article) }}">{{ $c->article->title }}</a></td>
            <td><strong>{{ $c->author_name ?? __('Anonymous') }}</strong><br><span style="white-space: pre-line">{{ \Illuminate\Support\Str::limit($c->body, 500) }}</span>@if($c->ip)<br><span class="meta">{{ $c->ip }}</span>@endif</td>
            <td>{{ $c->created_at->format('Y-m-d H:i') }}</td>
            <td><form method="post" action="{{ route('site.comments.update', $c) }}">@csrf @method('PUT')<label for="cs-{{ $c->id }}" class="meta">{{ __('Status') }}</label>
                <select id="cs-{{ $c->id }}" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($s->id === $c->status_id)>{{ $s->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('site.comments.destroy', $c) }}" onsubmit="return confirm(@json(__('Delete this comment?')))">@csrf @method('DELETE')<button type="submit">{{ __('Delete') }}</button></form></td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
