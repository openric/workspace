{{-- OpenRiC Workspace - the accession settings: numbering, assignment, what accepting needs, the default priority and checklist, the checklist and appraisal templates and the numbering sequences (Heratio's intake configuration, appraisal templates and numbering, PSIS's accessionIntake config and numbering). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Accession settings'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('accessions.index') }}">{{ __('Accessions') }}</a></p>
    <h1>{{ __('Accession settings') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('accessions.settings.update') }}">
        @csrf
        <h2>{{ __('Numbering') }}</h2>
        <p id="mask-help">{{ __('A number given when none is typed in. Tokens: :tokens. {SEQ:5} pads the sequence to five digits; {REPO} is the repository\'s identifier and {TYPE} the agreement type\'s prefix, such as DOG. Each sequence starts at 1 again each year.', ['tokens' => implode(', ', $tokens)]) }}</p>
        <label for="accession_numbering_mask">{{ __('Accession numbering mask') }}</label>
        <input id="accession_numbering_mask" name="accession_numbering_mask" required maxlength="100" aria-describedby="mask-help" value="{{ old('accession_numbering_mask', $institution->accession_numbering_mask) }}">
        <label for="agreement_numbering_mask">{{ __('Agreement numbering mask') }}</label>
        <input id="agreement_numbering_mask" name="agreement_numbering_mask" required maxlength="100" aria-describedby="mask-help" value="{{ old('agreement_numbering_mask', $institution->agreement_numbering_mask) }}">
        <h2>{{ __('Intake') }}</h2>
        <input type="hidden" name="accession_auto_assign" value="0">
        <label style="font-weight: normal"><input type="checkbox" name="accession_auto_assign" value="1" @checked(old('accession_auto_assign', $institution->accession_auto_assign)) style="width: auto"> {{ __('Assign a new accession to whoever records it') }}</label>
        <input type="hidden" name="accession_requires_agreement" value="0">
        <label style="font-weight: normal"><input type="checkbox" name="accession_requires_agreement" value="1" @checked(old('accession_requires_agreement', $institution->accession_requires_agreement)) style="width: auto"> {{ __('An accession is accepted only once a donor agreement documents it') }}</label>
        @foreach(['accession_requires_appraisal' => 'An accession is accepted only once an appraisal has been completed', 'accession_container_barcodes' => 'Give the containers of accessions barcodes',
            'accession_rights_inheritance' => 'Pass an accession\'s rights to the descriptions made from it'] as $field => $text)
            <input type="hidden" name="{{ $field }}" value="0">
            <label style="font-weight: normal"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $institution->{$field})) style="width: auto"> {{ __($text) }}</label>
        @endforeach
        <label for="default_priority">{{ __('Default priority') }}</label>
        <select id="default_priority" name="default_priority" style="font: inherit; padding: .5rem"><option value="">{{ __('Normal') }}</option>@foreach($priorities as $p)<option value="{{ $p->notation }}" @selected($institution->accession_default_priority_id === $p->id)>{{ $p->label($lang) }}</option>@endforeach</select>
        <label for="checklist_template">{{ __('Default intake checklist template') }}</label>
        <select id="checklist_template" name="checklist_template" style="font: inherit; padding: .5rem"><option value="">{{ __('None') }}</option>@foreach($templates as $t)<option value="{{ $t->id }}" @selected($institution->accession_checklist_template_id === $t->id)>{{ $t->name }}</option>@endforeach</select>
        <button type="submit">{{ __('Save') }}</button>
    </form>

    <section id="templates">
        <h2>{{ __('Checklist templates') }}</h2>
        @foreach($templates as $t)
            <details>
                <summary>{{ $t->name }} ({{ trans_choice(':count item|:count items', count($t->items), ['count' => count($t->items)]) }})</summary>
                <form method="post" action="{{ route('accessions.templates.save', $t->id) }}">
                    @csrf
                    <label for="t-{{ $t->id }}-name">{{ __('Name') }}</label><input id="t-{{ $t->id }}-name" name="name" required maxlength="255" value="{{ $t->name }}">
                    <label for="t-{{ $t->id }}-description">{{ __('Description') }}</label><input id="t-{{ $t->id }}-description" name="description" maxlength="2000" value="{{ $t->description }}">
                    <label for="t-{{ $t->id }}-items">{{ __('Items, one per line') }}</label><textarea id="t-{{ $t->id }}-items" name="items" rows="6" required style="width: 100%; font: inherit">{{ implode("\n", $t->items) }}</textarea>
                    <button type="submit">{{ __('Save') }}</button>
                </form>
                <form method="post" action="{{ route('accessions.templates.destroy', $t->id) }}">@csrf<button type="submit">{{ __('Delete') }}</button></form>
            </details>
        @endforeach
        <details>
            <summary>{{ __('Add a template') }}</summary>
            <form method="post" action="{{ route('accessions.templates.save') }}">
                @csrf
                <label for="nt-name">{{ __('Name') }}</label><input id="nt-name" name="name" required maxlength="255">
                <label for="nt-description">{{ __('Description') }}</label><input id="nt-description" name="description" maxlength="2000">
                <label for="nt-items">{{ __('Items, one per line') }}</label><textarea id="nt-items" name="items" rows="6" required style="width: 100%; font: inherit"></textarea>
                <button type="submit">{{ __('Add') }}</button>
            </form>
        </details>
    </section>

    <section id="appraisal-templates">
        <h2>{{ __('Appraisal templates') }}</h2>
        <p id="criteria-help">{{ __('One criterion per line: its name, its weight and a description, separated by |, such as "Evidential value | 1.0 | Value as evidence of functions and activities".') }}</p>
        @php($criteriaText = fn ($t) => collect($t->criteria)->map(fn ($c) => trim($c['name'].' | '.$c['weight'].' | '.($c['description'] ?? ''), ' |'))->join("\n"))
        @foreach($appraisalTemplates as $t)
            <details>
                <summary>{{ $t->name }}@if($t->is_default) ({{ __('default') }})@endif, {{ trans_choice(':count criterion|:count criteria', count($t->criteria), ['count' => count($t->criteria)]) }}@if($t->sector), {{ $t->sector->label($lang) }}@endif</summary>
                <form method="post" action="{{ route('accessions.appraisal-templates.save', $t->id) }}">
                    @csrf
                    @include('accessions._appraisal-template-fields', ['t' => $t, 'prefix' => 'at-'.$t->id, 'criteria' => $criteriaText($t)])
                    <button type="submit">{{ __('Save') }}</button>
                </form>
                <form method="post" action="{{ route('accessions.appraisal-templates.destroy', $t->id) }}">@csrf<button type="submit">{{ __('Delete') }}</button></form>
            </details>
        @endforeach
        <details>
            <summary>{{ __('Add a template') }}</summary>
            <form method="post" action="{{ route('accessions.appraisal-templates.save') }}">
                @csrf
                @include('accessions._appraisal-template-fields', ['t' => null, 'prefix' => 'at-new', 'criteria' => ''])
                <button type="submit">{{ __('Add') }}</button>
            </form>
        </details>
    </section>

    <h2>{{ __('Numbering sequences') }}</h2>
    @forelse($sequences as $s)
        <p style="margin: .2rem 0">{{ __($s->kind === 'agreement' ? 'Agreements' : 'Accessions') }}@if($s->repository_id) ({{ \App\Models\Repository::find($s->repository_id)?->described('authorised-name', [app()->getLocale(), 'en']) }})@endif:
            {{ __('last :sequence in :year', ['sequence' => $s->last_sequence, 'year' => $s->last_year]) }}</p>
    @empty
        <p>{{ __('No number has been given yet.') }}</p>
    @endforelse
@endsection
