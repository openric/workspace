<?php

// OpenRiC Workspace - a built report as CSV, XLSX, PDF or Word (roadmap phase 8), after PSIS's ahgReportBuilderPlugin
// (Export/PdfExporter with Dompdf, Export/WordExporter with PhpWord) and its XLSX and CSV export with PhpSpreadsheet.
// Each draws the same document (ReportBuilder::document): the title and description, then each section in order.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

final class ReportExport
{
    /** format => [file extension, media type] */
    public const FORMATS = [
        'csv' => ['csv', 'text/csv; charset=UTF-8'],
        'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pdf' => ['pdf', 'application/pdf'],
        'docx' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];

    /** The table header colour of generated documents. */
    private const HEADER = '10373E';

    /** The document in a format, as the bytes of the file. */
    public function render(array $document, string $format): string
    {
        return match ($format) {
            'csv' => $this->csv($document),
            'xlsx' => $this->xlsx($document),
            'pdf' => $this->pdf($document),
            'docx' => $this->docx($document),
        };
    }

    /** CSV holds tables only: each table or counts section, under its title, with a blank line between. */
    private function csv(array $document): string
    {
        $out = fopen('php://temp', 'r+');
        $first = true;
        foreach ($this->tables($document) as [$title, $headings, $rows]) {
            if (! $first) {
                fputcsv($out, []);
            }
            $first = false;
            if ($title !== '') {
                fputcsv($out, [$title]);
            }
            fputcsv($out, $headings);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** A sheet for the report (title, description, summary and text), then one for each table or counts section. */
    private function xlsx(array $document): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($this->sheetTitle(__('Report'), []));
        $lines = [[$document['title']], [$document['description']], [__('Generated').': '.$document['generated']], []];
        foreach ($document['sections'] as $section) {
            if (in_array($section['type'], ['summary', 'text'], true)) {
                $lines[] = [$section['title']];
                foreach ($section['rows'] ?? array_map(fn ($p) => [$p], $section['paragraphs'] ?? []) as $row) {
                    $lines[] = $row;
                }
                $lines[] = [];
            }
        }
        $sheet->fromArray($lines, null, 'A1', true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $used = [$sheet->getTitle()];
        foreach ($this->tables($document) as [$title, $headings, $rows]) {
            $sheet = $book->createSheet()->setTitle($this->sheetTitle($title ?: __('Table'), $used));
            $used[] = $sheet->getTitle();
            $sheet->fromArray([$headings, ...$rows], null, 'A1', true);
            $last = $sheet->getHighestColumn();
            $sheet->getStyle("A1:{$last}1")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::HEADER]]]);
            $sheet->freezePane('A2');
            foreach (range(1, count($headings)) as $i) {
                $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
            }
        }
        $book->setActiveSheetIndex(0);
        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    private function pdf(array $document): string
    {
        $options = new Options;
        // Nothing outside the document is fetched: no remote fonts, images or files.
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.builder.print', ['document' => $document])->render());
        $pdf->setPaper('A4', $this->wide($document) ? 'landscape' : 'portrait');
        $pdf->render();

        return (string) $pdf->output();
    }

    private function docx(array $document): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);
        $word->setDefaultParagraphStyle(['spaceAfter' => 120]);
        $word->addTitleStyle(1, ['size' => 18, 'bold' => true]);
        $word->addTitleStyle(2, ['size' => 14, 'bold' => true]);
        $word->addTableStyle('report', ['borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 60]);
        $page = $word->addSection($this->wide($document) ? ['orientation' => 'landscape'] : []);
        $page->addTitle($document['title'], 1);
        if ($document['description'] !== '') {
            $page->addText($document['description']);
        }
        $page->addText($document['source'].' · '.$document['status'].' · '.__('Generated').' '.$document['generated'], ['size' => 9, 'color' => '555555']);
        foreach ($document['sections'] as $section) {
            if ($section['title'] !== '') {
                $page->addTitle($section['title'], 2);
            }
            if ($section['type'] === 'text') {
                foreach ($section['paragraphs'] as $paragraph) {
                    $page->addText($paragraph, [], ['alignment' => 'both']);
                }

                continue;
            }
            $headings = $section['headings'] ?? null;
            $table = $page->addTable('report');
            if ($headings) {
                $table->addRow(null, ['tblHeader' => true]);
                foreach ($headings as $heading) {
                    $table->addCell(null, ['bgColor' => self::HEADER])->addText($heading, ['bold' => true, 'color' => 'FFFFFF']);
                }
            }
            foreach ($section['rows'] as $row) {
                $table->addRow();
                foreach ($row as $cell) {
                    $table->addCell()->addText($cell);
                }
            }
        }
        if ($document['cut']) {
            $page->addText(__('Only the first :limit entities are included.', ['limit' => ReportBuilder::ROW_LIMIT]), ['italic' => true]);
        }
        ob_start();
        IOFactory::createWriter($word, 'Word2007')->save('php://output');

        return (string) ob_get_clean();
    }

    /** @return list<array{0: string, 1: list<string>, 2: list<list<string>>}> each table or counts section as title, headings and rows */
    private function tables(array $document): array
    {
        return array_values(array_map(fn ($s) => [$s['title'], $s['headings'], $s['rows']],
            array_filter($document['sections'], fn ($s) => in_array($s['type'], ['table', 'counts'], true))));
    }

    /** A table of more than five columns is printed across the page. */
    private function wide(array $document): bool
    {
        return collect($document['sections'])->contains(fn ($s) => $s['type'] === 'table' && count($s['headings']) > 5);
    }

    /** A sheet title Excel accepts: at most 31 characters, none of []:*?/\, and not one already used. */
    private function sheetTitle(string $title, array $used): string
    {
        $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $title)) ?: __('Table'), 0, 28);
        $name = $base;
        for ($i = 2; in_array($name, $used, true); $i++) {
            $name = $base.' '.$i;
        }

        return $name;
    }
}
