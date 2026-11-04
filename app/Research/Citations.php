<?php

// OpenRiC Workspace - a record cited for a reference manager or in a bibliography style (roadmap phase 19).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Copied from Heratio's CitationService (one record: RIS, BibTeX, EndNote XML, APA 7, MLA 9, Chicago 17) and
// CitationExportService (many records: BibTeX with unique keys, RIS, CSL-JSON), ahg-research. Changed: a record is
// read through ExportView, so a citation says only what its reader may read (a redacted field redacted, an
// unpublished creator left out); the reference code is the Workspace's; the address is the record's persistent IRI.

namespace App\Research;

use App\Exchange\ExportView;
use App\Models\RecordDoi;
use App\Models\RecordResource;
use DOMDocument;
use Illuminate\Support\Str;
use SimpleXMLElement;

class Citations
{
    public const FORMATS = ['ris' => 'RIS (Zotero, Mendeley, EndNote)', 'bibtex' => 'BibTeX', 'endnote' => 'EndNote XML', 'csl' => 'CSL-JSON',
        'apa' => 'APA 7', 'mla' => 'MLA 9', 'chicago' => 'Chicago 17', 'harvard' => 'Harvard', 'turabian' => 'Turabian', 'isadg' => 'ISAD(G)'];

    /** The formats that are a style to read, not a file for a reference manager. */
    public const STYLES = ['chicago', 'apa', 'mla', 'harvard', 'turabian', 'isadg'];

    /** The facts a citation is made of, as the view may read them. */
    public function facts(ExportView $view, RecordResource $unit): array
    {
        $unit->loadMissing('dates', 'repository');
        $first = $unit->dates->first();
        $display = $view->value($unit, 'dates-expressed') ?? $first?->expressed ?? $first?->display();
        $earliest = $unit->dates->pluck('earliest')->filter()->min();

        return [
            'id' => $unit->id,
            'title' => (string) ($view->value($unit, 'title') ?? ''),
            'creators' => $view->creators($unit)->map(fn ($r) => (string) $view->label($r->other))->filter()->unique()->values()->all(),
            'date' => $display ?: null,
            'year' => $earliest ? $earliest->format('Y') : (preg_match('/(\d{4})/', (string) $display, $m) ? $m[1] : null),
            'repository' => $unit->repository && $view->readable($unit->repository) ? $view->label($unit->repository) : null,
            'identifier' => $unit->referenceCode(),
            'extent' => $view->value($unit, 'extent'),
            // Cited by its DOI once it has a findable one, else by its persistent IRI (phase 20c).
            'doi' => $doi = RecordDoi::where('record_resource_id', $unit->id)->whereHas('state', fn ($q) => $q->where('notation', 'findable'))->value('doi'),
            'url' => $doi ? 'https://doi.org/'.$doi : $unit->iri,
            'accessed' => now(config('workspace.timezone')),
        ];
    }

    /** One record in a format. */
    public function one(ExportView $view, RecordResource $unit, string $format): string
    {
        return $this->many($view, [$unit], $format);
    }

