{{-- OpenRiC Workspace - a research project's ethics approvals (Heratio's research ethics page; roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Ethics approvals').' - '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('projects._part-head')
    <h1>{{ __('Ethics approvals') }}</h1>
    @forelse($project->ethicsApprovals as $a)
        @php($flag = \App\Research\DataPlans::expiry($a))
        <article>
            <h2>{{ $a->title }}</h2>
            <p>{{ $a->approvalType->label($lang) }} · {{ $a->status->label($lang) }}@if($a->committee_name) · {{ $a->committee_name }}@endif @if($a->reference_number) · {{ $a->reference_number }}@endif
                @if($a->decision_date) · {{ __('Decided on :date', ['date' => $a->decision_date->format('j M Y')]) }}@endif
                @if($a->expiry_date) · {{ __('Expires on :date', ['date' => $a->expiry_date->format('j M Y')]) }}@endif
                @if($flag === 'expired') · <strong class="error">{{ __('Expired') }}</strong>@elseif($flag === 'soon') · <strong>{{ __('Expires soon') }}</strong>@endif</p>
            <p class="meta">{{ __('Consent basis') }}: {{ $a->consentBasis->label($lang) }} · {{ __('Data sensitivity') }}: {{ $a->dataSensitivity->label($lang) }}@if($a->dmp) · {{ __('Plan') }}: <a href="{{ route('research.projects.plans.show', [$project, $a->dmp]) }}">{{ $a->dmp->title }}</a>@endif</p>
            @if($a->notes)<p style="white-space: pre-wrap">{{ $a->notes }}</p>@endif
            @if($level >= 2)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.ethics.save', [$project, $a]) }}">@csrf @include('projects._ethics-fields', ['x' => 'e'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                @if($level >= 3 || $a->created_by_id === $user->id)<form method="post" action="{{ route('research.funding.part.remove', ['ethics', $a->id]) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</details>@endif
        </article>
    @empty
        <p>{{ __('No ethics approval is recorded.') }}</p>
    @endforelse
    @if($level >= 2)<details><summary>{{ __('Record an approval') }}</summary><form method="post" action="{{ route('research.ethics.save', $project) }}">@csrf @include('projects._ethics-fields', ['a' => null, 'x' => 'en'])<button type="submit">{{ __('Add') }}</button></form></details>@endif
@endsection
