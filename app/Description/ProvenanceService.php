<?php

/**
 * ProvenanceService - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-information-object-manage/src/Services/ProvenanceService.php
 * at commit 7663253e9 (governance/CODE_PROVENANCE.md, roadmap phase 9e, ADR 0010): getOverview and saveOverview,
 * the chain's createEntry, updateEntry, deleteEntry and resequence, createDocument and deleteDocument, and the CSV
 * export's columns. Kept: Heratio's fields, the chain in order with the next step last, the overview one per record.
 * Changed: the Workspace's tables, UUIDs and persistent IRIs (a step is a rico:Activity); every list a vocabulary
 * instead of ahg_dropdown codes; the owner an authority record when there is one; dates in EDTF; a step marked deleted,
 * never removed; documents kept by the file store; the audit log is the Workspace's. Added: steps move up and down,
 * and a description made from an accession starts its chain from the accession's donor and repository.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Description;

use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Models\Accession;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\ProvenanceDocument;
use App\Models\ProvenanceEntry;
use App\Models\ProvenanceOverview;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProvenanceService
{
    /** A step's concept fields and their vocabularies. */
    public const ENTRY_TERMS = ['owner_type' => 'provenance-owner-type', 'start_qualifier' => 'date-qualifier', 'end_qualifier' => 'date-qualifier',
        'transfer_type' => 'provenance-transfer-type', 'certainty' => 'provenance-certainty', 'evidence_type' => 'provenance-evidence-type'];

    /** How an accession's acquisition type becomes a step's transfer, and the overview's acquisition, custody and status. */
    private const FROM_ACQUISITION = ['gift' => ['donation', 'gift', 'permanent', 'owned'], 'purchase' => ['purchase', 'purchase', 'permanent', 'owned'],
        'transfer' => ['transfer', 'transfer', 'permanent', 'owned'], 'deposit' => ['deposit', null, 'deposit', 'deposited']];

    public function __construct(private FileStore $store) {}

    /**
     * Heratio's saveOverview: create or change the record's overview. Fields: the TERMS notations, acquisition_date
     * (YYYY-MM-DD), acquisition_date_text, acquisition_price, acquisition_currency, gap_description, research_notes,
     * nazi_era_notes, cultural_property_notes, summary, and the flags has_gaps, nazi_era_checked, nazi_era_clear (or
     * null, not yet known), is_complete, is_public.
     */
    public function saveOverview(RecordResource $record, array $fields, User $actor): ProvenanceOverview
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $values = [];
        foreach (ProvenanceOverview::TERMS as $field => $vocabulary) {
            $values[$field.'_id'] = $text($field) === null ? null : $this->term($vocabulary, $text($field))->id;
        }
        $values += ['acquisition_date' => $this->day($text('acquisition_date')), 'acquisition_date_text' => $text('acquisition_date_text'),
            ...$this->money($text('acquisition_price'), $text('acquisition_currency'), 'acquisition'),
            'has_gaps' => (bool) ($fields['has_gaps'] ?? false), 'gap_description' => $text('gap_description'), 'research_notes' => $text('research_notes'),
            'nazi_era_checked' => (bool) ($fields['nazi_era_checked'] ?? false),
            'nazi_era_clear' => in_array($fields['nazi_era_clear'] ?? null, [null, ''], true) ? null : (bool) $fields['nazi_era_clear'],
            'nazi_era_notes' => $text('nazi_era_notes'), 'cultural_property_notes' => $text('cultural_property_notes'), 'summary' => $text('summary'),
            'is_complete' => (bool) ($fields['is_complete'] ?? false), 'is_public' => (bool) ($fields['is_public'] ?? false)];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $record->provenanceOverview
            ? tap($record->provenanceOverview)->update($values)
            : ProvenanceOverview::create([...$values, 'institution_id' => $record->institution_id, 'record_resource_id' => $record->id, 'created_by_id' => $actor->id])));
    }

    /**
     * Add a step at the end of the chain (Heratio's createEntry), or change one. Fields: owner (an authority record's
     * key) and owner_name (the name as the source gives it; the authority record's name when empty), the ENTRY_TERMS
     * notations, owner_location, owner_location_tgn, start_date and end_date (EDTF), transfer_details, sale_price,
     * sale_currency, auction_house, auction_lot, sources, evidence_description, notes, is_gap, gap_explanation.
     */
    public function saveEntry(RecordResource $record, ?ProvenanceEntry $entry, array $fields, ?User $actor): ProvenanceEntry
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $owner = $text('owner') === null ? null : (Agent::where('institution_id', $record->institution_id)->whereNull('deleted_at')->find($text('owner'))
            ?? throw new InvalidArgumentException(__('the owner is an authority record of the institution')));
        $name = $text('owner_name') ?? $owner?->described('agent-authorised-name', [app()->getLocale(), 'en']) ?? throw new InvalidArgumentException(__('give the owner\'s name'));
        $values = ['owner_id' => $owner?->id, 'owner_name' => $name];
        foreach (self::ENTRY_TERMS as $field => $vocabulary) {
            $values[$field.'_id'] = $text($field) === null ? null : $this->term($vocabulary, $text($field))->id;
        }
        $values['transfer_type_id'] ??= $this->term('provenance-transfer-type', 'unknown')->id;
        foreach (['start_date', 'end_date'] as $date) {
            $value = $text($date);
            $value === null || Edtf::valid($value) || throw new InvalidArgumentException(__('give the date in EDTF, such as 1920, 1920-05 or 1920-05-14'));
            $values[$date] = $value;
        }
        $values += ['owner_location' => $text('owner_location'), 'owner_location_tgn' => $text('owner_location_tgn'), 'transfer_details' => $text('transfer_details'),
            ...$this->money($text('sale_price'), $text('sale_currency'), 'sale'),
            'auction_house' => $text('auction_house'), 'auction_lot' => $text('auction_lot'), 'sources' => $text('sources'),
            'evidence_description' => $text('evidence_description'), 'notes' => $text('notes'), 'is_gap' => (bool) ($fields['is_gap'] ?? false),
            'gap_explanation' => $text('gap_explanation')];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($record, $entry, $values) {
            if ($entry) {
                return tap($entry)->update($values);
            }
            $institution = Institution::findOrFail($record->institution_id);
            $id = (string) Str::uuid7();
            $entry = new ProvenanceEntry([...$values, 'institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.ProvenanceEntry::IRI_SEGMENT.'/'.$id,
                'record_resource_id' => $record->id, 'position' => (int) $record->provenanceEntries()->max('position') + 1]);
            $entry->id = $id;
            $entry->save();

            return $entry;
        }));
    }

    /** Heratio's deleteEntry: mark the step deleted, keeping its IRI, and number the rest 1, 2, 3 again. */
    public function removeEntry(ProvenanceEntry $entry, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($entry) {
            $entry->update(['deleted_at' => now()]);
            $this->resequence($entry->record);
        }));
    }

    /** Move a step one place earlier ($earlier) or later in the chain. */
    public function move(ProvenanceEntry $entry, bool $earlier, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($entry, $earlier) {
            $steps = $entry->record->provenanceEntries()->get()->values();
            $at = $steps->search(fn ($s) => $s->id === $entry->id);
            $other = $steps->get($earlier ? $at - 1 : $at + 1);
            if ($other) {
                [$a, $b] = [$entry->position, $other->position];
                $entry->update(['position' => $b]);
                $other->update(['position' => $a]);
            }
        }));
    }

    /**
     * Heratio's createDocument: a supporting document, an uploaded file or a link or archive reference. Fields:
     * document_type, title, description, document_date (YYYY-MM-DD), document_date_text, external_url,
     * archive_reference, is_public, entry (the step it evidences).
     */
    public function addDocument(RecordResource $record, array $fields, ?string $path, ?string $originalName, User $actor): ProvenanceDocument
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $path !== null || $text('external_url') !== null || $text('archive_reference') !== null
            || throw new InvalidArgumentException(__('attach a file, an external URL or an archive reference'));
        $entry = $text('entry') === null ? null : ($record->provenanceEntries()->find($text('entry')) ?? throw new InvalidArgumentException(__('the step is one of this record\'s')));
        // ponytail: provenance documents count towards no repository's upload limit, as agreement documents; add one when they fill disks.
        $file = $path === null ? [] : $this->store->ingest($record->institution_id, $path);

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => ProvenanceDocument::create([
            'institution_id' => $record->institution_id, 'record_resource_id' => $record->id, 'provenance_entry_id' => $entry?->id,
            'document_type_id' => $this->term('provenance-document-type', $text('document_type') ?? 'other')->id,
            'title' => $text('title') ?? ($originalName === null ? null : mb_substr($originalName, 0, 500)), 'description' => $text('description'),
            'document_date' => $this->day($text('document_date')), 'document_date_text' => $text('document_date_text'),
            'external_url' => $text('external_url'), 'archive_reference' => $text('archive_reference'), 'is_public' => (bool) ($fields['is_public'] ?? false),
            'original_name' => $file === [] ? null : mb_substr((string) $originalName, 0, 255), 'storage_path' => $file['storage_path'] ?? null,
            'mime_type' => $file['mime_type'] ?? null, 'size_bytes' => $file['size_bytes'] ?? null, 'sha256' => $file['sha256'] ?? null,
            'scanned_with' => $file['scanned_with'] ?? null, 'scanned_at' => $file['scanned_at'] ?? null, 'uploaded_by_id' => $actor->id])));
    }

    public function removeDocument(ProvenanceDocument $document, User $actor): void
    {
        // The file stays: it is kept by its checksum, and another document or instantiation may hold the same one.
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $document->delete()));
    }

    /**
     * Start the chain of a description made from an accession: its donor, and the accession into the repository, on
     * the acquisition date, with the accession number as the source; and, when it has none, an overview from the
     * accession's acquisition type and date. A description with a chain already is left as it is.
     */
    public function fromAccession(Accession $accession, RecordResource $record, User $actor): void
    {
        if ($record->provenanceEntries()->exists()) {
            return;
        }
        $accession->loadMissing('acquisitionType', 'dates.property', 'repository.names.nameType');
        [$transfer, $acquisition, $custody, $status] = self::FROM_ACQUISITION[$accession->acquisitionType?->notation] ?? ['unknown', null, null, null];
        $day = $accession->dates->first(fn ($d) => $d->property->notation === 'acquisition')?->normalized_value;
        $source = __('Accession :number', ['number' => $accession->number()]);
        $languages = [app()->getLocale(), 'en'];
        foreach (app(AccessionService::class)->donors($accession) as $donor) {
            $this->saveEntry($record, null, ['owner' => $donor->id, 'transfer_type' => $transfer, 'end_date' => $day, 'sources' => $source, 'evidence_type' => 'documentary'], $actor);
        }
        if ($accession->repository) {
            $this->saveEntry($record, null, ['owner_name' => $accession->repository->described('authorised-name', $languages), 'owner_type' => 'institution',
                'transfer_type' => 'accessioning', 'start_date' => $day, 'sources' => $source, 'evidence_type' => 'documentary'], $actor);
        }
        if (! $record->provenanceOverview()->exists()) {
            $this->saveOverview($record, ['acquisition_type' => $acquisition, 'custody_type' => $custody, 'current_status' => $status,
                'acquisition_date' => $day !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : null, 'is_public' => true], $actor);
        }
        $record->unsetRelation('provenanceEntries');
    }

    /**
     * Heratio's export CSV, its columns in order.
     *
     * @return list<list<?string>>
     */
    public function csv(RecordResource $record): array
    {
        $languages = Vocab::languages();
        $rows = [['#', 'Owner', 'Owner Type', 'Location', 'TGN', 'Start Date', 'End Date', 'Transfer', 'Certainty', 'Sale Price', 'Currency', 'Auction House', 'Lot #', 'Sources', 'Notes', 'Gap']];
        foreach ($record->provenanceEntries()->with('ownerType.labels', 'transferType.labels', 'certainty.labels')->get() as $e) {
            $rows[] = [(string) $e->position, $e->owner_name, $e->ownerType?->label($languages), $e->owner_location, $e->owner_location_tgn, $e->start_date, $e->end_date,
                $e->transferType->label($languages), $e->certainty?->label($languages), $e->sale_price, $e->sale_currency, $e->auction_house, $e->auction_lot,
                $e->sources, $e->notes, $e->is_gap ? 'Yes' : 'No'];
        }

        return $rows;
    }

    private function resequence(RecordResource $record): void
    {
        foreach ($record->provenanceEntries()->get()->values() as $i => $step) {
            if ($step->position !== $i + 1) {
                $step->update(['position' => $i + 1]);
            }
        }
    }

    /** @return array<string, ?string> */
    private function money(?string $amount, ?string $currency, string $prefix): array
    {
        $amount = $amount === null ? null : str_replace([' ', ','], '', $amount);
        $amount === null || preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) || throw new InvalidArgumentException(__('give the amount as a number, such as 1500.00'));
        $currency = $currency === null ? null : strtoupper($currency);
        $currency === null || preg_match('/^[A-Z]{3}$/', $currency) || throw new InvalidArgumentException(__('give the currency as a three-letter code, such as ZAR'));

        return ["{$prefix}_price" => $amount, "{$prefix}_currency" => $amount === null ? null : ($currency ?? 'ZAR')];
    }

    private function day(?string $day): ?string
    {
        if ($day === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date && $date->format('Y-m-d') === $day ? $day : throw new InvalidArgumentException(__('give the date as YYYY-MM-DD'));
    }

    private function term(string $vocabulary, string $notation): Concept
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
