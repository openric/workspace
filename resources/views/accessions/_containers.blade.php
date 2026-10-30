{{-- OpenRiC Workspace - the containers an accession's material is in, and a new one for it (PSIS's and Heratio's accession containers). Needs $accession, $relations, $care, $canUpdate. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($storage = app(\App\Description\StorageService::class))
@php($containers = $relations->filter(fn ($r) => $r->kind() === 'storage' && $r->other instanceof \App\Models\Container && auth()->user()->may('read', 'storage', $r->other))->pluck('other'))
<section id="containers">
    <h2>{{ __('Containers') }}</h2>
    @if($containers->isNotEmpty())
        <table style="border-collapse: collapse">
            <thead><tr>@foreach(['Name', 'Type', 'Barcode', 'Location', 'Condition', 'Items'] as $h)<th scope="col" style="text-align: left; padding-right: 1rem">{{ __($h) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($containers as $c)
                @php($c->loadMissing('names.nameType', 'containerType.labels', 'condition.labels', 'identifiers.identifierType'))
                <tr>
                    <td style="padding-right: 1rem"><a href="{{ route('containers.show', $c) }}">{{ $c->described('container-name', $lang) }}</a></td>
                    <td style="padding-right: 1rem">{{ $c->containerType?->label($lang) }}</td>
                    <td style="padding-right: 1rem">{{ $c->identifiers->first(fn ($i) => $i->identifierType->iri === \App\Support\Vocab::IDENTIFIER_TYPES.'barcode')?->value }}</td>
                    <td style="padding-right: 1rem">{{ ($place = $storage->place($c)) ? $storage->path($place) : '' }}</td>
                    <td style="padding-right: 1rem">{{ $c->condition?->label($lang) }}</td>
                    <td style="padding-right: 1rem">{{ $c->item_count ?? $storage->contents($c)->count() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p>{{ __('No containers yet.') }}</p>
    @endif
    @if($canUpdate && $care['mayStore'])
        <details>
            <summary>{{ __('Add a container') }}</summary>
            <form method="post" action="{{ route('accessions.containers.store', $accession) }}">
                @csrf
                <small>{{ __('A new storage container, linked to this accession. To link one that exists, use Link physical storage.') }}</small>
                <label for="co-name">{{ __('Name') }}</label><input id="co-name" name="name" required maxlength="255">
                <label for="co-type">{{ __('Type') }}</label>
                <select id="co-type" name="type" style="font: inherit; padding: .5rem"><option value="">{{ __('Not set') }}</option>@foreach($care['containerTypes'] as $t)<option value="{{ $t->id }}">{{ $t->label($lang) }}</option>@endforeach</select>
                @if($care['barcodes'])<label for="co-barcode">{{ __('Barcode') }}</label><input id="co-barcode" name="barcode" maxlength="255" placeholder="{{ __('Scan or enter barcode') }}">@endif
                <label for="co-place">{{ __('Location') }}</label>
                <select id="co-place" name="place" style="font: inherit; padding: .5rem"><option value="">{{ __('Not kept anywhere yet') }}</option>@foreach($care['places'] as $p)<option value="{{ $p->id }}">{{ $p->path }}</option>@endforeach</select>
                <label for="co-condition">{{ __('Condition') }}</label>
                <select id="co-condition" name="condition" style="font: inherit; padding: .5rem"><option value="">{{ __('Not set') }}</option>@foreach($care['conditions'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                <div style="display: flex; gap: .75rem; flex-wrap: wrap">
                    <div><label for="co-items">{{ __('Number of items') }}</label><input id="co-items" name="item_count" inputmode="numeric" style="max-width: 8rem"></div>
                    <div><label for="co-weight">{{ __('Weight (kg)') }}</label><input id="co-weight" name="weight_kg" inputmode="decimal" style="max-width: 8rem"></div>
                </div>
                <input type="hidden" name="language" value="{{ app()->getLocale() }}">
                <button type="submit">{{ __('Add') }}</button>
            </form>
        </details>
    @endif
</section>
