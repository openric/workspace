{{-- OpenRiC Workspace - one donor agreement: its details, parties, dates and terms, its donor, the accessions it documents and the units it covers, its rights, restrictions, reminders and documents (Heratio's agreement view and edit). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $agreement->heading([app()->getLocale(), 'en']))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($label = fn ($c) => $c?->label($lang))
    @php($day = fn ($d) => $d?->format('Y-m-d'))
    <p><a href="{{ route('agreements.index') }}">{{ __('Donor agreements') }}</a></p>
    <h1>{{ $agreement->heading([app()->getLocale(), 'en']) }}</h1>
    <p>{{ __('Donor agreement') }} @include('description._ric', ['term' => 'rico:Record'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $agreement->iri }}</code>
        <br><strong>{{ __('Date created') }}:</strong> {{ $agreement->created_at->format('Y-m-d') }}. <strong>{{ __('Date modified') }}:</strong> {{ $agreement->updated_at->format('Y-m-d') }}.</p>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: end; margin: 1rem 0">
        @if($canUpdate)<a href="{{ route('agreements.edit', [$agreement, 'language' => $agreement->descriptionValues->first()?->language ?? 'en']) }}" style="display: inline-block; padding: .5rem 1rem; background: var(--accent); color: var(--bg); text-decoration: none">{{ __('Edit agreement') }}</a>
            <a href="{{ route('history', ['agreements', $agreement->id]) }}" style="display: inline-block; padding: .5rem 1rem; border: 1px solid var(--line); text-decoration: none">{{ __('History') }}</a>@endif
        @if(auth()->user()->may('delete', 'agreement', $agreement))<a href="{{ route('deletion.confirm', ['agreements', $agreement->id]) }}" style="display: inline-block; padding: .5rem 1rem; border: 1px solid var(--error); color: var(--error); text-decoration: none">{{ __('Delete') }}</a>@endif
    </div>

    <h2>{{ __('Details') }}</h2>
    <p><strong>{{ __('Agreement number') }}:</strong> {{ $agreement->number() }}
        <br><strong>{{ __('Agreement type') }}:</strong> {{ $label($agreement->type) }} @include('description._ric', ['term' => 'rico:hasDocumentaryFormType'])
        <br><strong>{{ __('Status') }}:</strong> {{ $label($agreement->status) }}
        <br><strong>{{ __('Repository') }}:</strong> @if($agreement->repository)<a href="{{ route('repositories.show', $agreement->repository) }}">{{ $agreement->repository->described('authorised-name', [app()->getLocale(), 'en']) }}</a>@else{{ __('not set') }}@endif
        @if($agreement->parent)<br><strong>{{ __('Part of') }}:</strong> <a href="{{ route('agreements.show', $agreement->parent) }}">{{ $agreement->parent->heading([app()->getLocale(), 'en']) }}</a>@endif
        @if($agreement->supersedes)<br><strong>{{ __('Supersedes') }}:</strong> <a href="{{ route('agreements.show', $agreement->supersedes) }}">{{ $agreement->supersedes->heading([app()->getLocale(), 'en']) }}</a>@endif
        @if($agreement->is_template)<br><strong>{{ __('Template') }}:</strong> {{ __('Yes') }}@endif</p>

    <h2>{{ __('Dates') }}</h2>
    <p>@foreach(['agreed_on' => 'Agreement date', 'effective_on' => 'Effective date', 'expires_on' => 'Expiry date', 'review_on' => 'Review date', 'terminated_on' => 'Termination date'] as $column => $what)
        <strong>{{ __($what) }}:</strong> {{ $day($agreement->{$column}) ?? __('not set') }}@unless($loop->last)<br>@endunless
    @endforeach</p>

    <h2>{{ __('Parties') }}</h2>
    <section id="donor">
        @include('description._relations', ['subject' => $agreement, 'kind' => 'agreements', 'relations' => $relations, 'canUpdate' => $canUpdate,
            'heading' => __('Donor'), 'types' => ['creation'], 'section' => 'dn', 'add' => 'creation|source', 'addLabel' => 'Add the donor, in the donor role'])
        @foreach($donors as $donor)
            @php($contact = $donor->contactPoints()->orderByDesc('is_primary')->first())
            <p style="margin: .2rem 0">{{ $donor->described('agent-authorised-name', [app()->getLocale(), 'en']) }}: {{ __('Primary contact information') }}: @if($contact){{ collect([$contact->contact_person, $contact->street_address, $contact->city, $contact->region, $contact->postal_code, $contact->country_code, $contact->telephone, $contact->email])->filter()->join(', ') }}@else{{ __('none') }}@endif
                (<a href="{{ route('agents.show', $donor) }}">{{ __('Related donor record') }}</a>)</p>
        @endforeach
    </section>
    <p>@foreach(['legal_representative' => 'Legal representative', 'legal_representative_title' => 'Legal representative title', 'repository_representative' => 'Repository representative', 'repository_representative_title' => 'Repository representative title'] as $column => $what)
        <strong>{{ __($what) }}:</strong> {{ $agreement->{$column} ?? __('not set') }}@unless($loop->last)<br>@endunless
    @endforeach</p>

    <h2>{{ __('Signatures') }}</h2>
    <p><strong>{{ __('Donor signature') }}:</strong> {{ collect([$agreement->donor_signature_name, $day($agreement->donor_signed_on)])->filter()->join(', ') ?: __('not signed') }}
        <br><strong>{{ __('Repository signature') }}:</strong> {{ collect([$agreement->repository_signature_name, $day($agreement->repository_signed_on)])->filter()->join(', ') ?: __('not signed') }}
        <br><strong>{{ __('Witness') }}:</strong> {{ collect([$agreement->witness_name, $day($agreement->witnessed_on)])->filter()->join(', ') ?: __('none') }}</p>

    <h2>{{ __('Transfer') }}</h2>
    <p><strong>{{ __('Transfer method') }}:</strong> {{ $agreement->transfer_method ?? __('not set') }}
        <br><strong>{{ __('Transfer date') }}:</strong> {{ $day($agreement->transferred_on) ?? __('not set') }}
        <br><strong>{{ __('Received by') }}:</strong> {{ $agreement->received_by ?? __('not set') }}</p>

    <h2>{{ __('Financial terms') }}</h2>
    <p>@if($agreement->has_financial_terms)<strong>{{ __('Purchase amount') }}:</strong> {{ $agreement->purchase_amount === null ? __('not set') : $agreement->currency.' '.number_format((float) $agreement->purchase_amount, 2, '.', ' ') }}@else{{ __('None.') }}@endif</p>

    @include('description._view', ['subject' => $agreement, 'standard' => \App\Description\Elements::AGREEMENT, 'drafts' => true])

    <section id="accessions">
        @include('description._relations', ['subject' => $agreement, 'kind' => 'agreements', 'relations' => $relations, 'canUpdate' => $canUpdate,
            'heading' => __('Accessions'), 'types' => ['documentation'], 'section' => 'ac', 'add' => 'documentation|source', 'addLabel' => 'Link an accession it documents'])
    </section>
    <section id="records">
        @include('description._relations', ['subject' => $agreement, 'kind' => 'agreements', 'relations' => $relations, 'canUpdate' => $canUpdate,
            'heading' => __('Linked records'), 'types' => ['covers', 'partly-covers', 'refers-to'], 'section' => 'lr', 'add' => 'covers|source', 'addLabel' => 'Link a unit of description it covers or refers to'])
    </section>

    <section id="rights">
        <h2>{{ __('Rights') }}</h2>
        @forelse($agreement->rights as $r)
            <p style="margin: .2rem 0"><strong>{{ $label($r->right) }}</strong>: {{ $label($r->permission) }}
                ({{ collect(['applies_to_digital' => __('digital'), 'applies_to_physical' => __('physical'), 'applies_to_metadata' => __('metadata')])->filter(fn ($w, $c) => $r->{$c})->join(', ') ?: __('nothing') }})@if($r->starts_on || $r->ends_on), {{ $day($r->starts_on) ?? '?' }} {{ __('to') }} {{ $day($r->ends_on) ?? '?' }}@endif
                @if($r->requires_donor_approval). {{ __('Needs the donor\'s approval') }}@endif @if($r->requires_fee). {{ __('A fee is charged') }}@endif
                @if($canUpdate)<form method="post" action="{{ route('agreements.rows.destroy', [$agreement, 'rights', $r->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
            @foreach(['Conditions' => $r->conditions, 'Fee structure' => $r->fee_structure, 'Note' => $r->note] as $what => $text)@if($text)<p style="margin: 0 0 .4rem; white-space: pre-line"><em>{{ __($what) }}:</em> {{ $text }}</p>@endif @endforeach
        @empty
            <p>{{ __('None yet.') }}</p>
        @endforelse
        @if($canUpdate)
            <details>
                <summary>{{ __('Add a right') }}</summary>
                <form method="post" action="{{ route('agreements.rows.store', [$agreement, 'rights']) }}">
                    @csrf
                    @foreach(['right' => 'Right', 'permission' => 'Permission'] as $field => $what)
                        <label for="r-{{ $field }}">{{ __($what) }}</label>
                        <select id="r-{{ $field }}" name="row[{{ $field }}]" style="font: inherit; padding: .5rem">@foreach($choices[$field] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                    @endforeach
                    @foreach(['applies_to_digital' => 'Applies to digital', 'applies_to_physical' => 'Applies to physical', 'applies_to_metadata' => 'Applies to metadata', 'requires_donor_approval' => 'Requires donor approval', 'requires_fee' => 'Requires a fee'] as $field => $what)
                        <label style="font-weight: normal"><input type="checkbox" name="row[{{ $field }}]" value="1" @checked(str_starts_with($field, 'applies')) style="width: auto"> {{ __($what) }}</label>
                    @endforeach
                    <label for="r-starts">{{ __('Start date') }}</label><input id="r-starts" name="row[starts_on]" placeholder="YYYY-MM-DD" maxlength="10">
                    <label for="r-ends">{{ __('End date') }}</label><input id="r-ends" name="row[ends_on]" placeholder="YYYY-MM-DD" maxlength="10">
                    @foreach(['conditions' => 'Conditions', 'fee_structure' => 'Fee structure', 'note' => 'Note'] as $field => $what)
                        <label for="r-{{ $field }}">{{ __($what) }}</label><textarea id="r-{{ $field }}" name="row[{{ $field }}]" rows="2" style="width: 100%; font: inherit"></textarea>
                    @endforeach
                    <button type="submit">{{ __('Add') }}</button>
                </form>
            </details>
        @endif
    </section>

    <section id="restrictions">
        <h2>{{ __('Restrictions') }}</h2>
        @forelse($agreement->restrictions as $r)
            <p style="margin: .2rem 0"><strong>{{ $label($r->restrictionType) }}</strong>@unless($r->is_active) ({{ __('not in force') }})@endunless:
                {{ $r->applies_to_all ? __('all of the material') : __('some of the material') }}@if($r->starts_on || $r->ends_on), {{ $day($r->starts_on) ?? '?' }} {{ __('to') }} {{ $day($r->ends_on) ?? '?' }}@endif
                @if($r->auto_release). {{ __('Released automatically on :date', ['date' => $day($r->release_on) ?? '?']) }}@endif
                @if($r->popiaCategory). POPIA: {{ $label($r->popiaCategory) }}@if($r->data_subject_consent !== null), {{ $r->data_subject_consent ? __('the data subject consented') : __('the data subject did not consent') }}@endif @endif
                @if($r->security_clearance_level !== null). {{ __('Security clearance level :level', ['level' => $r->security_clearance_level]) }}@endif
                @if($r->can_be_overridden). {{ __('May be overridden by :authority', ['authority' => $r->override_authority ?? '?']) }}@endif
                @if($canUpdate)<form method="post" action="{{ route('agreements.rows.destroy', [$agreement, 'restrictions', $r->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
            @foreach(['Specific materials' => $r->specific_materials, 'Box list' => $r->box_list, 'Release trigger' => $r->release_trigger, 'Reason' => $r->reason, 'Legal basis' => $r->legal_basis, 'Note' => $r->note] as $what => $text)@if($text)<p style="margin: 0 0 .4rem; white-space: pre-line"><em>{{ __($what) }}:</em> {{ $text }}</p>@endif @endforeach
        @empty
            <p>{{ __('None yet.') }}</p>
        @endforelse
        @if($canUpdate)
            <details>
                <summary>{{ __('Add a restriction') }}</summary>
                <form method="post" action="{{ route('agreements.rows.store', [$agreement, 'restrictions']) }}">
                    @csrf
                    <label for="x-type">{{ __('Restriction type') }}</label>
                    <select id="x-type" name="row[restriction_type]" style="font: inherit; padding: .5rem">@foreach($choices['restriction_type'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                    @foreach(['applies_to_all' => 'Applies to all of the material', 'auto_release' => 'Released automatically', 'can_be_overridden' => 'May be overridden', 'is_active' => 'In force'] as $field => $what)
                        <label style="font-weight: normal"><input type="checkbox" name="row[{{ $field }}]" value="1" @checked(in_array($field, ['applies_to_all', 'is_active'], true)) style="width: auto"> {{ __($what) }}</label>
                    @endforeach
                    @foreach(['starts_on' => 'Start date', 'ends_on' => 'End date', 'release_on' => 'Release date'] as $field => $what)
                        <label for="x-{{ $field }}">{{ __($what) }}</label><input id="x-{{ $field }}" name="row[{{ $field }}]" placeholder="YYYY-MM-DD" maxlength="10">
                    @endforeach
                    <label for="x-popia">{{ __('POPIA category') }}</label>
                    <select id="x-popia" name="row[popia_category]" style="font: inherit; padding: .5rem"><option value="">{{ __('Not set') }}</option>@foreach($choices['popia_category'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                    <label for="x-consent">{{ __('Data subject consent') }}</label>
                    <select id="x-consent" name="row[data_subject_consent]" style="font: inherit; padding: .5rem"><option value="">{{ __('Not known') }}</option><option value="1">{{ __('Yes') }}</option><option value="0">{{ __('No') }}</option></select>
                    <label for="x-level">{{ __('Security clearance level') }}</label><input id="x-level" name="row[security_clearance_level]" inputmode="numeric" maxlength="1">
                    <label for="x-authority">{{ __('Override authority') }}</label><input id="x-authority" name="row[override_authority]" maxlength="500">
                    <label for="x-trigger">{{ __('Release trigger') }}</label><input id="x-trigger" name="row[release_trigger]" maxlength="500">
                    @foreach(['specific_materials' => 'Specific materials', 'box_list' => 'Box list', 'reason' => 'Reason', 'legal_basis' => 'Legal basis', 'note' => 'Note'] as $field => $what)
                        <label for="x-{{ $field }}">{{ __($what) }}</label><textarea id="x-{{ $field }}" name="row[{{ $field }}]" rows="2" style="width: 100%; font: inherit"></textarea>
                    @endforeach
                    <button type="submit">{{ __('Add') }}</button>
                </form>
            </details>
        @endif
    </section>

    <section id="reminders">
        <h2>{{ __('Reminders') }}</h2>
        @forelse($agreement->reminders as $r)
            <p style="margin: .2rem 0"><strong>{{ $day($r->remind_on) }}</strong> {{ $r->subject }}: {{ collect([$label($r->type), $label($r->priority), $label($r->status),
                $r->snoozed_until ? __('until :date', ['date' => $day($r->snoozed_until)]) : null, $r->recurrence ? $label($r->recurrence) : null,
                __(':days days ahead', ['days' => $r->advance_days]), $r->notify_email ? __('by email to :to', ['to' => ($r->recipients->isNotEmpty() ? $r->recipients : collect([$r->createdBy])->filter())->pluck('name')->join(', ')]) : __('not emailed'),
                $r->sent_at ? __('last sent :date', ['date' => $r->sent_at->format('Y-m-d')]) : null])->filter()->join(', ') }}
                @if($canUpdate)<form method="post" action="{{ route('agreements.rows.destroy', [$agreement, 'reminders', $r->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
            @foreach(['Description' => $r->description, 'Action required' => $r->action_required] as $what => $text)@if($text)<p style="margin: 0 0 .4rem; white-space: pre-line"><em>{{ __($what) }}:</em> {{ $text }}</p>@endif @endforeach
            @if($r->completed_at)<p style="margin: 0 0 .4rem"><em>{{ __('Completed') }}:</em> {{ $r->completed_at->format('Y-m-d') }}@if($r->completedBy), {{ $r->completedBy->name }}@endif @if($r->completion_note). {{ $r->completion_note }}@endif</p>@endif
            @if($canUpdate)@include('agreements._reminder-actions', ['reminder' => $r, 'back' => null])@endif
        @empty
            <p>{{ __('None yet.') }}</p>
        @endforelse
        @if($canUpdate)
            <details>
                <summary>{{ __('Add a reminder') }}</summary>
                <form method="post" action="{{ route('agreements.reminders.store', $agreement) }}">
                    @csrf
                    <label for="m-subject">{{ __('Subject') }}</label><input id="m-subject" name="row[subject]" required maxlength="255">
                    @foreach(['type' => ['Reminder type', 'reminder_type'], 'priority' => ['Priority', 'priority']] as $field => [$what, $list])
                        <label for="m-{{ $field }}">{{ __($what) }}</label>
                        <select id="m-{{ $field }}" name="row[{{ $field }}]" style="font: inherit; padding: .5rem">@foreach($choices[$list] as $c)<option value="{{ $c->notation }}" @selected($c->notation === 'normal')>{{ $c->label($lang) }}</option>@endforeach</select>
                    @endforeach
                    <label for="m-date">{{ __('Reminder date') }}</label><input id="m-date" name="row[remind_on]" required placeholder="YYYY-MM-DD" maxlength="10">
                    <label for="m-advance">{{ __('Days ahead to send it') }}</label><input id="m-advance" name="row[advance_days]" value="30" inputmode="numeric" maxlength="3">
                    <label for="m-recurrence">{{ __('Recurrence') }}</label>
                    <select id="m-recurrence" name="row[recurrence]" style="font: inherit; padding: .5rem"><option value="">{{ __('Once') }}</option>@foreach($choices['recurrence'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                    <label for="m-ends">{{ __('Recurrence ends') }}</label><input id="m-ends" name="row[recurrence_ends_on]" placeholder="YYYY-MM-DD" maxlength="10">
                    <label style="font-weight: normal"><input type="checkbox" name="row[notify_email]" value="1" checked style="width: auto"> {{ __('Send by email') }}</label>
                    <label for="m-recipients">{{ __('Send to') }}</label>
                    <small id="m-recipients-help">{{ __('Accounts that may read agreements; with none chosen, it goes to you.') }}</small>
                    <select id="m-recipients" name="row[recipients][]" multiple aria-describedby="m-recipients-help" style="font: inherit; padding: .5rem">@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                    @foreach(['description' => 'Description', 'action_required' => 'Action required'] as $field => $what)
                        <label for="m-{{ $field }}">{{ __($what) }}</label><textarea id="m-{{ $field }}" name="row[{{ $field }}]" rows="2" style="width: 100%; font: inherit"></textarea>
                    @endforeach
                    <button type="submit">{{ __('Add') }}</button>
                </form>
            </details>
        @endif
    </section>

    <section id="documents">
        <h2>{{ __('Documents') }}</h2>
        @forelse($agreement->documents as $d)
            <p style="margin: .2rem 0">
                @if(! $d->is_confidential || $canUpdate)<a href="{{ route('agreements.documents.show', [$agreement, $d->id]) }}">{{ $d->title ?? $d->original_name }}</a>@else{{ $d->title ?? $d->original_name }}@endif
                ({{ collect([$label($d->documentType), $day($d->document_date), $d->version ? __('version :version', ['version' => $d->version]) : null,
                    $d->is_signed ? __('signed :date by :by', ['date' => $day($d->signed_on) ?? '?', 'by' => $d->signed_by ?? '?']) : null,
                    $d->is_confidential ? __('confidential') : null, number_format($d->size_bytes / 1024, 1).' KB'])->filter()->join(', ') }})
                @if($canUpdate)<form method="post" action="{{ route('agreements.documents.destroy', [$agreement, $d->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
            @foreach(['Description' => $d->description, 'Access restriction' => $d->access_restriction] as $what => $text)@if($text)<p style="margin: 0 0 .4rem; white-space: pre-line"><em>{{ __($what) }}:</em> {{ $text }}</p>@endif @endforeach
            <p class="meta" style="margin: 0 0 .4rem">SHA-256 <code>{{ $d->sha256 }}</code>@if($d->uploadedBy), {{ __('uploaded by :name, :date', ['name' => $d->uploadedBy->name, 'date' => $d->created_at->format('Y-m-d')]) }}@endif</p>
        @empty
            <p>{{ __('None yet.') }}</p>
        @endforelse
        @if($canUpdate)
            <details>
                <summary>{{ __('Upload a document') }}</summary>
                <form method="post" action="{{ route('agreements.documents.store', $agreement) }}" enctype="multipart/form-data">
                    @csrf
                    <label for="f-file">{{ __('File') }}</label><input id="f-file" type="file" name="file" required>
                    <label for="f-type">{{ __('Document type') }}</label>
                    <select id="f-type" name="document_type" style="font: inherit; padding: .5rem">@foreach($choices['document_type'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                    <label for="f-title">{{ __('Title') }}</label><input id="f-title" name="title" maxlength="255">
                    <label for="f-date">{{ __('Document date') }}</label><input id="f-date" name="document_date" placeholder="YYYY-MM-DD" maxlength="10">
                    <label for="f-version">{{ __('Version') }}</label><input id="f-version" name="version" maxlength="50">
                    <label style="font-weight: normal"><input type="checkbox" name="is_signed" value="1" style="width: auto"> {{ __('Signed') }}</label>
                    <label for="f-signed">{{ __('Signature date') }}</label><input id="f-signed" name="signed_on" placeholder="YYYY-MM-DD" maxlength="10">
                    <label for="f-by">{{ __('Signed by') }}</label><input id="f-by" name="signed_by" maxlength="500">
                    <label style="font-weight: normal"><input type="checkbox" name="is_confidential" value="1" style="width: auto"> {{ __('Confidential: only those who may change the agreement open it') }}</label>
                    <label for="f-sha">{{ __('SHA-256 you expect (optional)') }}</label><input id="f-sha" name="sha256" maxlength="64">
                    @foreach(['description' => 'Description', 'access_restriction' => 'Access restriction'] as $field => $what)
                        <label for="f-{{ $field }}">{{ __($what) }}</label><textarea id="f-{{ $field }}" name="{{ $field }}" rows="2" style="width: 100%; font: inherit"></textarea>
                    @endforeach
                    <button type="submit">{{ __('Upload') }}</button>
                </form>
            </details>
        @endif
    </section>

    @include('description._identifiers', ['subject' => $agreement, 'kind' => 'agreements', 'canUpdate' => $canUpdate])
    <p class="meta">{{ __('Every change to the agreement is kept in its history, as Heratio\'s agreement history.') }}</p>
@endsection
