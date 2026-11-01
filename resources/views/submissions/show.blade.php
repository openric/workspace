{{-- OpenRiC Workspace - a submission (PSIS's viewSubmission, addItem, editItem and publish; roadmap phase 19e): its items and files, sending it, its review and its publication. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $submission->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($status = $submission->status->notation)
    <p>@if($own)<a href="{{ route('research.submissions.index') }}">{{ __('My submissions') }}</a>@endif @if($reviewer) · <a href="{{ route('research.submissions.pending') }}">{{ __('Submissions to review') }}</a>@endif</p>
    <h1>{{ $submission->title }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p><strong>{{ $submission->status->label($lang) }}</strong> · {{ $submission->sourceType->label($lang) }} · {{ $submission->user->name }}
        · {{ trans_choice(':count description|:count descriptions', $submission->total_items, ['count' => $submission->total_items]) }} · {{ trans_choice(':count file|:count files', $submission->total_files, ['count' => $submission->total_files]) }} ({{ \Illuminate\Support\Number::fileSize($submission->total_file_size) }})
        @if($submission->submitted_at) · {{ __('sent :date', ['date' => $submission->submitted_at->timezone(config('workspace.timezone'))->format('j M Y')]) }}@endif
        @if($submission->published_at) · {{ __('published :date', ['date' => $submission->published_at->timezone(config('workspace.timezone'))->format('j M Y')]) }}@endif</p>
    @if($submission->description)<p>{{ $submission->description }}</p>@endif
    @if($own)<p>@include('research._project-select', ['thing' => $submission, 'kind' => 'submission'])</p>@endif
    <p>@if($submission->repository){{ __('Repository') }}: {{ \App\Relations\EntityKinds::label($submission->repository, $lang) }}@endif @if($submission->parentRecord) · {{ __('To go under') }}: <a href="{{ route('records.show', $submission->parentRecord) }}">{{ \App\Relations\EntityKinds::label($submission->parentRecord, $lang) }}</a>@endif</p>
    @if($status === 'returned' && $submission->return_comment)<p class="error" role="alert">{{ __('Returned to you') }}: {{ $submission->return_comment }}</p>@endif
    @if($status === 'rejected' && $submission->reject_comment)<p class="error">{{ __('Rejected') }}: {{ $submission->reject_comment }}</p>@endif

    <h2>{{ __('Items') }}</h2>
    @forelse($submission->items as $i)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><span class="meta">{{ $i->itemType->label($lang) }}</span> · <strong>{{ $i->title }}</strong>@if($i->identifier) · {{ $i->identifier }}@endif @if($i->level) · {{ $i->level->anyLabel($lang) }}@endif
                @if($i->parentItem) · {{ __('inside :title', ['title' => $i->parentItem->title]) }}@endif
                @if($i->referenceRecord) · <a href="{{ route('records.show', $i->referenceRecord) }}">{{ \App\Relations\EntityKinds::label($i->referenceRecord, $lang) }}</a>@endif
                @if($i->published_id) · <a href="{{ route(['record_resources' => 'records.show', 'agents' => 'agents.show', 'repositories' => 'repositories.show'][$i->published_table] ?? 'records.show', $i->published_id) }}">{{ __('published') }}</a>@endif</p>
            @if($i->date_display || $i->date_start)<p class="meta">{{ $i->date_display }} @if($i->date_start)({{ $i->date_start->toDateString() }}@if($i->date_end) - {{ $i->date_end->toDateString() }}@endif)@endif</p>@endif
            @if($i->scope_and_content)<p style="white-space: pre-wrap">{{ $i->scope_and_content }}</p>@endif
            @foreach(['extent_and_medium' => 'Extent and medium', 'creators' => 'Creators', 'subjects' => 'Subjects', 'places' => 'Places', 'genres' => 'Genres', 'access_conditions' => 'Conditions governing access', 'reproduction_conditions' => 'Conditions governing reproduction', 'repository_name' => 'Repository name', 'repository_address' => 'Address', 'repository_contact' => 'Contact', 'notes' => 'Notes'] as $field => $label)
                @if($i->{$field})<p class="meta">{{ __($label) }}: {{ $i->{$field} }}</p>@endif
            @endforeach
            @foreach($i->files as $f)<p>@if($f->file)<a href="{{ route('research.submissions.files.show', $f) }}">{{ $f->original_name }}</a> <span class="meta">{{ \Illuminate\Support\Number::fileSize($f->file->size_bytes) }}</span>@else{{ $f->original_name }} <span class="meta">{{ __('named, but not in the exchange file') }}</span>@endif @if($f->caption) · {{ $f->caption }}@endif
                @if($open)<form method="post" action="{{ route('research.submissions.files.remove', $f) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>@endforeach
            @if($open)
                <p><a href="{{ route('research.submissions.show', [$submission, 'item' => $i->id]) }}#sb-item">{{ __('Change') }}</a>
                    <form method="post" action="{{ route('research.submissions.item', [$i, 'remove']) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form></p>
                @if($i->itemType->notation === 'description')
                    <form method="post" action="{{ route('research.submissions.item', [$i, 'file']) }}" enctype="multipart/form-data">@csrf<label for="sf-{{ $loop->index }}">{{ __('Add a file') }}</label> <input id="sf-{{ $loop->index }}" name="file" type="file" required>
                        <label for="sfc-{{ $loop->index }}">{{ __('Caption') }}</label> <input id="sfc-{{ $loop->index }}" name="caption" size="30"> <button type="submit">{{ __('Upload') }}</button></form>
                @endif
            @endif
        </section>
    @empty<p>{{ __('No item yet.') }}</p>@endforelse

    @if($open)
        <h2 id="sb-item">{{ $editing ? __('Change the item') : __('Add an item') }}</h2>
        <form method="post" action="{{ $editing ? route('research.submissions.items.update', [$submission, $editing]) : route('research.submissions.items.store', $submission) }}">@csrf
            @include('submissions._item-fields', ['i' => $editing])
            <button type="submit">{{ __('Save') }}</button> @if($editing)<a href="{{ route('research.submissions.show', $submission) }}">{{ __('Cancel') }}</a>@endif</form>
        <h2>{{ __('Settings') }}</h2>
        <form method="post" action="{{ route('research.submissions.update', $submission) }}">@csrf @include('submissions._fields', ['s' => $submission])<button type="submit">{{ __('Save') }}</button></form>
        <form method="post" action="{{ route('research.submissions.step', [$submission, 'submit']) }}">@csrf<button type="submit">{{ $status === 'returned' ? __('Send it again for review') : __('Send it for review') }}</button></form>
    @endif
    @if($own && ! in_array($status, ['approved', 'published'], true))<form method="post" action="{{ route('research.submissions.step', [$submission, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the submission') }}</button></form>@endif

    @if($reviewer)
        <h2>{{ __('Review') }}</h2>
        <form method="post" action="{{ route('research.submissions.review', [$submission, 'comment']) }}">@csrf
            <label for="rv-comment">{{ __('Comment, or why it is returned or rejected') }}</label><br><textarea id="rv-comment" name="comment" rows="3" cols="80">{{ old('comment') }}</textarea><br>
            <button type="submit">{{ __('Comment') }}</button>
            @if($status === 'submitted')<button type="submit" formaction="{{ route('research.submissions.review', [$submission, 'take']) }}">{{ __('Take it on') }}</button>@endif
            @if(in_array($status, ['submitted', 'under-review'], true))<button type="submit" formaction="{{ route('research.submissions.review', [$submission, 'approve']) }}">{{ __('Approve') }}</button>@endif
            @if(in_array($status, ['submitted', 'under-review', 'approved'], true))<button type="submit" formaction="{{ route('research.submissions.review', [$submission, 'return']) }}">{{ __('Return to the researcher') }}</button>
                <button type="submit" formaction="{{ route('research.submissions.review', [$submission, 'reject']) }}">{{ __('Reject') }}</button>@endif
        </form>
        @if($status === 'approved')
            <h2>{{ __('Publish') }}</h2>
            <form method="post" action="{{ route('research.submissions.publish', $submission) }}">@csrf
                <label for="pb-repo">{{ __('Repository') }}</label> <select id="pb-repo" name="repository_id"><option value="">{{ __('As the submission says') }}</option>@foreach($repositories as $r)<option value="{{ $r->id }}">{{ \App\Relations\EntityKinds::label($r, $lang) }}</option>@endforeach</select>
                <label for="pb-lang">{{ __('Language it is written in') }}</label> <input id="pb-lang" name="language" size="6" value="{{ old('language', 'en') }}"><br>
                <label><input type="checkbox" name="publish" value="1" checked> {{ __('Publish the new descriptions at once') }}</label>
                <label><input type="checkbox" name="create_terms" value="1" checked> {{ __('Make subjects, places and genres the Workspace does not have') }}</label><br>
                <button type="submit" name="dry_run" value="1">{{ __('Dry run') }}</button> <button type="submit">{{ __('Publish') }}</button></form>
        @endif
        @if($submission->import)
            @php($a = $submission->import->report['actions'] ?? ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0])
            <p>{{ $submission->import->committed_at ? __('The import: :created created, :updated updated, :unchanged unchanged, :rejected not imported.', $a) : __('The dry run: :created would be created, :updated would be updated, :unchanged unchanged, :rejected not imported.', $a) }}
                <a href="{{ route('imports.show', $submission->import) }}">{{ __('The full report') }}</a></p>
        @endif
    @endif

    <h2>{{ __('History') }}</h2>
    @forelse($submission->reviews as $r)<p><span class="meta">{{ $r->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}</span> · {{ $r->reviewer?->name }} · {{ $r->action->label($lang) }}@if($r->comment): {{ $r->comment }}@endif</p>
    @empty<p>{{ __('Not sent yet.') }}</p>@endforelse
    @if($submission->sourceFile)<p class="meta">{{ __('Imported from :file', ['file' => $submission->sourceFile->original_name]) }}</p>@endif
@endsection
