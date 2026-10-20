<?php

// OpenRiC Workspace - read units of description from an EAD 2002 or EAD3 finding aid.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Written for the Workspace from the two tag libraries and the ISAD(G)
// crosswalk, the reverse of App\Exchange\Ead, so a finding aid the Workspace
// exported reads back as it was: element by element, with the persistent IRIs
// of the units, creators and access points it names. Elements are found by
// their local names, so a file with or without the EAD namespace reads the
// same. Every element the import does not read is listed in the report.

namespace App\Exchange\Import;

use App\Description\Bcp47;
use App\Exchange\Ead;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

class EadReader
{
    /** EAD narrative elements and the ISAD(G) element (notation) each is read into. */
    private const NARRATIVE = [
        'bioghist' => 'creator-history', 'custodhist' => 'archival-history', 'acqinfo' => 'acquisition', 'scopecontent' => 'scope-and-content',
        'appraisal' => 'appraisal', 'accruals' => 'accruals', 'arrangement' => 'arrangement', 'accessrestrict' => 'conditions-governing-access',
        'userestrict' => 'conditions-governing-reproduction', 'phystech' => 'physical-characteristics', 'otherfindaid' => 'finding-aids-note',
        'originalsloc' => 'location-of-originals', 'altformavail' => 'location-of-copies', 'relatedmaterial' => 'related-units',
        'separatedmaterial' => 'related-units', 'bibliography' => 'publication-note', 'odd' => 'note',
    ];

    /** processinfo holds three ISAD(G) 3.7 elements; encodinganalog tells which, else the archivist's note. */
    private const PROCESSINFO = ['3.7.1' => 'archivists-note', '3.7.2' => 'rules-or-conventions', '3.7.3' => 'dates-of-descriptions'];

    private const NAMES = ['persname' => 'person', 'famname' => 'family', 'corpname' => 'corporate-body', 'name' => null];

    private Report $report;

    private string $language;

    /** @var list<UnitDraft> */
    private array $drafts = [];

    public ?string $eadid = null;

    /** @return list<UnitDraft> each unit before the units inside it */
    public function read(string $content, string $language, Report $report): array
    {
        $this->report = $report;
        $this->language = $language;
        $this->drafts = [];
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($content, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $document->documentElement?->localName !== 'ead') {
            throw new InvalidArgumentException('the file is not an EAD finding aid'.($errors ? ': '.trim($errors[0]->message) : ''));
        }
        $root = $document->documentElement;
        $ead3 = $root->namespaceURI === Ead::EAD3 || $this->child($root, 'control') !== null;
        $header = $this->child($root, $ead3 ? 'control' : 'eadheader');
        $this->eadid = $this->text($this->child($header, $ead3 ? 'recordid' : 'eadid'));
        $archdesc = $this->child($root, 'archdesc') ?? throw new InvalidArgumentException('the finding aid has no archdesc');
        $top = $this->unit($archdesc, null, 'archdesc', '');
        $rules = $ead3 ? $this->text($this->child($this->child($header, 'conventiondeclaration'), 'citation'))
            : $this->text($this->child($this->child($header, 'profiledesc'), 'descrules'));
        if ($rules !== null) {
            $top->value($this->language, 'rules-or-conventions', $rules);
        }

        return $this->drafts;
    }

    private function unit(DOMElement $node, ?UnitDraft $parent, string $location, string $path): UnitDraft
    {
        $draft = new UnitDraft;
        $this->drafts[] = $draft;
        $draft->parentKey = $parent?->key;
        $draft->location = $location;
        $level = $node->getAttribute('level');
        $draft->level = $level === 'otherlevel' ? ($node->getAttribute('otherlevel') ?: null) : ($level ?: null);
        $did = $this->child($node, 'did');
        if ($did !== null) {
            $this->did($draft, $did);
        }
        $title = $draft->values[$this->language]['title'] ?? null;
        $draft->location = $location.($title ? ' "'.mb_substr($title, 0, 60).'"' : '');
        $path .= '/'.($draft->identifier ?? $title ?? 'untitled');
        // The persistent IRI where the Workspace exported it; else the component's id; else its path.
        $draft->key = $draft->iri ?? ($node->getAttribute('id') !== '' ? 'id:'.$node->getAttribute('id') : 'path:'.$path);
        $draft->stableKey = $draft->iri !== null || $node->getAttribute('id') !== '' || $draft->identifier !== null;
        $this->content($draft, $node);

        $children = [];
        foreach ($this->elements($node) as $child) {
            if ($child->localName === 'dsc') {
                foreach ($this->elements($child) as $component) {
                    if ($this->isComponent($component)) {
                        $children[] = $component;
                    }
                }
            } elseif ($this->isComponent($child)) {
                $children[] = $child;
            }
        }
        foreach ($children as $child) {
            $this->unit($child, $draft, $child->localName, $path);
        }

        return $draft;
    }

