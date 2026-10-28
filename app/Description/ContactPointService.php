<?php

// OpenRiC Workspace - add, change and remove an entity's contact points.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\ContactPoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactPointService
{
    /** @param  array<string, mixed>  $fields  ContactPoint::FIELDS and is_primary */
    public function save(Model $subject, array $fields, ?ContactPoint $contact = null, ?User $actor = null): ContactPoint
    {
        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($subject, $fields, $contact) {
            $values = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), array_intersect_key($fields, array_flip(ContactPoint::FIELDS)));
            if (isset($values['country_code'])) {
                $values['country_code'] = strtoupper($values['country_code']);
            }
            $values['is_primary'] = (bool) ($fields['is_primary'] ?? false);
            $contact ??= new ContactPoint(['institution_id' => $subject->getAttribute('institution_id'), 'subject_table' => $subject->getTable(), 'subject_id' => $subject->getKey()]);
            $contact->fill($values)->save();
            // One primary contact per entity: making this one primary clears the others.
            if ($contact->is_primary) {
                ContactPoint::where(['subject_table' => $contact->subject_table, 'subject_id' => $contact->subject_id, 'is_primary' => true])
                    ->whereKeyNot($contact->id)->get()->each->update(['is_primary' => false]);
            }

            return $contact;
        }));
    }

    public function remove(ContactPoint $contact, ?User $actor = null): void
    {
        // The Audited trait records the removal with everything the contact point held.
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $contact->delete()));
    }

    /** The whole contact point: a field the form did not send is cleared, not kept. */
    public function fieldsFrom(Request $request): array
    {
        $sent = $request->validate([
            'is_primary' => ['nullable', 'boolean'],
            'label' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'street_address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'telephone' => ['nullable', 'string', 'max:64'],
            'fax' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:1024'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        // + keeps the left-hand keys, so what was sent wins over the empty defaults.
        return $sent + array_fill_keys(ContactPoint::FIELDS, null);
    }
}
