{{-- OpenRiC Workspace - the discussions of a research project or workspace (Heratio's discussions; roadmap phase 19f). Needs $home, $homeKind ('projects' or 'workspaces'), $discussions, $talk (0 none, 1 read, 2 write, 3 manage). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<h2>{{ __('Discussions') }}</h2>
@forelse($discussions as $d)
    <article style="border-top: 1px solid var(--line); padding: .3rem 0">
        <p>@if($d->is_pinned)<strong>{{ __('Pinned') }}</strong> · @endif @if($d->subject)<strong>{{ $d->subject }}</strong> · @endif<span class="meta">{{ $d->user?->name }} · {{ $d->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}@if($d->is_resolved) · {{ __('resolved') }}@endif</span></p>
        <p style="white-space: pre-wrap">{{ $d->content }}</p>
        @foreach($d->replies as $r)<p style="margin-left: 1.5rem"><span class="meta">{{ $r->user?->name }} · {{ $r->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}</span><br><span style="white-space: pre-wrap">{{ $r->content }}</span>
            @if($talk >= 3 || $r->user_id === auth()->id())<form method="post" action="{{ route('research.discussions.step', [$r, 'delete']) }}" style="display: inline">@csrf<button type="submit">{{ __('Delete') }}</button></form>@endif</p>@endforeach
        @if($talk >= 2)
            <details style="margin-left: 1.5rem"><summary>{{ __('Reply') }}</summary><form method="post" action="{{ route('research.discussions.store', [$homeKind, $home->id]) }}">@csrf<input type="hidden" name="parent_id" value="{{ $d->id }}">
                <textarea name="content" rows="2" cols="70" required aria-label="{{ __('Reply') }}"></textarea><br><button type="submit">{{ __('Reply') }}</button></form></details>
        @endif
        @if($talk >= 3 || $d->user_id === auth()->id())
            <form method="post" action="{{ route('research.discussions.step', [$d, 'resolve']) }}" style="display: inline">@csrf<button type="submit">{{ $d->is_resolved ? __('Open again') : __('Resolve') }}</button></form>
            @if($talk >= 3)<form method="post" action="{{ route('research.discussions.step', [$d, 'pin']) }}" style="display: inline">@csrf<button type="submit">{{ $d->is_pinned ? __('Unpin') : __('Pin') }}</button></form>@endif
            <form method="post" action="{{ route('research.discussions.step', [$d, 'delete']) }}" style="display: inline">@csrf<button type="submit">{{ __('Delete') }}</button></form>
        @endif
    </article>
@empty<p>{{ __('None.') }}</p>@endforelse
@if($talk >= 2)
    <details><summary>{{ __('Start a discussion') }}</summary><form method="post" action="{{ route('research.discussions.store', [$homeKind, $home->id]) }}">@csrf
        <label for="ds-subject">{{ __('Subject') }}</label> <input id="ds-subject" name="subject" size="50"><br>
        <label for="ds-content">{{ __('Text') }}</label><br><textarea id="ds-content" name="content" rows="4" cols="80" required></textarea><br><button type="submit">{{ __('Post') }}</button></form></details>
@endif
