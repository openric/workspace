<?php

// OpenRiC Workspace - favourites exported (roadmap phase 19a, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's FavoritesExportService (ahg-favorites): CSV (with a byte order mark, for Excel), JSON, BibTeX,
// RIS, EAD 3 and a page to print, and PDF of the same page (Heratio's dropdown offers PDF). EndNote XML and CSL-JSON
// come from the citations. The citation formats and EAD hold the records among the favourites only, as Heratio's
// EAD does; what each says is what its owner may read.

namespace App\Research;

use App\Exchange\ExportView;
use App\Models\FavouriteFolder;
use App\Models\Institution;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use XMLWriter;

class FavouritesExport
{
    public const FORMATS = ['csv' => 'CSV (Spreadsheet)', 'json' => 'JSON', 'pdf' => 'PDF (Printable)', 'bibtex' => 'BibTeX (Reference Manager)',
        'ris' => 'RIS (Reference Manager)', 'endnote' => 'EndNote XML', 'csl' => 'CSL-JSON', 'print' => 'Print View (HTML)', 'ead' => 'EAD XML'];

    public function __construct(private Favourites $favourites, private Citations $citations) {}

    public function response(User $user, ?FavouriteFolder $folder, string $format): Response
    {
        $items = $this->favourites->browse($user, $folder?->id ?? null)->filter(fn ($i) => $i['readable'])->values();
        $name = 'favourites'.($folder ? '-'.Str::slug($folder->name) : '').'-'.now()->format('Y-m-d');
        $view = new ExportView($user, false, null, Institution::find($user->institution_id));

        return match ($format) {
            'csv' => response()->streamDownload(function () use ($items) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [__('Title'), __('Reference Code'), __('Type'), __('IRI'), __('Notes'), __('Folder'), __('Date Added')]);
                foreach ($items as $i) {
                    fputcsv($out, [$i['title'], $i['reference'], $i['kind'], $i['entity']?->getAttribute('iri') ?? $i['url'], $i['favourite']->notes,
                        $i['favourite']->folder?->name, $i['favourite']->created_at->format('Y-m-d H:i')]);
                }
                fclose($out);
            }, "$name.csv", ['Content-Type' => 'text/csv; charset=utf-8']),
            'json' => response()->json($items->map(fn ($i) => ['title' => $i['title'], 'reference_code' => $i['reference'], 'type' => $i['kind'],
                'iri' => $i['entity']?->getAttribute('iri'), 'url' => $i['url'], 'notes' => $i['favourite']->notes, 'folder' => $i['favourite']->folder?->name,
                'added' => $i['favourite']->created_at->toAtomString()])->all(), 200, ['Content-Disposition' => "attachment; filename=\"$name.json\""], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'bibtex', 'ris', 'endnote', 'csl' => response($this->citations->many($view, $this->favourites->records($items), $format), 200, [
                'Content-Type' => Citations::mime($format), 'Content-Disposition' => "attachment; filename=\"$name.".Citations::extension($format).'"']),
            'ead' => response($this->ead($view, $items, $folder, Institution::find($user->institution_id)), 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => "attachment; filename=\"$name.xml\""]),
            'print' => response($this->page($items, $folder)),
            'pdf' => response($this->pdf($this->page($items, $folder)), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"$name.pdf\""]),
            default => abort(404),
        };
    }

    public function page(Collection $items, ?FavouriteFolder $folder): string
    {
        return view('favourites.print', ['items' => $items, 'folder' => $folder])->render();
    }

    private function pdf(string $html): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    /** Heratio's minimal EAD 3: the folder as a collection, each record an item with its title, code, dates and level. */
    private function ead(ExportView $view, Collection $items, ?FavouriteFolder $folder, ?Institution $institution): string
    {
        $title = $folder?->name ?? __('Favourites');
        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('ead');
        $x->writeAttribute('xmlns', 'http://ead3.archivists.org/schema/');
        $x->writeAttribute('audience', 'external');
        $x->startElement('control');
        $x->writeElement('recordid', 'workspace-favourites-'.($folder?->id ?? 'all'));
        $x->startElement('filedesc');
        $x->startElement('titlestmt');
        $x->writeElement('titleproper', $title);
        $x->endElement();
        $x->endElement();
        $x->startElement('maintenancestatus');
        $x->writeAttribute('value', 'new');
        $x->endElement();
        $x->startElement('maintenanceagency');
        $x->writeElement('agencyname', $institution?->name ?? 'OpenRiC Workspace');
        $x->endElement();
        $x->endElement();
        $x->startElement('archdesc');
        $x->writeAttribute('level', 'collection');
        $x->startElement('did');
        $x->writeElement('unittitle', $title);
        $x->writeElement('unitdate', now()->toDateString());
        $x->endElement();
        $x->startElement('dsc');
        foreach ($items as $i) {
            if (! in_array($i['entity'], $this->favourites->records(collect([$i])), true)) {
                continue;
            }
            $facts = $this->citations->facts($view, $i['entity']);
            $x->startElement('c');
            $x->writeAttribute('level', 'item');
            $x->startElement('did');
            $x->writeElement('unittitle', $facts['title']);
            foreach (array_filter(['unitid' => $facts['identifier'], 'unitdate' => $facts['date'], 'physdesc' => $i['entity']->level?->anyLabel(['en'])]) as $element => $value) {
                $x->writeElement($element, (string) $value);
            }
            $x->endElement();
            if ($i['favourite']->notes) {
                $x->startElement('scopecontent');
                $x->writeElement('p', $i['favourite']->notes);
                $x->endElement();
            }
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }
}