    /** Several records in a format; BibTeX keys are kept unique. @param  iterable<RecordResource>  $units */
    public function many(ExportView $view, iterable $units, string $format): string
    {
        $facts = [];
        foreach ($units as $unit) {
            $facts[] = $this->facts($view, $unit);
        }
        $keys = [];

        return match ($format) {
            'ris' => implode("\r\n\r\n", array_map(fn ($f) => $this->ris($f), $facts)),
            'bibtex' => implode("\n\n", array_map(function ($f) use (&$keys) {
                return $this->bibtex($f, $keys);
            }, $facts)),
            'endnote' => $this->endnote($facts),
            'csl' => json_encode(array_map(fn ($f) => $this->csl($f), $facts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'apa' => implode("\n\n", array_map(fn ($f) => $this->apa($f), $facts)),
            'mla' => implode("\n\n", array_map(fn ($f) => $this->mla($f), $facts)),
            'chicago' => implode("\n\n", array_map(fn ($f) => $this->chicago($f), $facts)),
            'harvard' => implode("\n\n", array_map(fn ($f) => $this->harvard($f), $facts)),
            'turabian' => implode("\n\n", array_map(fn ($f) => $this->turabian($f), $facts)),
            'isadg' => implode("\n\n", array_map(fn ($f) => $this->isadg($f), $facts)),
            default => throw new \InvalidArgumentException(__('No such citation format.')),
        };
    }

    public static function mime(string $format): string
    {
        return match ($format) {
            'ris' => 'application/x-research-info-systems',
            'bibtex' => 'application/x-bibtex',
            'endnote' => 'application/xml',
            'csl' => 'application/vnd.citationstyles.csl+json',
            default => 'text/plain; charset=utf-8',
        };
    }

    public static function extension(string $format): string
    {
        return ['ris' => 'ris', 'bibtex' => 'bib', 'endnote' => 'xml', 'csl' => 'json'][$format] ?? 'txt';
    }

    private function ris(array $f): string
    {
        $lines = ['TY  - UNPB'];
        if ($f['title'] !== '') {
            $lines[] = 'TI  - '.$f['title'];
        }
        foreach ($f['creators'] as $a) {
            $lines[] = 'AU  - '.$a;
        }
        foreach (['PY' => $f['year'], 'DA' => $f['date'], 'PB' => $f['repository']] as $tag => $value) {
            if ($value) {
                $lines[] = "$tag  - $value";
            }
        }
        if ($f['identifier']) {
            $lines[] = 'N1  - Reference code: '.$f['identifier'];
        }
        if ($f['extent']) {
            $lines[] = 'N1  - Extent: '.$f['extent'];
        }
        $f['doi'] && $lines[] = 'DO  - '.$f['doi'];
        $lines[] = 'UR  - '.$f['url'];
        $lines[] = 'Y2  - '.$f['accessed']->format('Y-m-d');
        $lines[] = 'ER  - ';

        return implode("\r\n", array_map(fn ($l) => str_replace(["\r", "\n"], ' ', $l), $lines));
    }

    /** @param  array<string, true>  $keys  the keys already used */
    private function bibtex(array $f, array &$keys): string
    {
        // Heratio's citeKey: the first creator's surname ("Surname, Given", else the last word), else the archive, and
        // the year, lower case; a, b, ... after a key already used.
        $first = $f['creators'][0] ?? '';
        $surname = $first === '' ? '' : (str_contains($first, ',') ? trim(Str::before($first, ',')) : (string) Str::afterLast(trim($first), ' '));
        $key = strtolower((preg_replace('/[^a-z0-9]+/i', '', $surname !== '' ? $surname : (string) ($f['repository'] ?? '')) ?: 'ref').($f['year'] ?? ''));
        $candidate = $key;
        for ($suffix = 'a'; isset($keys[$candidate]); $suffix++) {
            $candidate = $key.$suffix;
        }
        $key = $candidate;
        $keys[$key] = true;
        $fields = [];
        if ($f['title'] !== '') {
            $fields[] = '  title = {'.$this->escBib($f['title']).'}';
        }
        if ($f['creators']) {
            $fields[] = '  author = {'.$this->escBib(implode(' and ', $f['creators'])).'}';
        }
        if ($f['year']) {
            $fields[] = '  year = {'.$f['year'].'}';
        }
        if ($f['repository']) {
            $fields[] = '  howpublished = {'.$this->escBib($f['repository']).'}';
        }
        if ($f['identifier']) {
            $fields[] = '  note = {Reference code: '.$this->escBib($f['identifier']).'}';
        }
        $f['doi'] && $fields[] = '  doi = {'.$f['doi'].'}';
        $fields[] = '  url = {'.$f['url'].'}';
        $fields[] = '  urldate = {'.$f['accessed']->format('Y-m-d').'}';

        return "@misc{{$key},\n".implode(",\n", $fields)."\n}";
    }

    private function escBib(string $s): string
    {
        return str_replace(['\\', '&', '%', '$', '#', '_', '{', '}', '~', '^'], ['\textbackslash{}', '\&', '\%', '\$', '\#', '\_', '\{', '\}', '\textasciitilde{}', '\textasciicircum{}'], $s);
    }

    private function endnote(array $facts): string
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><xml><records/></xml>');
        foreach ($facts as $f) {
            $rec = $xml->records->addChild('record');
            $rec->addChild('ref-type', 'Archival Material');
            $authors = $rec->addChild('contributors')->addChild('authors');
            foreach ($f['creators'] ?: array_filter([$f['repository']]) as $c) {
                $authors->addChild('author', htmlspecialchars($c, ENT_XML1));
            }
            $rec->addChild('titles')->addChild('title', htmlspecialchars($f['title'], ENT_XML1));
            if ($f['repository']) {
                $rec->addChild('publisher', htmlspecialchars($f['repository'], ENT_XML1));
            }
            if ($f['year']) {
                $rec->addChild('year', $f['year']);
            }
            if ($f['identifier']) {
                $rec->addChild('call-num', htmlspecialchars($f['identifier'], ENT_XML1));
            }
            $rec->addChild('urls')->addChild('related-urls')->addChild('url', htmlspecialchars($f['url'], ENT_XML1));
            $rec->addChild('access-date', $f['accessed']->format('Y-m-d'));
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        return (string) $dom->saveXML();
    }

    /** CSL-JSON, as Heratio's entryToCsl writes it: type manuscript, archive and archive location, issued and accessed. */
    private function csl(array $f): array
    {
        return array_filter([
            'id' => $f['id'], 'type' => 'manuscript', 'title' => $f['title'] ?: null,
            'author' => array_map(fn ($a) => str_contains($a, ',') ? ['family' => trim(Str::before($a, ',')), 'given' => trim(Str::after($a, ','))] : ['literal' => $a], $f['creators']) ?: null,
            'issued' => $f['year'] ? ['date-parts' => [[(int) $f['year']]]] : null,
            'archive' => $f['repository'], 'archive_location' => $f['identifier'], 'DOI' => $f['doi'], 'URL' => $f['url'],
            'accessed' => ['date-parts' => [[(int) $f['accessed']->format('Y'), (int) $f['accessed']->format('n'), (int) $f['accessed']->format('j')]]],
        ], fn ($v) => $v !== null);
    }

    private function apa(array $f): string
    {
        $authors = $f['creators'] ? implode(', ', array_map(function ($c) {
            $bits = explode(',', $c, 2);

            return count($bits) === 2 ? trim($bits[0]).', '.mb_substr(trim($bits[1]), 0, 1).'.' : trim($c);
        }, $f['creators'])) : ($f['repository'] ?: 'Anon.');

        return implode(' ', array_filter([$authors, '('.($f['year'] ?? 'n.d.').').', $f['title'] !== '' ? $f['title'].'.' : null,
            $f['identifier'] ? '['.$f['identifier'].'].' : null, $f['repository'] ? $f['repository'].'.' : null,
            'Retrieved '.$f['accessed']->format('j F Y').', from '.$f['url']]));
    }

    private function mla(array $f): string
    {
        return implode(' ', array_filter([($f['creators'][0] ?? ($f['repository'] ?: 'Anon')).'.', $f['title'] !== '' ? '"'.$f['title'].'."' : null,
            ($f['date'] ?? $f['year']) ? ($f['date'] ?? $f['year']).',' : null, $f['identifier'] ? $f['identifier'].',' : null,
            $f['repository'] ? $f['repository'].',' : null, $f['url'].'.', 'Accessed '.$f['accessed']->format('j F Y').'.']));
    }

    private function chicago(array $f): string
    {
        return implode(' ', array_filter([($f['creators'] ? implode('; ', $f['creators']) : ($f['repository'] ?: 'Anon')).',', $f['title'] !== '' ? '"'.$f['title'].',"' : null,
            ($f['date'] ?? $f['year']) ? ($f['date'] ?? $f['year']).',' : null, $f['identifier'] ? $f['identifier'].',' : null,
            $f['repository'] ? $f['repository'].',' : null, $f['url'].' (accessed '.$f['accessed']->format('j F Y').').']));
    }

    /** Heratio's formatHarvard: the repository, (the year), 'the title', [the reference code]. */
    private function harvard(array $f): string
    {
        return implode(' ', array_filter([$f['repository'], '('.($f['year'] ?? 'n.d.').')', $f['title'] !== '' ? "'".$f['title']."'" : null,
            $f['identifier'] ? '['.$f['identifier'].']' : null])).'.';
    }

    /** Heratio's formatTurabian. */
    private function turabian(array $f): string
    {
        return implode(' ', array_filter([$f['title'] !== '' ? '"'.$f['title'].',"' : null, ($f['date'] ?? $f['year']) ? ($f['date'] ?? $f['year']).',' : null,
            $f['identifier'] ? $f['identifier'].',' : null, $f['repository']])).'.';
    }

    /** Heratio's formatIsadg: repository, reference code, title, dates, extent. */
    private function isadg(array $f): string
    {
        return implode('. ', array_filter([$f['repository'], $f['identifier'], $f['title'] ?: null, $f['date'] ?? $f['year'], $f['extent']])).'.';
    }
}
