{{-- OpenRiC Workspace - an accession's intake: its status and the steps it may take, assignment and priority, the checklist, attachments and timeline (PSIS's accessionIntake queue detail, checklist, attachments and timeline). Needs $accession, $intake (blockers, progress, transitions, accounts, templates, categories, priorities), $canUpdate. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($label = fn ($c) => $c?->label($lang))
@php($when = fn ($t) => $t?->setTimezone(config('workspace.timezone'))->format('Y-m-d H:i'))
<section id="intake">
    <h2>{{ __('Intake') }}</h2>
    <p><strong>{{ __('Intake status') }}:</strong> {{ $label($accession->intakeStatus) }}
        <br><strong>{{ __('Priority') }}:</strong> {{ $label($accession->intakePriority) }}
        <br><strong>{{ __('Assigned to') }}:</strong> {{ $accession->assignedTo?->name ?? __('nobody') }}
        @foreach(['submitted_at' => 'Submitted', 'accepted_at' => 'Accepted', 'rejected_at' => 'Rejected'] as $column => $what)@if($accession->{$column})<br><strong>{{ __($what) }}:</strong> {{ $when($accession->{$column}) }}@endif @endforeach
        @if($accession->rejection_reason)<br><strong>{{ __('Rejection reason') }}:</strong> {{ $accession->rejection_reason }}@endif
        @if($accession->intake_notes)<br><strong>{{ __('Intake notes') }}:</strong> {{ $accession->intake_notes }}@endif
        <br><strong>{{ __('Checklist') }}:</strong> {{ __(':completed of :total done (:percent%)', $intake['progress']) }}</p>
    @if($intake['blockers'] && in_array('accepted', $intake['transitions'], true))<p class="error" role="status">{{ __('Before it can be accepted: :blockers.', ['blockers' => implode('; ', $intake['blockers'])]) }}</p>@endif
    @if($canUpdate)
        <div style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
            @foreach($intake['transitions'] as $to)
                <form method="post" action="{{ route('accessions.intake', $accession) }}" style="display: flex; gap: .4rem; align-items: end">
                    @csrf
                    <input type="hidden" name="to" value="{{ $to }}">
                    @if(in_array($to, ['rejected', 'returned'], true))
                        <div><label for="in-{{ $to }}" style="margin-top: 0">{{ __($to === 'rejected' ? 'Reason' : 'What to revise') }}</label><input id="in-{{ $to }}" name="text" maxlength="2000" @required($to === 'rejected')></div>
                    @endif
                    <button type="submit" style="margin-top: 0">{{ __(['submitted' => 'Submit for review', 'under-review' => 'Start review', 'accepted' => 'Accept (finalise)', 'rejected' => 'Reject', 'returned' => 'Return for revision'][$to]) }}</button>
                </form>
            @endforeach
        </div>
        <form method="post" action="{{ route('accessions.assign', $accession) }}" style="display: flex; gap: .5rem; flex-wrap: wrap; align-items: end; margin-top: .75rem">
            @csrf
            <div><label for="in-assignee" style="margin-top: 0">{{ __('Assigned to') }}</label>
                <select id="in-assignee" name="assignee" style="font: inherit; padding: .5rem"><option value="">{{ __('Nobody') }}</option>@foreach($intake['accounts'] as $a)<option value="{{ $a->id }}" @selected($accession->assigned_to_id === $a->id)>{{ $a->name }}</option>@endforeach</select></div>
            <div><label for="in-priority" style="margin-top: 0">{{ __('Priority') }}</label>
                <select id="in-priority" name="priority" style="font: inherit; padding: .5rem">@foreach($intake['priorities'] as $p)<option value="{{ $p->notation }}" @selected($accession->intake_priority_id === $p->id)>{{ $p->label($lang) }}</option>@endforeach</select></div>
            <button type="submit" style="margin-top: 0">{{ __('Save') }}</button>
        </form>
    @endif
</section>

<section id="checklist">
    <h2>{{ __('Intake checklist') }}</h2>
    @forelse($accession->checklist as $item)
        <p style="margin: .2rem 0">
            @if($canUpdate)<form method="post" action="{{ route('accessions.checklist', $accession) }}" style="display: inline">@csrf<input type="hidden" name="action" value="toggle"><input type="hidden" name="item" value="{{ $item->id }}"><button type="submit" aria-label="{{ $item->is_completed ? __('Untick :label', ['label' => $item->label]) : __('Tick :label', ['label' => $item->label]) }}" style="margin-top: 0; padding: .1rem .5rem">{{ $item->is_completed ? '[x]' : '[ ]' }}</button></form>@else{{ $item->is_completed ? '[x]' : '[ ]' }}@endif
            {{ $item->label }}@if($item->is_completed) <small>({{ collect([$item->completedBy?->name, $when($item->completed_at)])->filter()->join(', ') }})</small>@endif
            @if($canUpdate)<form method="post" action="{{ route('accessions.checklist', $accession) }}" style="display: inline">@csrf<input type="hidden" name="action" value="remove"><input type="hidden" name="item" value="{{ $item->id }}"><button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
    @empty
        <p>{{ __('No checklist.') }}</p>
    @endforelse
    @if($canUpdate)
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: end; margin-top: .5rem">
            <form method="post" action="{{ route('accessions.checklist', $accession) }}" style="display: flex; gap: .4rem; align-items: end">
                @csrf<input type="hidden" name="action" value="add">
                <div><label for="ck-label" style="margin-top: 0">{{ __('Add an item') }}</label><input id="ck-label" name="label" required maxlength="500"></div>
                <button type="submit" style="margin-top: 0">{{ __('Add') }}</button>
            </form>
            @if($intake['templates']->isNotEmpty())
                <form method="post" action="{{ route('accessions.checklist', $accession) }}" style="display: flex; gap: .4rem; align-items: end">
                    @csrf<input type="hidden" name="action" value="template">
                    <div><label for="ck-template" style="margin-top: 0">{{ __('Replace with a template') }}</label>
                        <select id="ck-template" name="template" style="font: inherit; padding: .5rem">@foreach($intake['templates'] as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
                    <button type="submit" style="margin-top: 0">{{ __('Apply') }}</button>
                </form>
            @endif
        </div>
    @endif
</section>

<section id="attachments">
    <h2>{{ __('Attachments') }}</h2>
    @forelse($accession->attachments as $a)
        <p style="margin: .2rem 0"><a href="{{ route('accessions.attachments.show', [$accession, $a->id]) }}">{{ $a->original_name }}</a>
            ({{ collect([$label($a->category), number_format($a->size_bytes / 1024, 1).' KB', $a->uploadedBy ? __('uploaded by :name, :date', ['name' => $a->uploadedBy->name, 'date' => $a->created_at->format('Y-m-d')]) : null])->filter()->join(', ') }})
            @if($canUpdate)<form method="post" action="{{ route('accessions.attachments.destroy', [$accession, $a->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
        @if($a->description)<p style="margin: 0 0 .4rem; white-space: pre-line">{{ $a->description }}</p>@endif
    @empty
        <p>{{ __('None yet.') }}</p>
    @endforelse
    @if($canUpdate)
        <details>
            <summary>{{ __('Upload an attachment') }}</summary>
            <form method="post" action="{{ route('accessions.attachments.store', $accession) }}" enctype="multipart/form-data">
                @csrf
                <label for="at-file">{{ __('File') }}</label><input id="at-file" type="file" name="file" required>
                <label for="at-category">{{ __('Category') }}</label>
                <select id="at-category" name="category" style="font: inherit; padding: .5rem">@foreach($intake['categories'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                <label for="at-description">{{ __('Description') }}</label><textarea id="at-description" name="description" rows="2" style="width: 100%; font: inherit"></textarea>
                <button type="submit">{{ __('Upload') }}</button>
            </form>
        </details>
    @endif
</section>

<section id="timeline">
    <h2>{{ __('Timeline') }}</h2>
    <p class="meta">{{ __('The accession\'s chain of custody: each step, assignment, attachment and deaccession, the latest first.') }}</p>
    @forelse($accession->timeline as $event)
        <p style="margin: .2rem 0"><strong>{{ $when($event->occurred_at) }}</strong> {{ $label($event->eventType) }}{{ $event->actor ? ', '.$event->actor->name : '' }}{{ $event->description ? ': '.$event->description : '' }}</p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    @if($canUpdate)
        <form method="post" action="{{ route('accessions.timeline', $accession) }}" style="display: flex; gap: .4rem; align-items: end; margin-top: .5rem">
            @csrf
            <div style="flex: 1"><label for="tl-note" style="margin-top: 0">{{ __('Add a note') }}</label><input id="tl-note" name="note" required maxlength="2000"></div>
            <button type="submit" style="margin-top: 0">{{ __('Add') }}</button>
        </form>
    @endif
</section>
