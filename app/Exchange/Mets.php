<?php

// OpenRiC Workspace - a unit of description and everything inside it as one METS document (roadmap phase 22c).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-api MetsController. METS 1.12: each unit's description as Dublin Core in its own dmdSec, the files
// of its instantiations the reader may receive in the fileSec by their use (master, reference, thumbnail), each with
// its SHA-256 and the address it is fetched at, and the units' arrangement as a logical structMap whose divisions
// point to their descriptions and files. Only what the export's reader may read goes in, as with every export.

namespace App\Exchange;

use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Models\DigitalFile;
use App\Models\Institution;
use App\Models\RecordResource;
use Illuminate\Support\Collection;
use XMLWriter;

class Mets
{
    public function __construct(private DublinCore $dublinCore, private InstantiationService $instantiations) {}

    /** @param  Collection<string, Collection<int, RecordResource>>  $below  the units inside, by their parent */
    public function export(RecordResource $top, Collection $below, ExportView $view): string
    {
        $units = [$top];
        $walk = function (string $parent) use (&$walk, &$units, $below) {
            foreach ($below[$parent] ?? [] as $unit) {
                $units[] = $unit;
                $walk($unit->id);
            }
        };
        $walk($top->id);
        $units = array_values(array_filter($units, fn ($u) => $view->readable($u)));
        $ids = array_map(fn ($u) => $u->id, $units);
        $files = DigitalFile::whereHas('instantiation', fn ($q) => $q->whereIn('record_resource_id', $ids)->whereNull('deleted_at'))
            ->with('use', 'instantiation.record', 'instantiation.availability')->get()
            ->filter(fn ($f) => $this->instantiations->deliverable($view->user, $f))->groupBy(fn ($f) => $f->instantiation->record_resource_id);

        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('mets');
        $x->writeAttribute('xmlns', 'http://www.loc.gov/METS/');
        $x->writeAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $x->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->writeAttribute('xsi:schemaLocation', 'http://www.loc.gov/METS/ https://www.loc.gov/standards/mets/mets.xsd');
        $x->writeAttribute('OBJID', $top->iri);
        $x->writeAttribute('LABEL', (string) $view->label($top));
        $x->startElement('metsHdr');
        $x->writeAttribute('CREATEDATE', now()->toIso8601String());
        $x->startElement('agent');
        $x->writeAttribute('ROLE', 'CREATOR');
        $x->writeAttribute('TYPE', 'ORGANIZATION');
        $x->writeElement('name', (string) Institution::find($top->institution_id)?->name);
        $x->endElement();
        $x->endElement();
        foreach ($units as $n => $unit) {
            $x->startElement('dmdSec');
            $x->writeAttribute('ID', 'dmd-'.($n + 1));
            $x->startElement('mdWrap');
            $x->writeAttribute('MDTYPE', 'DC');
            $x->startElement('xmlData');
            $x->writeRaw(preg_replace('/^<\?xml[^>]*>\s*/', '', $this->dublinCore->export($unit->loadMissing(RecordService::DESCRIBED), $view)));
            $x->endElement();
            $x->endElement();
            $x->endElement();
        }
        $x->startElement('fileSec');
        foreach ($files->flatten()->groupBy(fn ($f) => $f->use->notation) as $use => $group) {
            $x->startElement('fileGrp');
            $x->writeAttribute('USE', $use);
            foreach ($group as $file) {
                $x->startElement('file');
                $x->writeAttribute('ID', 'file-'.$file->id);
                $x->writeAttribute('MIMETYPE', $file->mime_type);
                $x->writeAttribute('SIZE', (string) $file->size_bytes);
                $x->writeAttribute('CHECKSUM', $file->sha256);
                $x->writeAttribute('CHECKSUMTYPE', 'SHA-256');
                $x->startElement('FLocat');
                $x->writeAttribute('LOCTYPE', 'URL');
                $x->writeAttribute('xlink:href', $view->user === null ? route('discover.file', $file) : route('files.show', $file));
                $x->endElement();
                $x->endElement();
            }
            $x->endElement();
        }
        $x->endElement();
        $x->startElement('structMap');
        $x->writeAttribute('TYPE', 'logical');
        $index = array_flip($ids);
        $div = function (RecordResource $unit) use (&$div, $x, $view, $below, $index, $files) {
            $x->startElement('div');
            $x->writeAttribute('TYPE', (string) $unit->level?->notation);
            $x->writeAttribute('LABEL', (string) $view->label($unit));
            $x->writeAttribute('DMDID', 'dmd-'.($index[$unit->id] + 1));
            foreach ($files[$unit->id] ?? [] as $file) {
                $x->startElement('fptr');
                $x->writeAttribute('FILEID', 'file-'.$file->id);
                $x->endElement();
            }
            foreach ($below[$unit->id] ?? [] as $child) {
                isset($index[$child->id]) && $div($child);
            }
            $x->endElement();
        };
        $div($top);
        $x->endElement();
        $x->endElement();

        return $x->outputMemory();
    }
}
