<?php

// OpenRiC Workspace - files taken in without a person at the screen: from a watched folder or the scan API (roadmap
// phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-scan (ProcessScanFile, SidecarParser, BagItIngestService). Single files become a record each, of
// the folder's level under its record set, the files sharing a name's stem (ARC-1_p1.tif, ARC-1_p2.tif) one record
// with an instantiation for each, in order. A sidecar beside them (ARC-1.xml, Heratio's <heratioScan> format, so
// scanners set up for Heratio work unchanged) gives the record's title, identifier, level, scope and content, dates
// and access conditions, and may name its parent by identifier. A BagIt bag is checked against its manifest first and
// refused whole if any file differs. Changed: Heratio's sector routing, rights enforcement and AI processing are not
// carried (AI is phase 17); the record is a draft, as an import's are, until someone publishes it.

namespace App\Ingest;

use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

class Intake
{
    public function __construct(private RecordService $records, private InstantiationService $instantiations, private DateService $dates) {}

    /**
     * What a <heratioScan> sidecar says, as Heratio's SidecarParser reads it, for the fields the Workspace keeps.
     *
     * @return array{title: ?string, identifier: ?string, level: ?string, scope: ?string, access: ?string, parent: ?string, dates: list<array{start: ?string, end: ?string, display: ?string}>}
     */
    public static function sidecar(string $xml): array
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || throw new InvalidArgumentException(__('the sidecar is not well-formed XML'));
        $root = $doc->documentElement;
        ($root && $root->localName === 'heratioScan') || throw new InvalidArgumentException(__('the sidecar\'s root is not heratioScan'));
        $child = function (DOMElement $parent, string $name): ?string {
            foreach ($parent->childNodes as $node) {
                if ($node instanceof DOMElement && $node->localName === $name) {
                    return trim($node->textContent) ?: null;
                }
            }

            return null;
        };
        $dates = [];
        foreach ($root->getElementsByTagNameNS('*', 'date') as $date) {
            $dates[] = ['start' => $date->getAttribute('start') ?: $child($date, 'start'), 'end' => $date->getAttribute('end') ?: $child($date, 'end'),
                'display' => $date->getAttribute('display') ?: (trim($date->textContent) ?: null)];
        }

        return ['title' => $child($root, 'title'), 'identifier' => $child($root, 'identifier'), 'level' => $child($root, 'levelOfDescription'),
            'scope' => $child($root, 'scopeAndContent'), 'access' => $child($root, 'accessConditions'),
            'parent' => $child($root, 'parentIdentifier') ?? $child($root, 'parentIri'), 'dates' => $dates];
    }

    /** The stem files are grouped by: a page or sequence number at the end is set aside (Heratio's findSidecar). */
    public static function stem(string $name): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);

        return preg_match('/^(.+?)[_-]p?\d+$/i', $stem, $m) ? $m[1] : $stem;
    }

    /**
     * A record made for files, under the parent (or the record set the sidecar names inside it), of the level the
     * sidecar gives or the default, its files kept as its instantiations in their order.
     *
     * @param  list<array{path: string, name: string}>  $files
     */
    public function record(User $actor, Repository $repository, ?RecordResource $parent, Concept $defaultLevel, array $meta, array $files, string $language): RecordResource
    {
        $files !== [] || throw new InvalidArgumentException(__('There is no file to take in.'));
        $institution = Institution::findOrFail($actor->institution_id);
        if ($meta['parent'] ?? null) {
            $parent = $this->parentNamed($institution, $parent, $meta['parent']) ?? throw new InvalidArgumentException(__('The sidecar names a parent, :parent, that is not here.', ['parent' => $meta['parent']]));
        }
        if ($parent ? ! $actor->may('create', 'description', $parent) : ! $actor->may('create', 'description', $repository)) {
            throw new InvalidArgumentException(__('You may not add records there.'));
        }
        $level = ($meta['level'] ?? null) ? Concept::choices(Vocab::BASE.'level-of-description/')->get()->first(fn ($c) => in_array(mb_strtolower($meta['level']), [$c->notation, mb_strtolower((string) $c->label(['en']))], true)) : null;
        $title = ($meta['title'] ?? null) ?: self::stem($files[0]['name']);
        $record = $this->records->create($institution, ($level ?? $defaultLevel)->iri, mb_substr($title, 0, 1024), $language, $parent, $parent ? null : $repository, $meta['identifier'] ?? null, $actor);
        $values = array_filter(['scope-and-content' => $meta['scope'] ?? null, 'conditions-governing-access' => $meta['access'] ?? null]);
        if ($values !== []) {
            $this->records->describe($record, $values, $language, $actor);
        }
        foreach ($meta['dates'] ?? [] as $date) {
            $normalized = $date['start'] ? ($date['end'] && $date['end'] !== $date['start'] ? $date['start'].'/'.$date['end'] : $date['start']) : null;
            if ($normalized || $date['display']) {
                $this->dates->add($record, ['property' => 'creation', 'normalized' => $normalized, 'expressed' => $date['display'], 'language' => $date['display'] ? $language : null], $actor);
            }
        }
        foreach ($files as $file) {
            $this->instantiations->upload($record, $file['path'], $file['name'], $language, null, null, 'online', $actor);
        }

        return $record;
    }

    /**
     * Whether the folder is a BagIt bag whose payload matches its manifest (RFC 8493, Heratio's BagItIngestService); a
     * mismatch is refused whole, naming the first file that differs.
     *
     * @return list<array{path: string, name: string}> the payload files, by their path inside data/
     */
    public static function bag(string $dir): array
    {
        $manifests = glob($dir.'/manifest-{sha512,sha256,sha1,md5}.txt', GLOB_BRACE) ?: [];
        $manifests !== [] || throw new InvalidArgumentException(__('the bag has no manifest'));
        $algorithm = preg_replace('/^manifest-(.+)\.txt$/', '$1', basename($manifests[0]));
        $files = [];
        foreach (file($manifests[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$sum, $path] = preg_split('/\s+/', trim($line), 2) + [1 => ''];
            $segments = explode('/', $path);
            if ($path === '' || in_array('..', $segments, true) || $segments[0] !== 'data') {
                throw new InvalidArgumentException(__('the bag\'s manifest names a file outside data/: :path', ['path' => $path]));
            }
            $local = $dir.'/'.$path;
            if (! is_file($local) || ! hash_equals(strtolower($sum), hash_file($algorithm, $local))) {
                throw new InvalidArgumentException(__('the bag does not match its manifest: :path', ['path' => $path]));
            }
            $files[] = ['path' => $local, 'name' => substr($path, 5)];
        }

        return $files;
    }

    /** A record set inside the parent (or anywhere in the institution without one) by its persistent IRI or identifier. */
    private function parentNamed(Institution $institution, ?RecordResource $within, string $named): ?RecordResource
    {
        $found = RecordResource::where('institution_id', $institution->id)->whereNull('deleted_at')->where('iri', $named)->first();
        if ($found) {
            return $found;
        }
        $candidates = RecordResource::where('institution_id', $institution->id)->whereNull('deleted_at')
            ->when($within, fn ($q) => $q->where('parent_id', $within->id))->get()->filter(fn ($r) => $r->identifier === $named);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
