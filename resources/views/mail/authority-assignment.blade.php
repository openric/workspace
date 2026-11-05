{{-- OpenRiC Workspace - AuthorityAssignmentMail body (roadmap phase 17b2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
<p>{{ __(':by asked you to resolve ":value", a name in :title, to an authority record or place.', ['by' => $by->name, 'value' => $mention->value(), 'title' => \App\Relations\EntityKinds::label($mention->record)]) }}</p>
@if($note)<p>{{ $note }}</p>@endif
<p><a href="{{ route('authority.review', $mention) }}">{{ __('Open it for review') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
