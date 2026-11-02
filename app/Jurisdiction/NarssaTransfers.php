<?php

// OpenRiC Workspace - transfers of records to the National Archives and Records Service of South Africa (roadmap phase
// 18d, ADR 0016).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's TransferPackageService (build, buildFromApprovedDisposals, its METS manifest). A transfer gathers
// records, each with its description as EAD3 and its master files, into a package: a tar.gz with one folder a record,
// manifest.csv (reference code, title, disposal class, files, bytes, SHA-256) and transfer.xml, a METS file listing
// each record with its checksum. The package is kept in the object store with its SHA-256. A transfer can be built from
// the transfer disposals that are approved or cleared and in no transfer yet; carrying out each disposal stays a step
// of the disposal workflow, which names the National Archives as the destination. Statuses: draft, packaged,
// transmitted, accepted with the receipt's reference, or returned. Changed: Heratio wrote the package with a shell
// tar command; here it is written by PHP's own archive classes, and only the master files reach the package, never a
// reference copy.

namespace App\Jurisdiction;

use App\Audit\AuditLog;
use App\Exchange\Ead;
use App\Exchange\ExportView;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\DisposalAction;
use App\Models\Institution;
use App\Models\NarssaTransfer;
use App\Models\RecordDisposalClass;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PharData;
use XMLWriter;

class NarssaTransfers
{
    public function __construct(private FileStore $store) {}

