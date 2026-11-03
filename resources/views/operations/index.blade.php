{{-- OpenRiC Workspace - operations: how the installation is doing, failed background jobs, and the institution's fixity (roadmap phase 12a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Operations'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    <h1>{{ __('Operations') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p><a href="{{ route('fixity.schedules') }}">{{ __('Fixity schedules and runs') }}</a> · <a href="{{ route('fixity.failures') }}">{{ trans_choice(':count fixity failure open|:count fixity failures open', $openFailures, ['count' => $openFailures]) }}</a> · <a href="{{ route('jobs.index') }}">{{ __('Jobs') }}</a></p>
    @if($operator)
        <h2>{{ __('The installation') }}</h2>
        @forelse($problems as $problem)<p class="error" role="alert">{{ $problem }}</p>@empty<p role="status">{{ __('Nothing needs attention.') }}</p>@endforelse
        <table><thead><tr><th scope="col">{{ __('Figure') }}</th><th scope="col">{{ __('Value') }}</th><th scope="col">{{ __('What it is') }}</th></tr></thead>
            <tbody>@foreach($figures as $name => [$value, $help])<tr @if(isset($problems[$name])) style="font-weight: 600" @endif><td><code>{{ $name }}</code></td>
                <td>{{ str_ends_with($name, '_bytes') ? \Illuminate\Support\Number::fileSize($value, 1) : (str_ends_with($name, '_timestamp_seconds') ? ($value ? \Carbon\Carbon::createFromTimestamp($value)->timezone($tz)->format('Y-m-d H:i') : __('never')) : number_format($value)) }}</td><td>{{ __($help) }}</td></tr>@endforeach</tbody></table>
        <p><small>{{ $metricsOn ? __('A monitor reads the same figures as Prometheus text at :url, sending the metrics token.', ['url' => route('metrics')]) : __('Set WORKSPACE_METRICS_TOKEN to let a monitor read these figures as Prometheus text at :url.', ['url' => route('metrics')]) }}</small></p>
        <h2>{{ __('Background jobs that failed') }}</h2>
        <table><thead><tr><th scope="col">{{ __('Failed') }}</th><th scope="col">{{ __('Job') }}</th><th scope="col">{{ __('Queue') }}</th><th scope="col">{{ __('Why') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th></tr></thead>
            <tbody>@forelse($failedJobs as $job)<tr><td>{{ \Carbon\Carbon::parse($job->failed_at)->timezone($tz)->format('Y-m-d H:i') }}</td><td>{{ json_decode($job->payload)->displayName ?? '' }}</td><td>{{ $job->queue }}</td>
                <td><small>{{ \Illuminate\Support\Str::limit(strtok($job->exception, "\n"), 200) }}</small></td>
                <td>@foreach(['retry' => __('Try again'), 'forget' => __('Forget')] as $action => $label)<form method="post" action="{{ route('operations.failed-job', [$job->uuid, $action]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ $label }}</button></form> @endforeach</td></tr>
            @empty<tr><td colspan="5">{{ __('None.') }}</td></tr>@endforelse</tbody></table>
    @else
        <p>{{ __('The installation as a whole (its queue, disks and failed jobs) is shown to its operators.') }}</p>
    @endif
    <h2>{{ __('Latest fixity runs') }}</h2>
    @include('operations._runs', ['runs' => $runs])
@endsection
