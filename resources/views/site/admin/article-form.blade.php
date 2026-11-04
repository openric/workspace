{{-- OpenRiC Workspace - an article: its text, status and cover, its files in sections and in order, the articles it links to, and pictures for its text (Heratio's article form; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $article ? __('Edit article') : __('New article'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $article ? __('Edit article') : __('New article') }}</h1>
    <p><a href="{{ route('site.articles') }}">{{ __('Articles') }}</a>@if($article?->isPublic()) · <a href="{{ route('site.article', $article->slug) }}">{{ __('On the public site') }}</a>@endif</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" enctype="multipart/form-data" action="{{ $article ? route('site.articles.update', $article) : route('site.articles.store') }}">@csrf @if($article)@method('PUT')@endif
        <label for="at">{{ __('Title') }}</label><input id="at" name="title" required maxlength="255" value="{{ old('title', $article?->title) }}">
        <label for="as">{{ __('Address') }}</label><input id="as" name="slug" maxlength="200" value="{{ old('slug', $article?->slug) }}">
        <p class="meta">{{ __('Made from the title when left empty.') }}</p>
        <label for="ag">{{ __('Group') }}</label><input id="ag" name="article_group" maxlength="100" list="article-groups" value="{{ old('article_group', $article?->article_group) }}">
        <datalist id="article-groups">@foreach($groups as $g)<option value="{{ $g }}">@endforeach</datalist>
        <label for="aa">{{ __('Author') }}</label><input id="aa" name="author" maxlength="150" value="{{ old('author', $article?->author) }}">
        <label for="ae">{{ __('Summary') }}</label><textarea id="ae" name="excerpt" maxlength="500" rows="2">{{ old('excerpt', $article?->excerpt) }}</textarea>
        <label for="ab">{{ __('Text') }} ({{ __('Markdown') }})</label><textarea id="ab" name="body" rows="20">{{ old('body', $article?->body) }}</textarea>
        <label for="al">{{ __('Heading over the files') }}</label><input id="al" name="attachments_label" maxlength="255" value="{{ old('attachments_label', $article?->attachments_label) }}" placeholder="{{ __('Files') }}">
        <label for="ast">{{ __('Status') }}</label><select id="ast" name="status"><option value="draft" @selected(old('status', $article?->status?->notation ?? 'draft') === 'draft')>{{ __('Draft') }}</option><option value="published" @selected(old('status', $article?->status?->notation) === 'published')>{{ __('Published') }}</option></select>
        <label for="ap">{{ __('Publication date') }}</label><input id="ap" name="published_at" type="datetime-local" value="{{ old('published_at', $article?->published_at?->format('Y-m-d\TH:i')) }}">
        <p class="meta">{{ __('Set the first time it is published, unless one is given; a date to come keeps it off the public site until then.') }}</p>
        <label for="ac">{{ __('Cover picture') }}</label>@if($article?->cover)<img src="{{ route('site.file', $article->cover) }}" alt="" style="max-width: 12rem; display: block">@endif<input id="ac" name="cover" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
        <button type="submit">{{ __('Save') }}</button>
    </form>
    @if($article)
        <h2>{{ __('Pictures for the text') }}</h2>
        <p class="meta">{{ __('Upload a picture to get its address, then put it in the text as ![a description](its address). A picture of an article is shown only while the article is published.') }}</p>
        <form id="image-upload"><label for="ai">{{ __('Picture') }}</label><input id="ai" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required> <button type="submit">{{ __('Upload') }}</button> <output id="image-address"></output></form>
        <script>
            document.getElementById('image-upload').addEventListener('submit', function (e) {
                e.preventDefault();
                const body = new FormData(this); body.append('article', @json($article->id));
                fetch(@json(route('site.images.store')), {method: 'POST', body: body, headers: {'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json'}, credentials: 'same-origin'})
                    .then(function (r) { return r.json(); }).then(function (d) { document.getElementById('image-address').textContent = d.url ? '![](' + d.url + ')' : (d.error || d.message); });
            });
        </script>

        <h2 id="attachments">{{ __('Files') }}</h2>
        @if($attachments->isNotEmpty())
            <table><thead><tr><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('File') }}</th><th scope="col">{{ __('Change') }}</th></tr></thead><tbody>
                @php($ids = $attachments->pluck('id')->all())
                @foreach($attachments as $i => $f)<tr><td>@include('site.admin._move', ['ids' => $ids, 'i' => $i, 'action' => route('site.attachments.order', $article)])</td><td><a href="{{ route('site.file', $f->file) }}">{{ $f->title }}</a><br><span class="meta">{{ $f->kind->label($lang) }}@if($f->group_label) · {{ $f->group_label }}@endif · {{ $f->file->file_name }} · {{ \Illuminate\Support\Number::fileSize($f->file->size_bytes) }}</span></td>
                    <td><details><summary>{{ __('Change') }}</summary><form method="post" enctype="multipart/form-data" action="{{ route('site.attachments.update', [$article, $f]) }}">@csrf @method('PUT')
                        @include('site.admin._attachment-fields', ['f' => $f, 'id' => $f->id])
                        <label for="af-file-{{ $f->id }}">{{ __('Replace the file') }}</label><input id="af-file-{{ $f->id }}" name="file" type="file"> <button type="submit">{{ __('Save') }}</button></form>
                        <form method="post" action="{{ route('site.attachments.destroy', [$article, $f]) }}" onsubmit="return confirm(@json(__('Remove this file?')))">@csrf @method('DELETE')<button type="submit">{{ __('Remove') }}</button></form></details></td></tr>@endforeach
            </tbody></table>
        @endif
        <details @if($attachments->isEmpty()) open @endif><summary>{{ __('Attach a file') }}</summary>
            <form method="post" enctype="multipart/form-data" action="{{ route('site.attachments.store', $article) }}">@csrf
                <label for="an-file">{{ __('File') }}</label><input id="an-file" name="file" type="file" required accept="{{ collect(\App\Site\SiteFiles::ATTACHMENTS)->map(fn ($e) => '.'.$e)->join(',') }}">
                @include('site.admin._attachment-fields', ['f' => null, 'id' => 'new'])
                <button type="submit">{{ __('Attach') }}</button></form></details>

        <h2>{{ __('Related articles') }}</h2>
        @if($related->isNotEmpty())@php($linkIds = $related->map(fn ($r) => $r['article']->id)->all())<ul>@foreach($related as $i => $r)<li>@include('site.admin._move', ['ids' => $linkIds, 'i' => $i, 'action' => route('site.links.order', $article)]) <a href="{{ route('site.articles.edit', $r['article']) }}">{{ $r['article']->title }}</a>@if($r['description']) <span class="meta">{{ $r['description'] }}</span>@endif
            <form method="post" action="{{ route('site.links.destroy', [$article, $r['article']]) }}" style="display: inline">@csrf @method('DELETE')<button type="submit" style="margin: 0; padding: .1rem .5rem">{{ __('Remove') }}</button></form></li>@endforeach</ul>
        @endif
        @if($others->isNotEmpty())
            <form method="post" action="{{ route('site.links.store', $article) }}">@csrf<label for="lt">{{ __('Link to') }}</label><select id="lt" name="target">@foreach($others as $o)<option value="{{ $o->id }}">{{ $o->title }}</option>@endforeach</select>
                <label for="ld">{{ __('Why it is related (optional)') }}</label><input id="ld" name="description" maxlength="500"> <button type="submit">{{ __('Link') }}</button></form>
        @endif
        <form method="post" action="{{ route('site.articles.destroy', $article) }}" onsubmit="return confirm(@json(__('Delete this article with its files and comments?')))">@csrf @method('DELETE')<button type="submit">{{ __('Delete article') }}</button></form>
    @endif
@endsection
