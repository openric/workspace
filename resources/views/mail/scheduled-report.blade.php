{{-- OpenRiC Workspace - ScheduledReportMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
<p>{{ __('The report :name, made on :date, is attached. It covers :count entities.', ['name' => $report->name, 'date' => $archive->generated_at->setTimezone(config('workspace.timezone'))->format('Y-m-d H:i'), 'count' => $archive->entities]) }}</p>
<p><a href="{{ route('reports.builder.show', $report) }}">{{ __('Open the report') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
