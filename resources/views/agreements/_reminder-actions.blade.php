{{-- OpenRiC Workspace - complete, snooze, cancel or reactivate a reminder (Heratio's reminder statuses). Needs $reminder; $back = 'reminders' to return to the list. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($action = route('agreements.reminders.status', [$reminder->agreement_id, $reminder->id]))
@php($open = in_array($reminder->status->notation, ['active', 'snoozed'], true))
<div style="display: flex; gap: .5rem; flex-wrap: wrap; align-items: end; margin: 0 0 .6rem">
    @if($open)
        <form method="post" action="{{ $action }}" style="display: flex; gap: .4rem; align-items: end">@csrf<input type="hidden" name="status" value="completed">@if($back)<input type="hidden" name="back" value="{{ $back }}">@endif
            <div><label for="c-{{ $reminder->id }}" style="margin-top: 0">{{ __('Completion note') }}</label><input id="c-{{ $reminder->id }}" name="completion_note" maxlength="2000"></div>
            <button type="submit" style="margin-top: 0">{{ __('Complete') }}</button></form>
        <form method="post" action="{{ $action }}" style="display: flex; gap: .4rem; align-items: end">@csrf<input type="hidden" name="status" value="snoozed">@if($back)<input type="hidden" name="back" value="{{ $back }}">@endif
            <div><label for="s-{{ $reminder->id }}" style="margin-top: 0">{{ __('Snooze until') }}</label><input id="s-{{ $reminder->id }}" name="snoozed_until" placeholder="YYYY-MM-DD" maxlength="10"></div>
            <button type="submit" style="margin-top: 0">{{ __('Snooze') }}</button></form>
        <form method="post" action="{{ $action }}">@csrf<input type="hidden" name="status" value="cancelled">@if($back)<input type="hidden" name="back" value="{{ $back }}">@endif<button type="submit" style="margin-top: 0">{{ __('Cancel reminder') }}</button></form>
    @else
        <form method="post" action="{{ $action }}">@csrf<input type="hidden" name="status" value="active">@if($back)<input type="hidden" name="back" value="{{ $back }}">@endif<button type="submit" style="margin-top: 0">{{ __('Make active again') }}</button></form>
    @endif
</div>
