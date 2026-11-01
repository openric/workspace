{{-- OpenRiC Workspace - a research project (Heratio's viewProject and its collaboration, milestone, team, output and export pages; roadmap phase 19f). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    @php($may = fn (?string $addedBy) => $level >= 3 || $level >= 2 && $addedBy === $user->id)
    <p><a href="{{ route('research.projects.index') }}">{{ __('Research projects') }}</a></p>
    <h1>{{ $project->title }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}@if(session('share_link')) <code>{{ session('share_link') }}</code>@endif</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ $project->projectType->label($lang) }} · {{ $project->status->label($lang) }} · {{ $project->visibility->label($lang) }} · {{ __('Owner') }}: {{ $project->owner->name }}
        @if($project->start_date) · {{ $project->start_date->format('j M Y') }}@if($project->expected_end_date) - {{ $project->expected_end_date->format('j M Y') }}@endif @endif</p>
    @if($project->description)<p style="white-space: pre-wrap">{{ $project->description }}</p>@endif
    <p class="meta">@foreach(['affiliation' => 'Institution', 'supervisor' => 'Supervisor', 'funding_source' => 'Funding source', 'grant_number' => 'Grant number', 'ethics_approval' => 'Ethics approval'] as $f => $l)@if($project->{$f}){{ __($l) }}: {{ $project->{$f} }} · @endif @endforeach
        {{ __('Milestones') }}: {{ __(':progress% done', ['progress' => $summary['milestones']['progress']]) }}@if($summary['milestones']['overdue']), {{ trans_choice(':count overdue|:count overdue', $summary['milestones']['overdue'], ['count' => $summary['milestones']['overdue']]) }}@endif</p>
    <p class="no-print">@foreach(['research.reasoning.claims' => 'Claims', 'research.reasoning.graph' => 'Knowledge graph', 'research.reasoning.hypotheses' => 'Hypotheses', 'research.reasoning.contradictions' => 'Contradictions', 'research.reasoning.decisions' => 'Decision log', 'research.reasoning.analysis' => 'Analysis results'] as $r => $l)<a href="{{ route($r, $project) }}">{{ __($l) }}</a> · @endforeach</p>
    <p class="no-print">@foreach(['research.studios.question' => 'Question builder', 'research.studios.method' => 'Method studio', 'research.studios.triage' => 'Source triage', 'research.studios.argument' => 'Argument builder', 'research.studios.writing' => 'Writing studio', 'research.studios.review' => 'Review studio', 'research.studios.publication' => 'Publication studio', 'research.studios.time-machine' => 'Time machine'] as $r => $l)<a href="{{ route($r, $project) }}">{{ __($l) }}</a> · @endforeach</p>
    <p class="no-print">@foreach(['research.analysis.snapshots' => 'Snapshots', 'research.analysis.timeline' => 'Timeline', 'research.analysis.map' => 'Map', 'research.analysis.alerts' => 'Field alerts'] as $r => $l)<a href="{{ route($r, $project) }}">{{ __($l) }}</a> · @endforeach</p>
    <p class="no-print">{{ __('Export') }}: @foreach($formats as $f => $l)<a href="{{ route('research.projects.export', [$project, $f]) }}">{{ __($l) }}</a>@if(! $loop->last), @endif @endforeach</p>
    <p id="pj-presence" class="meta" aria-live="polite"></p>

    <h2>{{ __('Collaborators') }}</h2>
    @foreach($project->members->filter(fn ($m) => $level >= 3 || ! in_array($m->status->notation, ['removed', 'declined'], true)) as $m)
        <p>{{ $m->user->name }} · {{ $m->role->label($lang) }} · <span class="meta">{{ $m->status->label($lang) }}</span>
            @if($level >= 3 && $m->role->notation !== 'owner' && $m->status->notation === 'accepted')<form method="post" action="{{ route('research.projects.member', [$m, 'role']) }}" style="display: inline">@csrf
                <select name="role" aria-label="{{ __('Role of :name', ['name' => $m->user->name]) }}">@foreach($roles as $c)@if($c->notation !== 'owner' || $level >= 4)<option value="{{ $c->notation }}" @selected($m->role->notation === $c->notation)>{{ $c->label($lang) }}</option>@endif @endforeach</select> <button type="submit">{{ __('Change') }}</button></form>@endif
            @if($m->role->notation !== 'owner' && in_array($m->status->notation, ['pending', 'accepted'], true) && ($level >= 3 || $m->user_id === $user->id))<form method="post" action="{{ route('research.projects.member', [$m, 'remove']) }}" style="display: inline">@csrf<button type="submit">{{ $m->user_id === $user->id ? __('Leave') : __('Remove') }}</button></form>@endif</p>
    @endforeach
    @if($level >= 3)
        <form method="post" action="{{ route('research.projects.step', [$project, 'invite']) }}">@csrf<label for="pi-email">{{ __('Invite by e-mail address') }}</label> <input id="pi-email" name="email" type="email" required>
            <label for="pi-role">{{ __('Role') }}</label> <select id="pi-role" name="role">@foreach($roles->reject(fn ($c) => $c->notation === 'owner') as $c)<option value="{{ $c->notation }}" @selected($c->notation === 'contributor')>{{ $c->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Invite') }}</button></form>
    @endif

    <h2>{{ __('Resources') }}</h2>
    @forelse($project->resources as $r)
        <p><span class="meta">{{ $r->resourceType->label($lang) }}@if($r->linkType) · {{ $r->linkType->label($lang) }}@endif</span> ·
            @if($r->external_url)<a href="{{ $r->external_url }}" rel="noopener noreferrer">{{ $r->title }}</a>@elseif($r->record)<a href="{{ route('records.show', $r->record) }}">{{ $r->title }}</a>@elseif($r->resource_id || $r->file_id)<a href="{{ route('research.projects.resource', $r) }}">{{ $r->title }}</a>@else<strong>{{ $r->title }}</strong>@endif
            <span class="meta">{{ $r->addedBy?->name }}</span>@if($r->tags) · <span class="meta">{{ $r->tags }}</span>@endif @if($r->description)<br>{{ $r->description }}@endif @if($r->notes)<br><span style="white-space: pre-wrap">{{ $r->notes }}</span>@endif
            @if($may($r->added_by_id))<form method="post" action="{{ route('research.projects.resource.remove', $r) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($collections->isNotEmpty())<p>{{ __('Collections in the project') }}: @foreach($collections as $c)@if($c->user_id === $user->id)<a href="{{ route('research.collections.show', $c) }}">{{ $c->name }}</a>@else{{ $c->name }}@endif @if(! $loop->last), @endif @endforeach</p>@endif
    @if($level >= 2)
        <details><summary>{{ __('Add a resource') }}</summary>
            <form method="post" action="{{ route('research.projects.step', [$project, 'resource']) }}" enctype="multipart/form-data">@csrf
                <label for="pr-type">{{ __('Kind') }}</label> <select id="pr-type" name="resource_type">@foreach($kinds as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                <label for="pr-id">{{ __('One of yours') }}</label> <select id="pr-id" name="resource_id"><option value="">{{ __('None') }}</option>@foreach($mine as $type => $list)@foreach($list as $x)<option value="{{ $x->id }}">{{ $kinds->firstWhere('notation', $type)?->label($lang) }}: {{ $x->name ?? $x->title ?? \Illuminate\Support\Str::limit((string) $x->content, 60) }}</option>@endforeach @endforeach</select><br>
                <label for="pr-iri">{{ __('A record (its persistent IRI)') }}</label> <input id="pr-iri" name="iri" size="40">
                <label for="pr-url">{{ __('Web address, for a link') }}</label> <input id="pr-url" name="external_url" type="url" size="30">
                <label for="pr-lt">{{ __('Kind of site') }}</label> <select id="pr-lt" name="link_type"><option value="">{{ __('None') }}</option>@foreach($linkTypes as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select><br>
                <label for="pr-file">{{ __('A document') }}</label> <input id="pr-file" name="file" type="file"><br>
                <label for="pr-title">{{ __('Title') }}</label> <input id="pr-title" name="title" size="40"> <label for="pr-tags">{{ __('Tags') }}</label> <input id="pr-tags" name="tags" size="25"><br>
                <label for="pr-desc">{{ __('Description') }}</label> <input id="pr-desc" name="description" size="50"><br>
                <label for="pr-notes">{{ __('Notes') }}</label><br><textarea id="pr-notes" name="notes" rows="2" cols="70"></textarea><br><button type="submit">{{ __('Add') }}</button></form>
        </details>
    @endif

    <h2>{{ __('Milestones') }}</h2>
    @forelse($project->milestones as $m)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $m->title }}</strong> · {{ $m->milestoneType->label($lang) }} · {{ $m->status->label($lang) }} · {{ $m->progress_pct }}%
                @if($m->due_date) · {{ __('due :date', ['date' => $m->due_date->format('j M Y')]) }}@endif
                @if($service->isOverdue($m)) · <strong class="error">{{ __('overdue') }}</strong>@elseif($service->isDueSoon($m)) · <strong>{{ __('due soon') }}</strong>@endif
                @if($m->completed_date) · {{ __('completed :date', ['date' => $m->completed_date->format('j M Y')]) }}@if($m->completedBy) ({{ $m->completedBy->name }})@endif @endif</p>
            @if($m->deliverable)<p class="meta">{{ __('Deliverable') }}: {{ $m->deliverable }}</p>@endif
            @if($m->description)<p style="white-space: pre-wrap">{{ $m->description }}</p>@endif
            @if($level >= 2)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.projects.milestones', [$project, $m]) }}">@csrf @include('projects._milestone-fields', ['m' => $m, 'x' => 'pm'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                @if($may($m->created_by_id))<form method="post" action="{{ route('research.projects.part.remove', ['milestones', $m->id]) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</details>@endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<details><summary>{{ __('Add a milestone') }}</summary><form method="post" action="{{ route('research.projects.milestones', $project) }}">@csrf @include('projects._milestone-fields', ['m' => null, 'x' => 'pmn'])<button type="submit">{{ __('Add') }}</button></form></details>@endif

    <h2>{{ __('Team') }}</h2>
    @forelse($project->teamMembers as $t)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p>@if($t->is_lead)<strong>{{ $t->person_name }}</strong>@else{{ $t->person_name }}@endif · {{ $t->role->label($lang) }} · <span class="meta">{{ $t->status->label($lang) }}</span>@if($t->affiliation) · {{ $t->affiliation }}@endif
                @if($t->orcid) · <a href="https://orcid.org/{{ $t->orcid }}" rel="noopener noreferrer">ORCID {{ $t->orcid }}</a>@endif @if($t->start_date) · {{ $t->start_date->format('j M Y') }}@if($t->end_date) - {{ $t->end_date->format('j M Y') }}@endif @endif</p>
            @if($t->contribution_note)<p style="white-space: pre-wrap">{{ $t->contribution_note }}</p>@endif
            @if($level >= 2)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.projects.team', [$project, $t]) }}">@csrf @include('projects._team-fields', ['m' => $t, 'x' => 'pt'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                @if($may($t->created_by_id))<form method="post" action="{{ route('research.projects.part.remove', ['team', $t->id]) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</details>@endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<details><summary>{{ __('Add a team member') }}</summary><form method="post" action="{{ route('research.projects.team', $project) }}">@csrf @include('projects._team-fields', ['m' => null, 'x' => 'ptn'])<button type="submit">{{ __('Add') }}</button></form></details>@endif

    <h2>{{ __('Outputs') }}</h2>
    @forelse($project->outputs as $o)
        @php($url = \App\Research\Projects::outputUrl($o))
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p>@if($url)<a href="{{ $url }}" rel="noopener noreferrer">{{ $o->title }}</a>@else<strong>{{ $o->title }}</strong>@endif · {{ $o->outputType->label($lang) }} · {{ $o->status->label($lang) }}
                @if($o->authors) · {{ $o->authors }}@endif @if($o->venue) · <em>{{ $o->venue }}</em>@endif @if($o->output_date) · {{ $o->output_date->format('j M Y') }}@endif
                @if($o->identifier) · {{ $o->identifierType?->label($lang) }} {{ $o->identifier }}@endif</p>
            @if($o->notes)<p style="white-space: pre-wrap">{{ $o->notes }}</p>@endif
            @if($level >= 2)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.projects.outputs', [$project, $o]) }}">@csrf @include('projects._output-fields', ['o' => $o, 'x' => 'po'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                @if($may($o->created_by_id))<form method="post" action="{{ route('research.projects.part.remove', ['outputs', $o->id]) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</details>@endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<details><summary>{{ __('Add an output') }}</summary><form method="post" action="{{ route('research.projects.outputs', $project) }}">@csrf @include('projects._output-fields', ['o' => null, 'x' => 'pon'])<button type="submit">{{ __('Add') }}</button></form></details>@endif

    <h2>{{ __('Clipboard') }}</h2>
    @forelse($project->clipboard as $c)
        <p>@if($c->is_pinned)<strong>{{ __('Pinned') }}</strong> · @endif<a href="{{ route('records.show', $c->record) }}">{{ \App\Relations\EntityKinds::label($c->record, $lang) }}</a> <span class="meta">{{ $c->record->referenceCode() }} · {{ $c->user->name }}</span>@if($c->notes) · {{ $c->notes }}@endif
            @if($c->user_id === $user->id)<form method="post" action="{{ route('research.projects.clipboard', [$c, 'pin']) }}" style="display: inline">@csrf<button type="submit">{{ $c->is_pinned ? __('Unpin') : __('Pin') }}</button></form>
                <form method="post" action="{{ route('research.projects.clipboard', [$c, 'remove']) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<form method="post" action="{{ route('research.projects.step', [$project, 'clip']) }}">@csrf<label for="pc-iris">{{ __('Records of the catalogue, by their persistent IRIs, one a line') }}</label><br><textarea id="pc-iris" name="iris" rows="2" cols="70" required></textarea><br>
        <label for="pc-notes">{{ __('Notes') }}</label> <input id="pc-notes" name="notes" size="40"> <button type="submit">{{ __('Put on the clipboard') }}</button></form>@endif

    @include('research._discussions', ['home' => $project, 'homeKind' => 'projects'])

    <h2>{{ __('Comments on the evidence') }}</h2>
    <div id="pj-comments">
    @forelse($project->evidenceComments->whereNull('parent_id') as $c)
        <p><span class="meta">{{ $c->author?->name }} · {{ $c->created_at->timezone($tz)->format('j M Y H:i') }}@if($c->collection) · {{ $c->collection->name }}@endif @if($c->is_resolved) · {{ __('resolved') }}@endif</span><br><span style="white-space: pre-wrap">{{ $c->body }}</span>
            @if($talk >= 3 || $c->author_id === $user->id)<form method="post" action="{{ route('research.projects.evidence-comments.resolve', $c) }}" style="display: inline">@csrf<button type="submit">{{ $c->is_resolved ? __('Open again') : __('Resolve') }}</button></form>@endif</p>
        @foreach($project->evidenceComments->where('parent_id', $c->id) as $r)<p style="margin-left: 1.5rem"><span class="meta">{{ $r->author?->name }}</span> {{ $r->body }}</p>@endforeach
    @empty<p>{{ __('None.') }}</p>@endforelse
    </div>
    @if($talk >= 2)
        <form method="post" action="{{ route('research.projects.evidence-comments', $project) }}">@csrf
            @if($collections->isNotEmpty())<label for="pe-coll">{{ __('On the collection') }}</label> <select id="pe-coll" name="collection_id"><option value="">{{ __('The project as a whole') }}</option>@foreach($collections as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select><br>@endif
            <label for="pe-body">{{ __('Comment') }}</label><br><textarea id="pe-body" name="body" rows="2" cols="70" required></textarea><br><button type="submit">{{ __('Comment') }}</button></form>
    @endif

    <h2>{{ __('Activity') }}</h2>
    @forelse($activity as $a)<p class="meta">{{ $a->created_at->timezone($tz)->format('j M Y H:i') }} · {{ $a->user?->name }} · {{ $a->activityType->label($lang) }}@if($a->entity_title): {{ $a->entity_title }}@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse

    @if($level >= 3)
        <h2>{{ __('Settings') }}</h2>
        <form method="post" action="{{ route('research.projects.step', [$project, 'update']) }}">@csrf @include('projects._fields', ['p' => $project])<button type="submit">{{ __('Save') }}</button></form>
        <form method="post" action="{{ route('research.projects.step', [$project, 'share']) }}">@csrf<input type="hidden" name="share" value="{{ $project->share_token_hash ? 0 : 1 }}"><button type="submit">{{ $project->share_token_hash ? __('Stop sharing its summary') : __('Share its summary by a link') }}</button></form>
        @if(app(\App\Research\RicBridge::class)->may($user, $project))
            @php($described = app(\App\Research\RicBridge::class)->of($project))
            <form method="post" action="{{ route('research.analysis.describe', $project) }}">@csrf<label for="rb-agent">{{ __('Performed by (an authority record\'s persistent IRI)') }}</label> <input id="rb-agent" name="performer_iri" size="40">
                <button type="submit">{{ $described ? __('Describe it again in the catalogue') : __('Describe it in the catalogue') }}</button>@if($described) <a href="{{ route('activities.show', $described) }}">{{ __('The activity') }}</a>@endif</form>
        @endif
        @if($level >= 4)<form method="post" action="{{ route('research.projects.step', [$project, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the project') }}</button></form>@endif
    @endif
    <script>
    (function () {
        var since = null, el = document.getElementById('pj-presence'), url = @json(route('research.projects.poll', $project)), here = @json(__('Here now:'));
        function poll() {
            fetch(url + (since ? '?since=' + encodeURIComponent(since) : ''), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
                if (!d) { return; }
                el.textContent = d.presence.length > 1 ? here + ' ' + d.presence.map(function (p) { return p.name; }).join(', ') : '';
                if (since && d.comments.length) { el.textContent += ' · ' + @json(__('New comments; reload to read them.')); }
                since = d.cursor;
            }).catch(function () {});
        }
        poll();
        setInterval(poll, 20000);
    })();
    </script>
@endsection
