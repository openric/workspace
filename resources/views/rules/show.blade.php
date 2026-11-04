{{-- OpenRiC Workspace - one rule or mandate, with whom it gives authority to and what it regulates (roadmap phase 23b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $rule->described('rule-name', [app()->getLocale(), 'en']))
@section('content')
    <p><a href="{{ route('rules.index') }}">{{ __('Rules and mandates') }}</a></p>
    <h1>{{ $rule->described('rule-name', [app()->getLocale(), 'en']) }}</h1>
    <p>{{ __($rule->isMandate() ? 'Mandate' : 'Rule') }} @include('description._ric', ['term' => $rule->isMandate() ? 'rico:Mandate' : 'rico:Rule'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $rule->iri }}</code>
        <br><strong>{{ __('Status') }}:</strong> {{ $rule->status?->label(\App\Support\Vocab::languages()) ?? __('not set') }} @include('description._ric', ['term' => 'openricx:hasRuleStatus'])
        <br><strong>{{ __('Rule type') }}:</strong> {{ $rule->ruleType ? $rule->ruleType->anyLabel(['en']).' ('.$rule->ruleType->vocabulary->title.')' : __('not set') }} @include('description._ric', ['term' => 'rico:hasOrHadRuleType'])
        @if($rule->authority_iri)<br><strong>{{ __('The rule elsewhere') }}:</strong> <a href="{{ $rule->authority_iri }}" rel="noopener noreferrer">{{ $rule->authority_iri }}</a> @include('description._ric', ['term' => 'owl:sameAs'])@endif</p>
    @if($canUpdate)
        @include('description._actions', ['subject' => $rule, 'editRoute' => 'rules.edit', 'required' => 'rule-name'])
    @endif
    @include('description._view', ['subject' => $rule, 'standard' => \App\Description\Elements::RULE, 'drafts' => $canUpdate])

    @include('description._dates', ['subject' => $rule, 'kind' => 'rules', 'heading' => 'Dates', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])
    @include('description._relations', ['subject' => $rule, 'kind' => 'rules', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Issued by', 'types' => ['issuance'], 'section' => 'is', 'add' => 'issuance|source', 'addLabel' => 'Add the agent that issued it, such as Parliament, a minister or the council'])
    @include('description._relations', ['subject' => $rule, 'kind' => 'rules', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Enforced by', 'types' => ['enforcement'], 'section' => 'en', 'add' => 'enforcement|source', 'addLabel' => 'Add the agent that enforces it, such as the National Archives or the Information Regulator'])
    @include('description._relations', ['subject' => $rule, 'kind' => 'rules', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Its text', 'types' => ['expression'], 'section' => 'ex', 'add' => 'expression|source', 'addLabel' => 'Add the record that carries its text, such as the Government Gazette that published it'])
    @include('description._relations', ['subject' => $rule, 'kind' => 'rules', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Amendments', 'types' => ['amendment'], 'section' => 'am', 'add' => 'amendment|source', 'addLabel' => 'Add a rule this one amends'])
    @include('description._relations', ['subject' => $rule, 'kind' => 'rules', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Supersedes or superseded', 'types' => ['supersession'], 'section' => 'su', 'add' => 'supersession|source', 'addLabel' => 'Add a rule this one replaced'])
    <p class="meta">{{ __('For a mandate, give the section that gives the authority, such as section 13(2) of the National Archives and Records Service of South Africa Act, as the basis of the relation.') }}</p>
    @include('description._relations', ['subject' => $rule, 'kind' => 'rules', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Gives authority to', 'types' => ['mandate'], 'section' => 'md', 'add' => 'mandate|source', 'addLabel' => 'Add an agent this mandate gives authority to'])
    @include('description._relations', ['subject' => $rule, 'kind' => 'rules', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Regulates', 'types' => ['regulation'], 'section' => 'rg', 'add' => 'regulation|source', 'addLabel' => 'Add what it regulates: a unit of description, an activity, an agent or an instantiation'])
    @include('description._relations', ['subject' => $rule, 'kind' => 'rules', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Places', 'types' => ['place'], 'section' => 'pl', 'add' => 'place|target', 'addLabel' => 'Add a place it applies to, such as its jurisdiction'])
    @include('description._names', ['subject' => $rule, 'kind' => 'rules', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $rule, 'kind' => 'rules', 'canUpdate' => $canUpdate])

    <h2>{{ __('Control') }}</h2>
    <p>{{ __('Description identifier') }}: {{ $rule->description_identifier ?? __('none') }}.
        {{ __('Status') }}: {{ $rule->descriptionStatus?->label(\App\Support\Vocab::languages()) ?? __('not set') }}.
        {{ __('Level of detail') }}: {{ $rule->descriptionDetail?->label(\App\Support\Vocab::languages()) ?? __('not set') }}.</p>
@endsection