    private function did(UnitDraft $draft, DOMElement $did): void
    {
        foreach ($this->elements($did) as $element) {
            $text = $this->text($element);
            switch ($element->localName) {
                case 'head':
                    break;
                case 'unitid':
                    $type = $element->getAttribute('type') ?: $element->getAttribute('localtype');
                    if ($type === 'persistent-iri') {
                        $draft->iri = $text;
                    } elseif ($type === '' || $type === 'local') {
                        $draft->identifier ??= $text;
                    } elseif ($text !== null) {
                        $draft->identifiers[] = ['type' => $type, 'value' => $text];
                    }
                    break;
                case 'unittitle':
                    $draft->value($this->lang($element, $draft), 'title', $text);
                    break;
                case 'unitdate':
                    $this->date($draft, $element->getAttribute('normal') ?: null, $text, $element->getAttribute('datechar'), $this->lang($element, $draft));
                    break;
                case 'unitdatestructured':
                    $this->structuredDate($draft, $element);
                    break;
                case 'physdesc':
                case 'physdescstructured':
                case 'physdescset':
                    $draft->value($this->lang($element, $draft), 'extent', $text);
                    break;
                case 'langmaterial':
                    $draft->value($this->lang($element, $draft), 'languages-of-material', $text);
                    break;
                case 'repository':
                    $draft->repositoryName = $text;
                    break;
                case 'origination':
                    foreach ($this->names($element) as $name) {
                        $role = mb_strtolower($name['role']);
                        $draft->creators[] = ['name' => $name['name'], 'type' => $name['type'], 'iri' => $name['iri'],
                            'relation' => str_contains($role, 'accumulat') || str_contains($role, 'collector') ? 'accumulation' : 'creation', 'history' => null];
                    }
                    break;
                case 'note':
                case 'didnote':
                    $draft->value($this->lang($element, $draft), 'note', $text);
                    break;
                default:
                    $this->report->issue($draft->location ?: 'did', $element->localName, $text, $this->why($element->localName), 'left-out');
            }
        }
    }

    /** The narrative elements, access points and groups of them below a unit's did. */
    private function content(UnitDraft $draft, DOMElement $node): void
    {
        foreach ($this->elements($node) as $element) {
            $name = $element->localName;
            if (in_array($name, ['did', 'dsc', 'head'], true) || $this->isComponent($element)) {
                continue;
            }
            if ($name === 'descgrp') {
                $this->content($draft, $element);
            } elseif ($name === 'controlaccess') {
                $this->accessPoints($draft, $element);
            } elseif ($name === 'processinfo') {
                $notation = self::PROCESSINFO[$element->getAttribute('encodinganalog')] ?? null;
                $head = mb_strtolower((string) $this->text($this->child($element, 'head')));
                $notation ??= match (true) {
                    str_contains($head, 'rules') => 'rules-or-conventions',
                    str_contains($head, 'date') => 'dates-of-descriptions',
                    default => 'archivists-note',
                };
                $draft->value($this->lang($element, $draft), $notation, $this->narrative($element, $draft));
            } elseif (isset(self::NARRATIVE[$name])) {
                $draft->value($this->lang($element, $draft), self::NARRATIVE[$name], $this->narrative($element, $draft));
            } else {
                $this->report->issue($draft->location, $name, $this->text($element), $this->why($name), 'left-out');
            }
        }
    }

    /** Paragraphs, kept apart by a blank line; a paragraph that is only a link to a unit is an associated unit, not text. */
    private function narrative(DOMElement $element, UnitDraft $draft): ?string
    {
        $paragraphs = [];
        $blocks = array_filter($this->elements($element), fn ($e) => $e->localName !== 'head');
        if ($blocks === []) {
            return $this->text($element);
        }
        foreach ($blocks as $block) {
            $links = array_filter($this->elements($block), fn ($e) => in_array($e->localName, ['extref', 'ref'], true));
            if ($block->localName === 'p' && count($links) === 1 && count($this->elements($block)) === 1
                && trim(str_replace((string) reset($links)->textContent, '', $block->textContent)) === '') {
                $href = reset($links)->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: reset($links)->getAttribute('href');
                if ($href !== '') {
                    $draft->associated[] = $href;

                    continue;
                }
            }
            if (($text = $this->text($block)) !== null) {
                $paragraphs[] = $text;
            }
        }

        return $paragraphs === [] ? null : implode("\n\n", $paragraphs);
    }

