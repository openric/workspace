{{-- OpenRiC Workspace - the fields of a researcher's registration (Heratio's researcher register and profile forms). Needs $r (or null), $types, $affiliations, $idTypes. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<p><label for="r-title">{{ __('Title') }}</label> <input id="r-title" name="title" maxlength="50" size="6" value="{{ old('title', $r?->title) }}">
    <label for="r-first">{{ __('First name') }}</label> <input id="r-first" name="first_name" required maxlength="100" value="{{ old('first_name', $r?->first_name) }}">
    <label for="r-last">{{ __('Surname') }}</label> <input id="r-last" name="last_name" required maxlength="100" value="{{ old('last_name', $r?->last_name) }}">
    <label for="r-phone">{{ __('Telephone') }}</label> <input id="r-phone" name="phone" maxlength="50" value="{{ old('phone', $r?->phone) }}"></p>
<p><label for="r-affiliation">{{ __('Affiliation') }}</label> <select id="r-affiliation" name="affiliation">@foreach($affiliations as $a)<option value="{{ $a->notation }}" @selected(old('affiliation', $r?->affiliation?->notation ?? 'independent') === $a->notation)>{{ $a->label($lang) }}</option>@endforeach</select>
    <label for="r-institution">{{ __('Institution') }}</label> <input id="r-institution" name="institution" maxlength="255" value="{{ old('institution', $r?->institution) }}">
    <label for="r-department">{{ __('Department') }}</label> <input id="r-department" name="department" maxlength="255" value="{{ old('department', $r?->department) }}">
    <label for="r-position">{{ __('Position') }}</label> <input id="r-position" name="position" maxlength="255" value="{{ old('position', $r?->position) }}">
    <label for="r-student">{{ __('Student number') }}</label> <input id="r-student" name="student_id" maxlength="100" value="{{ old('student_id', $r?->student_id) }}"></p>
@if(! $r && $types->isNotEmpty())<p><label for="r-type">{{ __('Kind of researcher') }}</label> <select id="r-type" name="researcher_type_id"><option value="">-</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected(old('researcher_type_id') === $t->id)>{{ $t->name }}</option>@endforeach</select></p>@endif
<p><label for="r-interests">{{ __('Research interests') }}</label><br><textarea id="r-interests" name="research_interests" rows="3" cols="70">{{ old('research_interests', $r?->research_interests) }}</textarea></p>
<p><label for="r-project">{{ __('Current project') }}</label><br><textarea id="r-project" name="current_project" rows="2" cols="70">{{ old('current_project', $r?->current_project) }}</textarea></p>
<p><label for="r-orcid">{{ __('ORCID iD') }}</label> <input id="r-orcid" name="orcid_id" maxlength="19" placeholder="0000-0002-1825-0097" value="{{ old('orcid_id', $r?->orcid_id) }}">
    <label for="r-wos">{{ __('Web of Science ResearcherID') }}</label> <input id="r-wos" name="researcher_id_wos" maxlength="100" value="{{ old('researcher_id_wos', $r?->researcher_id_wos) }}">
    <label for="r-scopus">{{ __('Scopus Author ID') }}</label> <input id="r-scopus" name="scopus_id" maxlength="100" value="{{ old('scopus_id', $r?->scopus_id) }}">
    <label for="r-isni">{{ __('ISNI') }}</label> <input id="r-isni" name="isni" maxlength="19" value="{{ old('isni', $r?->isni) }}"></p>
<p><label for="r-idtype">{{ __('Identity document') }}</label> <select id="r-idtype" name="id_type"><option value="">-</option>@foreach($idTypes as $t)<option value="{{ $t->notation }}" @selected(old('id_type', $r?->idType?->notation) === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select>
    <label for="r-idnumber">{{ __('Its number') }}</label> <input id="r-idnumber" name="id_number" maxlength="100" value="{{ old('id_number', $r?->id_number) }}">
    <label for="r-photo">{{ __('Photograph (for your reader\'s card)') }}</label> <input id="r-photo" type="file" name="photo" accept="image/*"></p>
<p class="meta">{{ __('Your telephone and identity number are kept encrypted, and used only to admit you to the reading room.') }}</p>
