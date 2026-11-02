{{-- OpenRiC Workspace - status and errors at the head of a research page (roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
@if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
