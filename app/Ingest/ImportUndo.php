<?php

// OpenRiC Workspace - undo a committed import: what it made, and nothing since changed, is deleted (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After PSIS's ahgIngestPlugin, whose commit can be rolled back. Here an import is committed in parts, so it is undone
// afterwards rather than rolled back: each entry the import created is deleted as any entity is (EntityDeletion), its
// IRI kept and answering as deprecated, children before their parents and the files the import kept before the
// records that carry them. An entry anyone changed after the commit, or that has something the import did not make
// inside it, is kept and named in the report, so undoing never loses later work. What the import only updated is not
// put back: the audit log keeps what it was. Agents, places and terms an import made for access points stay.

namespace App\Ingest;

use App\Description\EntityDeletion;
use App\Exchange\Import\ImportService;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Import;
use App\Models\Instantiation;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ImportUndo
{
    private const MODELS = ['record_resources' => RecordResource::class, 'agents' => Agent::class,
        'repositories' => Repository::class, 'activities' => Activity::class];

    public function __construct(private EntityDeletion $deletion) {}

    /** @return array{deleted: int, kept: list<array{id: string, title: ?string, why: string}>} */
    public function run(Import $import, User $actor): array
    {
        if ($import->committed_at === null) {
            throw new InvalidArgumentException(__('Only a committed import can be undone.'));
        }
        if ($import->institution_id !== $actor->institution_id || ($import->user_id !== $actor->id && ! $actor->hasPermission(Vocab::permission('manage-institution')))) {
            throw new InvalidArgumentException(__('Only who imported it, or who administers the institution, undoes it.'));
        }
        if ($import->options['undone_at'] ?? null) {
            throw new InvalidArgumentException(__('It was undone already.'));
        }
        $table = ImportService::TABLES[$import->format] ?? 'record_resources';
        $model = self::MODELS[$table];
        $created = collect($import->report['units'] ?? [])->where('action', 'created')->filter(fn ($u) => ($u['unit_id'] ?? '') !== '')->pluck('title', 'unit_id')->all();
        $entities = $model::whereIn('id', array_keys($created))->whereNull('deleted_at')->get();
        // Deepest first: a unit's children go before it.
        $depth = fn ($e) => $table === 'record_resources' ? count($e->ancestors()) : 0;
        $entities = $entities->sortByDesc($depth)->values();
        // Later work is what the audit log holds after the commit's last entry; its sequence, not a clock, orders it.
        $after = (int) ($import->options['audit_seq'] ?? PHP_INT_MAX);
        // Changed: the entry itself, or anything naming it (its values, names, dates, relations and instantiations), since.
        $changed = fn (string $subjectTable, string $id) => DB::table('audit_log')->where('seq', '>', $after)
            ->where(fn ($q) => $q->where(['subject_table' => $subjectTable, 'subject_id' => $id])
                ->orWhere(fn ($q) => $q->where('subject_table', 'description_values')->whereIn('subject_id', DB::table('description_values')->where('subject_id', $id)->select('id')))
                ->orWhere(fn ($q) => $q->where('subject_table', 'names')->whereIn('subject_id', DB::table('names')->where('subject_id', $id)->select('id')))
                ->orWhereRaw('after::text LIKE ?', ['%'.$id.'%'])->orWhereRaw('before::text LIKE ?', ['%'.$id.'%']))->exists();
        [$deleted, $kept] = [0, []];
        foreach ($entities as $entity) {
            $why = $changed($table, $entity->id) ? __('changed since it was imported') : null;
            if ($why === null && $table === 'record_resources' && ! $actor->may('delete', 'description', $entity)) {
                $why = __('you may not delete it');
            }
            // The files the import kept go first, unless someone added or changed one since.
            if ($why === null && $table === 'record_resources') {
                $carriers = Instantiation::where('record_resource_id', $entity->id)->whereNull('deleted_at')->get();
                $later = $carriers->first(fn ($i) => DB::table('audit_log')->where(['subject_table' => 'instantiations', 'subject_id' => $i->id])->where('seq', '<=', $after)->doesntExist()
                    || $changed('instantiations', $i->id));
                if ($later) {
                    $why = __('it has an instantiation added or changed since');
                } else {
                    try {
                        $carriers->each(fn ($i) => $this->deletion->delete($i, $actor, __('Import :file undone', ['file' => $import->file_name])));
                    } catch (InvalidArgumentException $e) {
                        $why = $e->getMessage();
                    }
                }
            }
            if ($why === null) {
                try {
                    $this->deletion->delete($entity, $actor, __('Import :file undone', ['file' => $import->file_name]));
                    $deleted++;

                    continue;
                } catch (InvalidArgumentException $e) {
                    $why = $e->getMessage();
                }
            }
            $kept[] = ['id' => $entity->id, 'title' => $created[$entity->id] ?? null, 'why' => $why];
        }
        $import->update(['options' => [...$import->options, 'undone_at' => now()->toIso8601String(), 'undone_by_id' => $actor->id, 'undo' => ['deleted' => $deleted, 'kept' => $kept]]]);

        return ['deleted' => $deleted, 'kept' => $kept];
    }
}
