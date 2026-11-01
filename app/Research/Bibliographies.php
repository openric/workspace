<?php

// OpenRiC Workspace - bibliographies (roadmap phase 19c, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's BibliographyService and ResearchBibliographiesController (ahg-research): a bibliography in a
// citation style, its entries (records of the catalogue, or books, articles and other works, with Heratio's fields),
// entries added from records or favourites, typed in, or imported from BibTeX, RIS or a pasted citation (Heratio's
// parsers); exported as RIS, BibTeX, Zotero RDF, Mendeley JSON or CSL-JSON, or written out in its style; shared by a
// link (shown once, kept as a hash). A record's entry takes its facts as its owner may read them.

namespace App\Research;

use App\Audit\AuditLog;
use App\Exchange\ExportView;
use App\Models\Bibliography;
use App\Models\BibliographyEntry;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SimpleXMLElement;

class Bibliographies
{
    public const EXPORTS = ['ris' => 'RIS', 'bibtex' => 'BibTeX', 'zotero' => 'Zotero RDF', 'mendeley' => 'Mendeley JSON', 'csl' => 'CSL-JSON', 'text' => 'Bibliography (text)'];

    private const FIELDS = ['title', 'authors', 'date', 'publisher', 'container_title', 'volume', 'issue', 'pages', 'doi', 'url', 'archive_name', 'archive_location',
        'collection_title', 'box', 'folder', 'notes'];

    private const RIS_TYPES = ['archival' => 'UNPB', 'book' => 'BOOK', 'article' => 'JOUR', 'chapter' => 'CHAP', 'thesis' => 'THES', 'website' => 'ELEC', 'other' => 'GEN'];

    private const BIBTEX_TYPES = ['archival' => 'misc', 'book' => 'book', 'article' => 'article', 'chapter' => 'incollection', 'thesis' => 'phdthesis', 'website' => 'misc', 'other' => 'misc'];

    public function __construct(private Citations $citations) {}