    /** Package the records (with the disposal each comes from, if any) as a transfer. */
    public function build(User $actor, array $records, ?string $title = null, ?string $description = null, array $disposals = []): NarssaTransfer
    {
        $records = collect($records)->filter(fn ($r) => $r instanceof RecordResource && $r->institution_id === $actor->institution_id && $r->deleted_at === null)->unique('id')->values();
        if ($records->isEmpty()) {
            throw new InvalidArgumentException(__('Choose the records to transfer.'));
        }
        $institution = Institution::findOrFail($actor->institution_id);
        $reference = $this->reference($institution);
        $view = new ExportView($actor, false, null, $institution);
        $work = sys_get_temp_dir().'/narssa-'.Str::random(12);
        mkdir($work.'/'.$reference.'/items', 0700, true);
        $items = [];
        foreach ($records as $record) {
            $code = (string) ($record->referenceCode() ?? $record->iri);
            $folder = 'items/'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $code);
            $dir = $work.'/'.$reference.'/'.$folder;
            @mkdir($dir.'/files', 0700, true);
            $ead = app(Ead::class)->export($record, $view, true);
            file_put_contents($dir.'/description.xml', $ead);
            $hashes = [hash('sha256', $ead)];
            [$count, $bytes] = [0, 0];
            foreach ($record->instantiations()->whereNull('deleted_at')->with('files.use')->get()->flatMap->files->filter(fn ($f) => $f->use->notation === 'master') as $file) {
                $path = $this->store->path($file->storage_path);
                if (is_file($path)) {
                    copy($path, $dir.'/files/'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->original_name));
                    $hashes[] = $file->sha256;
                    $count++;
                    $bytes += $file->size_bytes;
                }
            }
            $class = RecordDisposalClass::where('record_resource_id', $record->id)->with('disposalClass')->first()?->disposalClass;
            $items[] = ['record' => $record, 'reference_code' => $code, 'title' => EntityKinds::label($record), 'schedule_code' => $class?->reference, 'file_count' => $count, 'file_bytes' => $bytes,
                'sha256' => hash('sha256', implode('', $hashes)), 'folder' => $folder, 'disposal' => $disposals[$record->id] ?? null];
        }
        $csv = fopen($work.'/'.$reference.'/manifest.csv', 'w');
        fputcsv($csv, ['reference_code', 'title', 'disposal_class', 'files', 'bytes', 'sha256'], escape: '');
        foreach ($items as $i) {
            fputcsv($csv, [$i['reference_code'], $i['title'], $i['schedule_code'], $i['file_count'], $i['file_bytes'], $i['sha256']], escape: '');
        }
        fclose($csv);
        file_put_contents($work.'/'.$reference.'/transfer.xml', $this->mets($reference, $institution, $items));
        $tar = new PharData($work.'/'.$reference.'.tar');
        $tar->buildFromDirectory($work, '#/'.preg_quote($reference, '#').'/#');
        $tar->compress(\Phar::GZ);
        $kept = $this->store->ingest($institution->id, $work.'/'.$reference.'.tar.gz');
        exec('rm -rf '.escapeshellarg($work));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $institution, $reference, $title, $description, $items, $kept) {
            $transfer = NarssaTransfer::create(['institution_id' => $institution->id, 'reference' => $reference, 'title' => trim((string) $title) ?: __('NARSSA transfer :reference', ['reference' => $reference]),
                'description' => trim((string) $description) ?: null, 'schedule_codes' => collect($items)->pluck('schedule_code')->filter()->unique()->implode(', ') ?: null,
                'initiated_by_id' => $actor->id, 'item_count' => count($items), 'total_size_bytes' => array_sum(array_column($items, 'file_bytes')), 'package_path' => $kept['storage_path'],
                'package_sha256' => $kept['sha256'], 'status_id' => $this->status('packaged')]);
            foreach ($items as $i) {
                $transfer->items()->create(['record_resource_id' => $i['record']->id, 'disposal_action_id' => $i['disposal'], 'reference_code' => $i['reference_code'], 'title_snapshot' => mb_substr($i['title'], 0, 500),
                    'schedule_code' => $i['schedule_code'], 'file_count' => $i['file_count'], 'file_bytes' => $i['file_bytes'], 'sha256' => $i['sha256'], 'created_at' => now()]);
            }

            return $transfer;
        }));
    }

    /** A transfer of every transfer disposal approved or cleared and in no transfer yet (Heratio's buildFromApprovedDisposals). */
    public function fromDisposals(User $actor): NarssaTransfer
    {
        $disposals = DisposalAction::where('institution_id', $actor->institution_id)->whereHas('action', fn ($q) => $q->where('iri', Vocab::BASE.'disposal-action-type/transfer'))
            ->whereHas('status', fn ($q) => $q->whereIn('iri', [Vocab::BASE.'disposal-status/approved', Vocab::BASE.'disposal-status/cleared']))
            ->whereNotIn('id', DB::table('narssa_transfer_items')->whereNotNull('disposal_action_id')->select('disposal_action_id'))->with('record')->get();
        if ($disposals->isEmpty()) {
            throw new InvalidArgumentException(__('No approved transfer disposal waits for a transfer.'));
        }

        return $this->build($actor, $disposals->pluck('record')->all(), __('Transfer of approved disposals'), null, $disposals->mapWithKeys(fn ($d) => [$d->record_resource_id => $d->id])->all());
    }

    /** transmitted, accepted (with the receipt's reference) or returned. */
    public function step(User $actor, NarssaTransfer $transfer, string $step, ?string $receipt = null, ?string $notes = null): void
    {
        $now = $transfer->status->notation;
        $allowed = ['transmitted' => ['packaged', 'returned'], 'accepted' => ['transmitted'], 'returned' => ['transmitted']];
        if (! isset($allowed[$step]) || ! in_array($now, $allowed[$step], true)) {
            throw new InvalidArgumentException(__('That cannot be done to a transfer that is :status.', ['status' => mb_strtolower($transfer->status->label(['en']))]));
        }
        if ($step === 'accepted' && trim((string) $receipt) === '') {
            throw new InvalidArgumentException(__('Give the reference of the National Archives\' receipt.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $transfer->update(['status_id' => $this->status($step)] + match ($step) {
            'transmitted' => ['transmitted_at' => now()],
            'accepted' => ['accepted_at' => now(), 'receipt_reference' => trim($receipt)],
            default => [],
        } + (trim((string) $notes) !== '' ? ['notes' => trim(($transfer->notes ? $transfer->notes."\n" : '').$notes)] : []))));
    }

    private function mets(string $reference, Institution $institution, array $items): string
    {
        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('mets');
        $w->writeAttribute('xmlns', 'http://www.loc.gov/METS/');
        $w->writeAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $w->writeAttribute('OBJID', $reference);
        $w->writeAttribute('LABEL', 'NARSSA transfer '.$reference);
        $w->writeAttribute('TYPE', 'archive-transfer');
        $w->startElement('metsHdr');
        $w->writeAttribute('CREATEDATE', now()->toIso8601String());
        $w->startElement('agent');
        $w->writeAttribute('ROLE', 'CREATOR');
        $w->writeAttribute('TYPE', 'ORGANIZATION');
        $w->writeElement('name', $institution->name);
        $w->endElement();
        $w->endElement();
        $w->startElement('fileSec');
        $w->startElement('fileGrp');
        $w->writeAttribute('USE', 'archival-items');
        foreach ($items as $n => $i) {
            $w->startElement('file');
            $w->writeAttribute('ID', 'item-'.($n + 1));
            $w->writeAttribute('CHECKSUM', $i['sha256']);
            $w->writeAttribute('CHECKSUMTYPE', 'SHA-256');
            $w->writeAttribute('SIZE', (string) $i['file_bytes']);
            $w->startElement('FLocat');
            $w->writeAttribute('LOCTYPE', 'OTHER');
            $w->writeAttribute('OTHERLOCTYPE', 'tar-path');
            $w->writeAttribute('xlink:href', $i['folder']);
            $w->endElement();
            $w->endElement();
        }
        $w->endElement();
        $w->endElement();
        $w->startElement('structMap');
        $w->startElement('div');
        $w->writeAttribute('TYPE', 'transfer');
        $w->writeAttribute('LABEL', $reference);
        foreach ($items as $n => $i) {
            $w->startElement('div');
            $w->writeAttribute('TYPE', 'record');
            $w->writeAttribute('LABEL', $i['reference_code'].' '.$i['title']);
            $w->startElement('fptr');
            $w->writeAttribute('FILEID', 'item-'.($n + 1));
            $w->endElement();
            $w->endElement();
        }
        $w->endElement();
        $w->endElement();
        $w->endElement();

        return $w->outputMemory();
    }

    /** NARSSA-YYYY-NNN, numbered within the institution and year (Heratio's allocateReference). */
    private function reference(Institution $institution): string
    {
        $year = now(config('workspace.timezone'))->format('Y');
        $n = NarssaTransfer::where('institution_id', $institution->id)->where('reference', 'like', "NARSSA-$year-%")->count() + 1;

        return sprintf('NARSSA-%s-%03d', $year, $n);
    }

    private function status(string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.'narssa-transfer-status/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
