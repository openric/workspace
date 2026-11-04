{{-- OpenRiC Workspace - one article: its text, its files in their sections, the articles linked to it, and visitors' comments (Heratio's article page; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $article->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <article class="site-text">
        <p class="meta"><a href="{{ route('site.articles-public') }}">{{ __('Articles') }}</a>@if($article->article_group) · <a href="{{ route('site.articles-public', ['group' => $article->article_group]) }}">{{ $article->article_group }}</a>@endif</p>
        <h1>{{ $article->title }}</h1>
        <p class="meta">{{ ($article->published_at ?? $article->created_at)->format('j F Y') }}@if($article->author) · {{ $article->author }}@endif</p>
        @if($article->cover)<img src="{{ route('site.file', $article->cover) }}" alt="" style="max-width: 100%; border-radius: var(--radius)">@endif
        {!! $html !!}
        @if($attachments->isNotEmpty())
            <section aria-labelledby="files">
                <h2 id="files">{{ $article->attachments_label ?: __('Files') }}</h2>
                @foreach($attachments->groupBy(fn ($a) => $a->group_label ?? '') as $section => $files)
                    @if($section !== '')<h3>{{ $section }}</h3>@endif
                    <ul>@foreach($files as $f)<li>
                        @if($f->isVideo())<figure style="margin: 0"><video controls preload="metadata" style="max-width: 100%" src="{{ route('site.file', $f->file) }}"></video><figcaption>{{ $f->title }}</figcaption></figure>
                        @else<a href="{{ route('site.file', $f->file) }}">{{ $f->title }}</a> <span class="meta">{{ $f->kind->label($lang) }} · {{ \Illuminate\Support\Number::fileSize($f->file->size_bytes) }}</span>@endif
                        @if($f->description)<br><span class="meta">{{ $f->description }}</span>@endif</li>@endforeach</ul>
                @endforeach
            </section>
        @endif
        @if($related->isNotEmpty())
            <section aria-labelledby="related"><h2 id="related">{{ __('Related articles') }}</h2>
                <ul>@foreach($related as $r)<li><a href="{{ route('site.article', $r['article']->slug) }}">{{ $r['article']->title }}</a>@if($r['description']) <span class="meta">{{ $r['description'] }}</span>@endif</li>@endforeach</ul></section>
        @endif
        <section id="comments" aria-labelledby="comments-h">
            <h2 id="comments-h">{{ __('Comments') }}</h2>
            @if(session('comment_success'))<p role="status">{{ session('comment_success') }}</p>@endif
            @if(session('comment_error'))<p class="error" role="alert">{{ session('comment_error') }}</p>@endif
            @forelse($comments as $c)<div style="margin: .8rem 0"><strong>{{ $c->author_name ?? __('Anonymous') }}</strong> <span class="meta">{{ $c->created_at->format('j M Y H:i') }}</span><p style="white-space: pre-line; margin: .2rem 0">{{ $c->body }}</p></div>
            @empty<p class="meta">{{ __('No comments yet.') }}</p>@endforelse
            <form method="post" action="{{ route('site.article.comment', $article->slug) }}">@csrf
                <label for="cn">{{ __('Your name (optional)') }}</label><input id="cn" name="author_name" maxlength="150" value="{{ old('author_name') }}">
                <label for="cb">{{ __('Comment') }}</label><textarea id="cb" name="body" required minlength="2" maxlength="4000" rows="4">{{ old('body') }}</textarea>
                @error('body')<p class="error">{{ $message }}</p>@enderror
                {{-- Only robots fill this in. --}}
                <div style="position: absolute; left: -9999px" aria-hidden="true"><label for="cw">Website</label><input id="cw" name="website" tabindex="-1" autocomplete="off"></div>
                <button type="submit">{{ __('Post comment') }}</button>
            </form>
        </section>
    </article>
    <script>
        // A read is counted once the article has been seen for a few seconds (Heratio's read count).
        setTimeout(function () {
            fetch(@json(route('site.article.read', $article->slug)), {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': @json(csrf_token())}, credentials: 'same-origin'});
        }, 5000);
    </script>
@endsection