    public function save(User $user, array $data, ?Bibliography $bibliography = null): Bibliography
    {
        if ($bibliography) {
            $this->mustOwn($user, $bibliography);
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the bibliography a name.'));
        }
        $fields = ['name' => trim($data['name']), 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'citation_style_id' => $this->concept('citation-style', $data['citation_style'] ?? 'chicago')];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $bibliography ? tap($bibliography)->update($fields)
            : Bibliography::create($fields + ['institution_id' => $user->institution_id, 'user_id' => $user->id])));
    }

    public function delete(User $user, Bibliography $bibliography): void
    {
        $this->mustOwn($user, $bibliography);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $bibliography->delete()));
    }

    /** An entry typed in (Heratio's addEntry). */
    public function addEntry(User $user, Bibliography $bibliography, array $data): BibliographyEntry
    {
        $this->mustOwn($user, $bibliography);
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the title of the work.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => BibliographyEntry::create($this->entryFields($data) + ['bibliography_id' => $bibliography->id,
            'entry_type_id' => $this->concept('bibliography-entry-type', $data['entry_type'] ?? 'other'), 'position' => (int) $bibliography->entries()->max('position') + 1])));
    }

    /** A record of the catalogue as an entry (Heratio's addEntryFromObject): its facts as the owner may read them. */
    public function addRecord(User $user, Bibliography $bibliography, RecordResource $unit): BibliographyEntry
    {
        $this->mustOwn($user, $bibliography);
        if ($unit->institution_id !== $bibliography->institution_id || ! EntityKinds::readable($user, $unit)) {
            throw new InvalidArgumentException(__('A record you asked for cannot be found.'));
        }
        if ($bibliography->entries()->where('record_resource_id', $unit->id)->exists()) {
            throw new InvalidArgumentException(__('That record is in the bibliography already.'));
        }
        $facts = $this->citations->facts(new ExportView($user, false, null, Institution::find($unit->institution_id)), $unit);

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => BibliographyEntry::create(['bibliography_id' => $bibliography->id, 'record_resource_id' => $unit->id,
            'entry_type_id' => $this->concept('bibliography-entry-type', 'archival'), 'title' => $facts['title'] ?: EntityKinds::label($unit), 'authors' => implode('; ', $facts['creators']) ?: null,
            'date' => $facts['date'] ?? $facts['year'], 'archive_name' => $facts['repository'], 'archive_location' => $facts['identifier'], 'url' => $facts['url'],
            'accessed_on' => now()->toDateString(), 'position' => (int) $bibliography->entries()->max('position') + 1])));
    }

    public function updateEntry(User $user, BibliographyEntry $entry, array $data): void
    {
        $this->mustOwn($user, $entry->bibliography);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $entry->update($this->entryFields($data + $entry->only(self::FIELDS))
            + ['entry_type_id' => $this->concept('bibliography-entry-type', $data['entry_type'] ?? $entry->entryType->notation)])));
    }

    public function removeEntry(User $user, BibliographyEntry $entry): void
    {
        $this->mustOwn($user, $entry->bibliography);
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $entry->delete()));
    }

    /**
     * Import BibTeX, RIS, or one pasted citation (Heratio's importBibTeX, importRIS and importFromCitation).
     *
     * @return int how many entries were added
     */
    public function import(User $user, Bibliography $bibliography, string $text, string $format): int
    {
        $this->mustOwn($user, $bibliography);
        $entries = match ($format) {
            'bibtex' => $this->parseBibTeX($text),
            'ris' => $this->parseRis($text),
            'citation' => [$this->parseCitation($text)],
            default => throw new InvalidArgumentException(__('Choose BibTeX, RIS or a citation.')),
        };
        $entries = array_values(array_filter($entries, fn ($e) => trim((string) ($e['title'] ?? '')) !== ''));
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($bibliography, $entries) {
            $position = (int) $bibliography->entries()->max('position');
            foreach ($entries as $entry) {
                BibliographyEntry::create($this->entryFields($entry) + ['bibliography_id' => $bibliography->id,
                    'entry_type_id' => $this->concept('bibliography-entry-type', $entry['entry_type'] ?? 'other'), 'position' => ++$position]);
            }
        }));

        return count($entries);
    }

    /** Share it by a link (shown once), or stop. @return ?string the token */
    public function share(User $user, Bibliography $bibliography, bool $on): ?string
    {
        $this->mustOwn($user, $bibliography);
        $token = $on ? Str::random(48) : null;
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $bibliography->update(['is_public' => $on, 'share_token_hash' => $token ? hash('sha256', $token) : null])));

        return $token;
    }

    public function shared(string $token): ?Bibliography
    {
        return Bibliography::where(['share_token_hash' => hash('sha256', $token), 'is_public' => true])->first();
    }

    public function export(Bibliography $bibliography, string $format): string
    {
        $entries = $bibliography->entries()->with('entryType')->get();

        return match ($format) {
            'ris' => $entries->map(fn ($e) => $this->ris($e))->join("\r\n\r\n"),
            'bibtex' => $this->bibtex($entries),
            'zotero' => $this->zotero($entries),
            'mendeley' => json_encode($entries->map(fn ($e) => $this->mendeley($e))->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'csl' => json_encode($entries->map(fn ($e) => $this->csl($e))->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'text' => $entries->map(fn ($e) => $this->styled($e, $bibliography->citationStyle->notation))->join("\n\n"),
            default => throw new InvalidArgumentException(__('No such citation format.')),
        };
    }

    public static function mime(string $format): string
    {
        return ['ris' => 'application/x-research-info-systems', 'bibtex' => 'application/x-bibtex', 'zotero' => 'application/rdf+xml', 'mendeley' => 'application/json',
            'csl' => 'application/vnd.citationstyles.csl+json'][$format] ?? 'text/plain; charset=utf-8';
    }

    public static function extension(string $format): string
    {
        return ['ris' => 'ris', 'bibtex' => 'bib', 'zotero' => 'rdf', 'mendeley' => 'json', 'csl' => 'json'][$format] ?? 'txt';
    }

    /** An entry written out in a style. */
    public function styled(BibliographyEntry $e, string $style): string
    {
        $authors = $e->authors ? str_replace(';', ',', $e->authors) : '';
        $year = preg_match('/(\d{4})/', (string) $e->date, $m) ? $m[1] : null;
        $where = collect([$e->archive_name, $e->archive_location, $e->collection_title, $e->box ? __('box :n', ['n' => $e->box]) : null, $e->folder ? __('folder :n', ['n' => $e->folder]) : null])->filter()->join(', ');
        $container = collect([$e->container_title, $e->volume ? 'vol. '.$e->volume : null, $e->issue ? 'no. '.$e->issue : null, $e->pages ? 'pp. '.$e->pages : null])->filter()->join(', ');

        return trim(match ($style) {
            'apa' => collect([$authors ?: null, '('.($year ?? 'n.d.').').', $e->title.'.', $container ?: null, $e->publisher, $where ?: null, $e->doi ? 'https://doi.org/'.$e->doi : $e->url])->filter()->join(' '),
            'mla' => collect([$authors ? $authors.'.' : null, '"'.$e->title.'."', $container ?: null, $e->publisher, $e->date, $where ?: null, $e->url])->filter()->join(' '),
            'harvard' => collect([$authors ?: $e->archive_name, '('.($year ?? 'n.d.').')', "'".$e->title."'", $container ?: null, $e->archive_location ? '['.$e->archive_location.']' : null, $e->url])->filter()->join(' ').'.',
            'isadg' => collect([$e->archive_name, $e->archive_location, $e->title, $e->date, $e->collection_title])->filter()->join('. ').'.',
            default => ($rest = collect([$container ?: null, $e->publisher, $e->date, $where ?: null, $e->url])->filter()->join(', ')) === ''
                ? ($authors ? $authors.', ' : '').'"'.$e->title.'."'
                : ($authors ? $authors.', ' : '').'"'.$e->title.'," '.$rest.'.',
        });
    }

    /** Heratio's parseBibTeX. @return list<array> */
    public function parseBibTeX(string $text): array
    {
        $types = ['article' => 'article', 'book' => 'book', 'inbook' => 'chapter', 'incollection' => 'chapter', 'inproceedings' => 'article', 'mastersthesis' => 'thesis',
            'phdthesis' => 'thesis', 'misc' => 'other', 'techreport' => 'other', 'unpublished' => 'other', 'online' => 'website'];
        preg_match_all('/@(\w+)\s*\{([^,]*),\s*((?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*)\}/s', $text, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $match) {
            $fields = [];
            preg_match_all('/(\w+)\s*=\s*(?:\{((?:[^{}]|\{[^{}]*\})*)\}|"([^"]*)"|(\d+))/s', $match[3], $found, PREG_SET_ORDER);
            foreach ($found as $f) {
                $fields[strtolower($f[1])] = trim(str_replace(['{', '}'], '', ($f[2] ?? '') !== '' ? $f[2] : (($f[3] ?? '') !== '' ? $f[3] : ($f[4] ?? ''))));
            }
            $out[] = ['entry_type' => $types[strtolower($match[1])] ?? 'other', 'title' => $fields['title'] ?? '', 'authors' => str_replace(' and ', '; ', $fields['author'] ?? ''),
                'date' => $fields['year'] ?? ($fields['date'] ?? ''), 'publisher' => $fields['publisher'] ?? '', 'container_title' => $fields['journal'] ?? ($fields['booktitle'] ?? ''),
                'volume' => $fields['volume'] ?? '', 'issue' => $fields['number'] ?? '', 'pages' => $fields['pages'] ?? '', 'doi' => $fields['doi'] ?? '', 'url' => $fields['url'] ?? '',
                'notes' => $fields['note'] ?? ''];
        }

        return $out;
    }

    /** Heratio's parseRIS. @return list<array> */
    public function parseRis(string $text): array
    {
        $types = ['JOUR' => 'article', 'BOOK' => 'book', 'CHAP' => 'chapter', 'THES' => 'thesis', 'RPRT' => 'other', 'CONF' => 'article', 'ELEC' => 'website', 'GEN' => 'other',
            'MGZN' => 'article', 'UNPB' => 'archival'];
        $out = [];
        $current = null;
        $authors = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (! preg_match('/^([A-Z][A-Z0-9])\s+-\s?(.*)$/', trim($line), $m)) {
                continue;
            }
            [$tag, $value] = [$m[1], trim($m[2])];
            if ($tag === 'TY') {
                $current = ['entry_type' => $types[$value] ?? 'other', 'title' => '', 'pages' => ''];
                $authors = [];
            } elseif ($tag === 'ER') {
                if ($current) {
                    $out[] = $current + ['authors' => implode('; ', $authors)];
                }
                $current = null;
            } elseif ($current !== null) {
                match ($tag) {
                    'TI', 'T1' => $current['title'] = $value,
                    'AU', 'A1' => $authors[] = $value,
                    'PY', 'Y1', 'DA' => $current['date'] ??= $value,
                    'PB' => $current['publisher'] = $value,
                    'JO', 'JF', 'T2' => $current['container_title'] = $value,
                    'VL' => $current['volume'] = $value,
                    'IS' => $current['issue'] = $value,
                    'SP' => $current['pages'] = $value,
                    'EP' => $current['pages'] .= '-'.$value,
                    'DO' => $current['doi'] = $value,
                    'UR', 'L1' => $current['url'] = $value,
                    'AN' => $current['archive_name'] = $value,
                    'N1' => $current['notes'] = trim(($current['notes'] ?? '')."\n".$value),
                    default => null,
                };
            }
        }
        if ($current) {
            $out[] = $current + ['authors' => implode('; ', $authors)];
        }

        return $out;
    }

    /** Heratio's importFromCitation: a web address, a year and a quoted title picked out of a pasted citation. */
    public function parseCitation(string $text): array
    {
        $text = trim($text);
        $title = preg_match('/"([^"]+)"/', $text, $m) || preg_match('/<(?:em|i)>([^<]+)<\/(?:em|i)>/i', $text, $m) ? $m[1] : mb_substr($text, 0, 200);

        return ['entry_type' => 'other', 'title' => trim($title, ' ,.'), 'url' => preg_match('/(https?:\/\/[^\s<>"]+)/i', $text, $u) ? rtrim($u[1], '.,') : null,
            'date' => preg_match('/\b(1[5-9]\d{2}|20\d{2})\b/', $text, $y) ? $y[1] : null, 'notes' => 'Imported from citation: '.mb_substr($text, 0, 200)];
    }

    private function ris(BibliographyEntry $e): string
    {
        $lines = ['TY  - '.(self::RIS_TYPES[$e->entryType->notation] ?? 'GEN'), 'TI  - '.$e->title];
        foreach (array_filter(array_map('trim', explode(';', (string) $e->authors))) as $a) {
            $lines[] = 'AU  - '.$a;
        }
        foreach (['PY' => $e->date, 'PB' => $e->publisher, 'T2' => $e->container_title, 'VL' => $e->volume, 'IS' => $e->issue, 'SP' => $e->pages, 'DO' => $e->doi, 'UR' => $e->url,
            'AN' => $e->archive_name] as $tag => $value) {
            if ($value) {
                $lines[] = "$tag  - $value";
            }
        }
        foreach (['Archive location' => $e->archive_location, 'Collection' => $e->collection_title, 'Box' => $e->box, 'Folder' => $e->folder] as $label => $value) {
            if ($value) {
                $lines[] = "N1  - $label: $value";
            }
        }
        if ($e->notes) {
            $lines[] = 'N1  - '.$e->notes;
        }
        if ($e->accessed_on) {
            $lines[] = 'Y2  - '.$e->accessed_on->format('Y-m-d');
        }
        $lines[] = 'ER  - ';

        return implode("\r\n", array_map(fn ($l) => str_replace(["\r", "\n"], ' ', $l), $lines));
    }

    private function bibtex($entries): string
    {
        $keys = [];
        $esc = fn (string $s) => str_replace(['\\', '&', '%', '$', '#', '_', '{', '}'], ['\textbackslash{}', '\&', '\%', '\$', '\#', '\_', '\{', '\}'], $s);

        return $entries->map(function (BibliographyEntry $e) use (&$keys, $esc) {
            $first = trim(explode(';', (string) $e->authors)[0] ?? '');
            $surname = $first === '' ? '' : (str_contains($first, ',') ? trim(Str::before($first, ',')) : (string) Str::afterLast($first, ' '));
            $key = strtolower((preg_replace('/[^a-z0-9]+/i', '', $surname ?: (string) $e->archive_name) ?: 'ref').(preg_match('/(\d{4})/', (string) $e->date, $m) ? $m[1] : ''));
            $candidate = $key;
            for ($suffix = 'a'; isset($keys[$candidate]); $suffix++) {
                $candidate = $key.$suffix;
            }
            $keys[$candidate] = true;
            $fields = array_filter(['title' => $e->title, 'author' => $e->authors ? str_replace('; ', ' and ', $e->authors) : null, 'year' => preg_match('/(\d{4})/', (string) $e->date, $m) ? $m[1] : null,
                'publisher' => $e->publisher, 'journal' => $e->entryType->notation === 'article' ? $e->container_title : null, 'booktitle' => $e->entryType->notation === 'chapter' ? $e->container_title : null,
                'volume' => $e->volume, 'number' => $e->issue, 'pages' => $e->pages, 'doi' => $e->doi, 'url' => $e->url, 'howpublished' => $e->archive_name,
                'note' => collect([$e->archive_location, $e->collection_title, $e->notes])->filter()->join('; ') ?: null]);

            return '@'.(self::BIBTEX_TYPES[$e->entryType->notation] ?? 'misc')."{{$candidate},\n".collect($fields)->map(fn ($v, $k) => "  $k = {".$esc((string) $v).'}')->join(",\n")."\n}";
        })->join("\n\n");
    }

    /** Heratio's exportZoteroRDF. */
    private function zotero($entries): string
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:z="http://www.zotero.org/namespaces/export#" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:bib="http://purl.org/net/biblio#"/>');
        foreach ($entries as $e) {
            $item = $xml->addChild('bib:Document', null, 'http://purl.org/net/biblio#');
            $item->addAttribute('rdf:about', '#item_'.$e->id, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
            $item->addChild('dc:title', htmlspecialchars($e->title, ENT_XML1), 'http://purl.org/dc/elements/1.1/');
            foreach (array_filter(array_map('trim', explode(';', (string) $e->authors))) as $a) {
                $item->addChild('dc:creator', htmlspecialchars($a, ENT_XML1), 'http://purl.org/dc/elements/1.1/');
            }
            foreach (['dc:date' => $e->date, 'dc:identifier' => $e->url, 'dc:source' => $e->archive_name, 'dc:publisher' => $e->publisher] as $tag => $value) {
                if ($value) {
                    $item->addChild($tag, htmlspecialchars($value, ENT_XML1), 'http://purl.org/dc/elements/1.1/');
                }
            }
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        return (string) $dom->saveXML();
    }

    /** Heratio's exportMendeleyJSON. */
    private function mendeley(BibliographyEntry $e): array
    {
        return array_filter(['type' => ['archival' => 'generic', 'book' => 'book', 'article' => 'journal', 'chapter' => 'book_section', 'thesis' => 'thesis', 'website' => 'web_page'][$e->entryType->notation] ?? 'generic',
            'title' => $e->title, 'authors' => array_values(array_map(fn ($a) => ['last_name' => trim(Str::before($a, ',')), 'first_name' => trim(str_contains($a, ',') ? Str::after($a, ',') : '')],
                array_filter(array_map('trim', explode(';', (string) $e->authors))))) ?: null,
            'year' => preg_match('/(\d{4})/', (string) $e->date, $m) ? (int) $m[1] : null, 'source' => $e->container_title ?? $e->archive_name, 'publisher' => $e->publisher,
            'volume' => $e->volume, 'issue' => $e->issue, 'pages' => $e->pages, 'websites' => $e->url ? [$e->url] : null, 'identifiers' => $e->doi ? ['doi' => $e->doi] : null, 'notes' => $e->notes]);
    }

    private function csl(BibliographyEntry $e): array
    {
        return $e->csl_data ?: array_filter(['id' => $e->id,
            'type' => ['archival' => 'manuscript', 'book' => 'book', 'article' => 'article-journal', 'chapter' => 'chapter', 'thesis' => 'thesis', 'website' => 'webpage'][$e->entryType->notation] ?? 'document',
            'title' => $e->title, 'author' => array_values(array_map(fn ($a) => str_contains($a, ',') ? ['family' => trim(Str::before($a, ',')), 'given' => trim(Str::after($a, ','))] : ['literal' => $a],
                array_filter(array_map('trim', explode(';', (string) $e->authors))))) ?: null,
            'issued' => preg_match('/(\d{4})/', (string) $e->date, $m) ? ['date-parts' => [[(int) $m[1]]]] : null, 'publisher' => $e->publisher, 'container-title' => $e->container_title,
            'volume' => $e->volume, 'issue' => $e->issue, 'page' => $e->pages, 'DOI' => $e->doi, 'URL' => $e->url, 'archive' => $e->archive_name, 'archive_location' => $e->archive_location]);
    }

    private function entryFields(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = ($v = trim((string) ($data[$f] ?? ''))) === '' ? null : mb_substr($v, 0, 5000);
        }
        $out['title'] ??= __('Untitled');
        $out['accessed_on'] = ($data['accessed_on'] ?? '') ?: null;

        return $out;
    }

    private function mustOwn(User $user, Bibliography $bibliography): void
    {
        if ($bibliography->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
