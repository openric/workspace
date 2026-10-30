<?php

// OpenRiC Workspace - what the EAC-CPF and EAC-F readers share (roadmap phase 11a): the documents of an import (one
// XML file, or the files of a ZIP the import kept as a JSON list), elements found by their local names, and every
// element of a record the reader did not read listed in the report, so nothing is lost silently.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Exchange\Import;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

trait ReadsXmlRecords
{
    private Report $report;

    private string $location = '';

    /** @var array<string, true> the elements of the record being read that were read, by their path in the document */
    private array $seen = [];

    private ?DOMElement $root = null;

    /**
     * The root element of each document, by the name of its file.
     *
     * @return iterable<string, DOMElement>
     */
    private function documents(string $content, string $rootName, string $label, Report $report): iterable
    {
        $this->report = $report;
        $files = str_starts_with(ltrim($content), '[') ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [['name' => 'the file', 'content' => $content]];
        foreach ($files as $file) {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->loadXML((string) $file['content'], LIBXML_NONET);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (! $loaded || $document->documentElement?->localName !== $rootName) {
                $message = $file['name'].' is not '.$label.($errors ? ': '.trim($errors[0]->message) : '');
                if (count($files) === 1) {
                    throw new InvalidArgumentException($message);
                }
                $report->issue($file['name'], $rootName, null, $message, 'rejected');

                continue;
            }
            yield $file['name'] => $document->documentElement;
        }
    }

    private function begin(string $location, DOMElement $root): void
    {
        $this->location = $location;
        $this->root = $root;
        $this->seen = [$root->getNodePath() => true];
    }

    /** List each element of the record no one read, once per element name. */
    private function end(): void
    {
        $unread = [];
        foreach ($this->root?->getElementsByTagName('*') ?? [] as $element) {
            if (! isset($this->seen[$element->getNodePath()]) && ! isset($unread[$element->localName])) {
                $unread[$element->localName] = trim(mb_substr($element->textContent, 0, 200));
            }
        }
        foreach ($unread as $name => $text) {
            $this->report->issue($this->location, $name, $text === '' ? null : $text, 'not read: the Workspace has no place for it here', 'left-out');
        }
    }

    private function mark(DOMElement $element): void
    {
        $this->seen[$element->getNodePath()] = true;
    }

    private function markAll(DOMElement $element): void
    {
        $this->mark($element);
        foreach ($element->getElementsByTagName('*') as $inner) {
            $this->seen[$inner->getNodePath()] = true;
        }
    }

    private function child(DOMElement $parent, string $name): ?DOMElement
    {
        return $this->children($parent, $name)[0] ?? null;
    }

    /** @return list<DOMElement> the element's children of that local name, or with $deep its descendants */
    private function children(DOMElement $parent, string $name, bool $deep = false): array
    {
        $found = [];
        foreach ($deep ? $parent->getElementsByTagName('*') : $parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                $found[] = $node;
                if ($deep) {
                    $this->mark($node);
                }
            }
        }

        return $found;
    }

    private function text(?DOMElement $element): ?string
    {
        if ($element === null) {
            return null;
        }
        $this->mark($element);

        return ($t = trim($element->textContent)) === '' ? null : $t;
    }

    /** The element's language: xml:lang, or EAS 2026's languageOfElement. */
    private function lang(DOMElement $element): ?string
    {
        $lang = $element->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') ?: $element->getAttribute('languageOfElement');

        return $lang === '' ? null : $lang;
    }

    /** A date element's standardDate, the EDTF the Workspace keeps. */
    private function standard(DOMElement $date): ?string
    {
        return ($s = trim($date->getAttribute('standardDate'))) === '' ? null : $s;
    }

    /** The paragraphs of a narrative element, one blank line between them. */
    private function paragraphs(DOMElement $element): string
    {
        $paragraphs = array_map(fn ($p) => trim($p->textContent), $this->children($element, 'p', true));

        return implode("\n\n", array_filter($paragraphs)) ?: trim($element->textContent);
    }

    /**
     * A relation: the type (EAC-CPF's xlink:arcrole, or EAC-F's relationType), the other end (xlink:href, or a label's
     * valueURI) and its label, which end this record is (xlink:role), dates and a note.
     *
     * @return array{type: string, iri: ?string, label: string, end: ?string, from: ?string, to: ?string, description: ?string}
     */
    private function relation(DOMElement $relation, string $entryName): array
    {
        $xlink = 'http://www.w3.org/1999/xlink';
        $entry = $this->child($relation, $entryName);
        $range = $this->child($relation, 'dateRange');
        $from = $range ? $this->child($range, 'fromDate') : null;
        $to = $range ? $this->child($range, 'toDate') : null;
        $note = $this->child($relation, 'descriptiveNote');

        return ['type' => $relation->getAttributeNS($xlink, 'arcrole') ?: $relation->getAttribute('relationType') ?: $relation->getAttribute('cpfRelationType') ?: $relation->getAttribute('resourceRelationType'),
            'iri' => ($relation->getAttributeNS($xlink, 'href') ?: $entry?->getAttribute('valueURI')) ?: null, 'label' => trim((string) $entry?->textContent),
            // EAC-CPF: xlink:role says which end this record is; EAC-F: the label's localType says which end the other is.
            'end' => in_array($role = $relation->getAttributeNS($xlink, 'role'), ['source', 'target'], true) ? $role
                : (['source' => 'target', 'target' => 'source'][(string) $entry?->getAttribute('localType')] ?? null),
            'from' => $from ? $this->standard($from) : null, 'to' => $to ? $this->standard($to) : null, 'description' => $note ? $this->paragraphs($note) : null];
    }
}
