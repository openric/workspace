<?php

// OpenRiC Workspace - add, change and remove an entity's contact points.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\ContactPoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
}