    private function accessPoints(UnitDraft $draft, DOMElement $controlaccess): void
    {
        foreach ($this->elements($controlaccess) as $element) {
            $name = $element->localName;
            $label = $this->text($element);
            $iri = $this->iri($element);
            if ($label === null || $name === 'head') {
                continue;
            }
            match (true) {
                $name === 'controlaccess' => $this->accessPoints($draft, $element),
                $name === 'subject' => $draft->subjects[] = ['name' => $label, 'iri' => $iri],
                $name === 'geogname' => $draft->places[] = ['name' => $label, 'iri' => $iri],
                $name === 'genreform' => $draft->genres[] = ['name' => $label, 'iri' => $iri],
                array_key_exists($name, self::NAMES) => $draft->names[] = ['name' => $label, 'type' => self::NAMES[$name], 'iri' => $iri],
                default => $this->report->issue($draft->location, "controlaccess/$name", $label, $this->why($name), 'left-out'),
            };
        }
    }

    /** @return list<array{name: string, type: ?string, iri: ?string, role: string}> */
    private function names(DOMElement $origination): array
    {
        $names = [];
        foreach ($this->elements($origination) as $element) {
            if (array_key_exists($element->localName, self::NAMES) && ($label = $this->text($element)) !== null) {
                $names[] = ['name' => $label, 'type' => self::NAMES[$element->localName], 'iri' => $this->iri($element),
                    'role' => $element->getAttribute('role') ?: $element->getAttribute('relator')];
            }
        }
        if ($names === [] && ($label = $this->text($origination)) !== null) {
            $names[] = ['name' => $label, 'type' => null, 'iri' => null, 'role' => ''];
        }

        return $names;
    }

    /** EAD's normal (ISO 8601, basic or extended form) as EDTF, with its written form. */
    private function date(UnitDraft $draft, ?string $normal, ?string $written, string $datechar, string $language): void
    {
        $edtf = null;
        if ($normal !== null) {
            $parts = array_map(fn ($part) => preg_match('/^(\d{4})(\d{2})(\d{2})?$/', $part, $m) ? $m[1].'-'.$m[2].(isset($m[3]) ? '-'.$m[3] : '') : $part, explode('/', $normal));
            $edtf = count($parts) === 2 ? CsvReader::interval($parts[0], $parts[1]) : $parts[0];
        }
        $property = in_array(mb_strtolower($datechar), ['accumulation', 'associated'], true) ? mb_strtolower($datechar) : 'creation';
        if ($edtf !== null || $written !== null) {
            $draft->dates[] = ['property' => $property, 'normalized' => $edtf, 'expressed' => $written === $edtf ? null : $written, 'language' => $language];
        }
    }

    private function structuredDate(UnitDraft $draft, DOMElement $element): void
    {
        $range = $this->child($element, 'daterange');
        $single = $this->child($element, 'datesingle');
        $standard = fn (?DOMElement $e) => $e?->getAttribute('standarddate') ?: null;
        $normal = $range ? CsvReader::interval($standard($this->child($range, 'fromdate')), $standard($this->child($range, 'todate'))) : $standard($single);
        $this->date($draft, $normal, $element->getAttribute('altrender') ?: null, $element->getAttribute('datechar'), $this->lang($element, $draft));
    }

    /** The persistent IRI an EAD name gives: authfilenumber in EAD 2002, identifier in EAD3, when it is an http IRI. */
    private function iri(DOMElement $element): ?string
    {
        $value = $element->getAttribute('authfilenumber') ?: $element->getAttribute('identifier');

        return preg_match('#^https?://#', $value) ? $value : null;
    }

    /** EAD3's lang on an element, when it is a BCP 47 tag of the two-letter kind the Workspace keeps; else the import's language. */
    private function lang(DOMElement $element, UnitDraft $draft): string
    {
        $lang = $element->getAttribute('lang');
        if ($lang === '') {
            return $this->language;
        }
        if (Bcp47::valid($lang) && strlen(strtok($lang, '-')) === 2) {
            return $lang;
        }
        $this->report->once("lang=\"$lang\"", "read as {$this->language}: the Workspace keeps BCP 47 tags such as en or af", 'note');

        return $this->language;
    }

    private function isComponent(DOMElement $element): bool
    {
        return preg_match('/^c(0[1-9]|1[0-2])?$/', $element->localName) === 1;
    }

    /** @return list<DOMElement> */
    private function elements(?DOMElement $parent): array
    {
        $children = [];
        foreach ($parent?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node;
            }
        }

        return $children;
    }

    private function child(?DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($this->elements($parent) as $element) {
            if ($element->localName === $name) {
                return $element;
            }
        }

        return null;
    }

    /** The element's text with its whitespace collapsed, or null when it has none. */
    private function text(?DOMElement $element): ?string
    {
        $text = $element ? trim(preg_replace('/\s+/u', ' ', $element->textContent)) : '';

        return $text === '' ? null : $text;
    }

    private function why(string $element): string
    {
        return match ($element) {
            'dao', 'daogrp', 'daoset' => 'digital objects are not kept yet; not read',
            'container', 'physloc' => 'physical storage is not kept yet; not read',
            'function', 'occupation', 'title' => 'only subjects, places, names and documentary form types are read as access points; not read',
            default => 'no ISAD(G) element the Workspace keeps; not read',
        };
    }
}
