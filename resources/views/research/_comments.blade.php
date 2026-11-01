{{-- OpenRiC Workspace - the comments on an annotation, diary entry or collection (Heratio's research_comment; roadmap phase 19f). Needs $subject and $kind (annotation, diary, collection). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($service = app(\App\Research\Collaboration::class))
@php($comments = $service->comments($subject))
<details class="no-print"><summary>{{ trans_choice('{0} Comments|{1} :count comment|[2,*] :count comments', $comments->count(), ['count' => $comments->count()]) }}</summary>
    @foreach($comments as $c)
        <p><span class="meta">{{ $c->user?->name }} · {{ $c->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}@if($c->is_resolved) · {{ __('resolved') }}@endif</span><br><span style="white-space: pre-wrap">{{ $c->content }}</span>
            @if($c->user_id === auth()->id() || $service->owner($subject)?->id === auth()->id())<form method="post" action="{{ route('research.comments.step', [$c, 'resolve']) }}" style="display: inline">@csrf<button type="submit">{{ $c->is_resolved ? __('Open again') : __('Resolve') }}</button></form>
                <form method="post" action="{{ route('research.comments.step', [$c, 'delete']) }}" style="display: inline">@csrf<button type="submit">{{ __('Delete') }}</button></form>@endif</p>
        @foreach($c->replies as $r)<p style="margin-left: 1.5rem"><span class="meta">{{ $r->user?->name }}</span> {{ $r->content }}</p>@endforeach
    @endforeach
    <form method="post" action="{{ route('research.comments.store', [$kind, $subject->getKey()]) }}">@csrf<textarea name="content" rows="2" cols="60" required aria-label="{{ __('Comment') }}"></textarea> <button type="submit">{{ __('Comment') }}</button></form>
</details>
