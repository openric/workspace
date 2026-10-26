{{-- OpenRiC Workspace - the clipboard: its entries, save, load and export (Heratio's clipboard page and menu). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $rows (ClipboardService::details). --}}
<h1>{{ __('Clipboard') }}</h1>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if(session('clipboard_id'))
    <p role="status">{{ __('Clipboard saved. Its clipboard ID is') }} <strong>{{ session('clipboard_id') }}</strong>{{ __(': write it down, and enter it under "Load a clipboard" to bring these entries back later, here or on another computer.') }}</p>
@endif
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
@php($names = ['records' => 'Archival descriptions', 'agents' => 'Authority records', 'repositories' => 'Repositories'])
@forelse($rows->groupBy('type') as $type => $entries)
    <h2>{{ $names[$type] }} ({{ $entries->count() }})</h2>
    <ul>
        @foreach($entries as $row)
            <li><a href="{{ $row['url'] }}">{{ $row['name'] }}</a>
                <form method="post" action="{{ route('clipboard.remove') }}" style="display: inline">@csrf<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $row['id'] }}">
                    <button type="submit" aria-pressed="false" style="margin: 0 0 0 .5rem; padding: .1rem .5rem; font-size: .8rem" aria-label="Take {{ $row['name'] }} off the clipboard">{{ __('Remove') }}</button></form></li>
        @endforeach
    </ul>
@empty
    <p>{{ __('The clipboard is empty. Add descriptions, authority records and repositories from their pages with "Add to clipboard".') }}</p>
@endforelse
@if($rows->isNotEmpty())
    <p>
        <form method="post" action="{{ route('clipboard.save') }}" style="display: inline">@csrf<button type="submit">{{ __('Save the clipboard') }}</button></form>
        <a href="{{ route('clipboard.export') }}" style="margin: 0 1rem">{{ __('Export as CSV') }}</a>
        <form method="post" action="{{ route('clipboard.clear') }}" style="display: inline">@csrf<button type="submit" aria-pressed="false">{{ __('Empty the clipboard') }}</button></form>
    </p>
@endif
<h2>{{ __('Load clipboard') }}</h2>
<form method="post" action="{{ route('clipboard.load') }}">
    @csrf
    <label for="clipboard_id">{{ __('Clipboard ID') }}</label>
    <input id="clipboard_id" name="clipboard_id" required maxlength="16" inputmode="numeric" style="max-width: 12rem">
    <label style="font-weight: normal"><input type="radio" name="mode" value="merge" checked style="width: auto"> {{ __('Add to what is on the clipboard') }}</label>
    <label style="font-weight: normal; margin-top: .3rem"><input type="radio" name="mode" value="replace" style="width: auto"> {{ __('Replace what is on the clipboard') }}</label>
    <button type="submit">{{ __('Load') }}</button>
</form>
