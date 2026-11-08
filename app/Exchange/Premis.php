<?php

// OpenRiC Workspace - a unit of description's preservation metadata as PREMIS 3.0 XML (roadmap phase 15a), after Heratio's
// ahg-preservation PremisXmlSerializer: the unit as an intellectual entity, each instantiation as a representation of
// it, each file with its SHA-256, size, PRONOM format and original name, a viewing copy related to the master it was
// made from, and every event done to them with the agents that did it, each event type with the Library of Congress
// preservation vocabulary term it matches. Only instantiations the reader may read go in. From phase 15c, a preservation
// master or access copy is related to the file it was normalised or migrated from, and the rights in force on the unit
// and its instantiations are PREMIS rights statements (Heratio's PremisRightsService), each with its basis, its
// copyright, licence or statute information, its grants and its holder, as every other export says them.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Exchange;

use App\Models\DigitalFile;
use App\Models\PreservationEvent;
use App\Models\RecordResource;
use App\Models\Right;
use App\Rights\PublicRights;
use XMLWriter;

class Premis
{
    public const NS = 'http://www.loc.gov/premis/v3';

    private const EVENT_TYPES = 'http://id.loc.gov/vocabulary/preservation/eventType';

    private const AGENT_TYPES = 'http://id.loc.gov/vocabulary/preservation/agentType';

    /** The Library of Congress code of each event and agent type, as the vocabularies' skos:exactMatch give them. */
    /** The events that make a file from another. */
    private const MADE = ['creation', 'normalization', 'migration'];

    private const LOC = ['premis-event-type' => ['ingestion' => 'ing', 'creation' => 'cre', 'message-digest-calculation' => 'mes', 'virus-check' => 'vir',
        'format-identification' => 'for', 'metadata-extraction' => 'mee', 'fixity-check' => 'fix', 'validation' => 'val', 'migration' => 'mig',
        'normalization' => 'nor', 'replication' => 'rep', 'deletion' => 'del'], 'premis-agent-type' => ['person' => 'per', 'organization' => 'org', 'software' => 'sof']];

    public function export(RecordResource $unit, ExportView $view): string
    {
        $instantiations = $unit->instantiations()->whereNull('deleted_at')->with('files.use', 'files.format', 'carrierType')->get()->filter(fn ($i) => $view->readable($i))->values();
        $files = $instantiations->flatMap->files;
        $events = PreservationEvent::whereIn('digital_file_id', $files->pluck('id'))->with('type', 'outcome', 'agentType')->orderBy('occurred_at')->orderBy('id')->get();
        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElementNs(null, 'premis', self::NS);
        $x->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->writeAttribute('xsi:schemaLocation', self::NS.' https://www.loc.gov/standards/premis/premis.xsd');
        $x->writeAttribute('version', '3.0');

        // The unit, as an intellectual entity.
        $this->object($x, 'intellectualEntity', $unit->iri, function () use ($x, $unit, $view, $instantiations) {
            $x->writeElement('originalName', (string) $view->label($unit));
            foreach ($instantiations as $i) {
                $this->relationship($x, 'structural', 'is represented by', $i->iri);
            }
        });
        // Each instantiation, as a representation of it.
        foreach ($instantiations as $i) {
            $this->object($x, 'representation', $i->iri, function () use ($x, $i, $view, $unit) {
                $x->writeElement('originalName', (string) $view->label($i));
                $this->relationship($x, 'structural', 'represents', $unit->iri);
                foreach ($i->files as $file) {
                    $this->relationship($x, 'structural', 'includes', self::fileId($file));
                }
            });
        }
        // Each file.
        $masters = $files->filter(fn ($f) => $f->use?->notation === 'master')->keyBy('instantiation_id');
        foreach ($files as $file) {
            $this->object($x, 'file', self::fileId($file), function () use ($x, $file, $masters, $events) {
                // A 3D model's significant properties (roadmap phase 21, after Heratio's ahg:3d-preserve), read from it.
                foreach (self::modelProperties($file) as $type => $value) {
                    $x->startElement('significantProperties');
                    $x->writeElement('significantPropertiesType', $type);
                    $x->writeElement('significantPropertiesValue', $value);
                    $x->endElement();
                }
                $x->startElement('objectCharacteristics');
                $x->writeElement('compositionLevel', '0');
                $x->startElement('fixity');
                $x->writeElement('messageDigestAlgorithm', 'SHA-256');
                $x->writeElement('messageDigest', $file->sha256);
                $x->writeElement('messageDigestOriginator', 'OpenRiC Workspace');
                $x->endElement();
                $x->writeElement('size', (string) $file->size_bytes);
                $x->startElement('format');
                $x->startElement('formatDesignation');
                $x->writeElement('formatName', $file->format?->name ?? $file->mime_type);
                if ($file->format?->version) {
                    $x->writeElement('formatVersion', $file->format->version);
                }
                $x->endElement();
                if ($file->format) {
                    $x->startElement('formatRegistry');
                    $x->writeElement('formatRegistryName', 'PRONOM');
                    $x->writeElement('formatRegistryKey', $file->format->puid);
                    $x->writeElement('formatRegistryRole', 'specification');
                    $x->endElement();
                }
                $x->endElement();
                $x->endElement();
                $x->writeElement('originalName', $file->original_name);
                $this->relationship($x, 'structural', 'is included in', $file->instantiation->iri);
                // Its source: the file its normalisation or migration was made from, or else the master.
                $made = $events->first(fn ($e) => $e->digital_file_id === $file->id && $e->source_file_id && in_array($e->type->notation, self::MADE, true));
                $source = $made?->source_file_id ? 'urn:uuid:'.$made->source_file_id : (($master = $masters->get($file->instantiation_id)) && $master->id !== $file->id ? self::fileId($master) : null);
                if ($source) {
                    $this->relationship($x, 'derivation', 'has source', $source);
                }
                foreach ($events->where('digital_file_id', $file->id) as $event) {
                    $this->identifier($x, 'linkingEventIdentifier', 'UUID', $event->id);
                }
            });
        }
        // Every event done to them.
        foreach ($events as $event) {
            $x->startElement('event');
            $this->identifier($x, 'eventIdentifier', 'UUID', $event->id);
            $x->startElement('eventType');
            $x->writeAttribute('authority', 'eventType');
            $x->writeAttribute('authorityURI', self::EVENT_TYPES);
            if ($code = self::LOC['premis-event-type'][$event->type->notation] ?? null) {
                $x->writeAttribute('valueURI', self::EVENT_TYPES.'/'.$code);
            }
            $x->text((string) $event->type->anyLabel(['en']));
            $x->endElement();
            $x->writeElement('eventDateTime', $event->occurred_at->toIso8601String());
            if ($event->detail) {
                $x->startElement('eventDetailInformation');
                $x->writeElement('eventDetail', $event->detail);
                $x->endElement();
            }
            $x->startElement('eventOutcomeInformation');
            $x->writeElement('eventOutcome', (string) $event->outcome->notation);
            if ($event->outcome_detail) {
                $x->startElement('eventOutcomeDetail');
                $x->writeElement('eventOutcomeDetailNote', $event->outcome_detail);
                $x->endElement();
            }
            $x->endElement();
            $this->identifier($x, 'linkingAgentIdentifier', 'local', self::agentId($event));
            $x->startElement('linkingObjectIdentifier');
            $x->writeElement('linkingObjectIdentifierType', 'UUID');
            $x->writeElement('linkingObjectIdentifierValue', 'urn:uuid:'.$event->digital_file_id);
            $x->writeElement('linkingObjectRole', in_array($event->type->notation, self::MADE, true) && $event->outcome->notation === 'success' && $event->source_file_id ? 'outcome' : 'source');
            $x->endElement();
            if ($event->source_file_id) {
                $x->startElement('linkingObjectIdentifier');
                $x->writeElement('linkingObjectIdentifierType', 'UUID');
                $x->writeElement('linkingObjectIdentifierValue', 'urn:uuid:'.$event->source_file_id);
                $x->writeElement('linkingObjectRole', 'source');
                $x->endElement();
            }
            $x->endElement();
        }
        $rights = collect([$unit, ...$instantiations])->flatMap(fn ($subject) => (new PublicRights($view))->rights($subject)->map(fn (Right $r) => [$r, $subject]));
        // The agents, once each, and the holders of its rights.
        foreach ($rights->pluck(0)->pluck('holder')->filter()->unique('id') as $holder) {
            $x->startElement('agent');
            $this->identifier($x, 'agentIdentifier', 'URI', $holder->iri);
            $x->writeElement('agentName', (string) $view->label($holder));
            $x->endElement();
        }
        foreach ($events->unique(fn ($e) => self::agentId($e)) as $event) {
            $x->startElement('agent');
            $this->identifier($x, 'agentIdentifier', 'local', self::agentId($event));
            $x->writeElement('agentName', $event->agent_name);
            $x->startElement('agentType');
            $x->writeAttribute('authority', 'agentType');
            $x->writeAttribute('authorityURI', self::AGENT_TYPES);
            if ($code = self::LOC['premis-agent-type'][$event->agentType->notation] ?? null) {
                $x->writeAttribute('valueURI', self::AGENT_TYPES.'/'.$code);
            }
            $x->text((string) $event->agentType->anyLabel(['en']));
            $x->endElement();
            $x->endElement();
        }
        foreach ($rights as [$right, $subject]) {
            $this->rights($x, $right, $subject->iri);
        }
        $x->endElement();

        return $x->outputMemory();
    }

    /** A right as a PREMIS rights statement: the basis, then what that basis says, then each act granted and the object it bears on. */
    private function rights(XMLWriter $x, Right $right, string $object): void
    {
        $basis = $right->basis->notation;
        $dates = function (string $element) use ($x, $right) {
            if ($right->starts_on) {
                $x->startElement($element);
                $x->writeElement('startDate', $right->starts_on->toDateString());
                $right->ends_on && $x->writeElement('endDate', $right->ends_on->toDateString());
                $x->endElement();
            }
        };
        $x->startElement('rights');
        $x->startElement('rightsStatement');
        $this->identifier($x, 'rightsStatementIdentifier', 'URI', $right->iri);
        $x->writeElement('rightsBasis', ['copyright' => 'Copyright', 'licence' => 'License', 'statute' => 'Statute'][$basis] ?? 'Other');
        $jurisdiction = trim((string) $right->jurisdiction) ?: 'unknown';
        if ($basis === 'copyright') {
            $x->startElement('copyrightInformation');
            $x->writeElement('copyrightStatus', ['copyrighted' => 'copyrighted', 'public-domain' => 'publicdomain'][$right->copyrightStatus?->notation] ?? 'unknown');
            $x->writeElement('copyrightJurisdiction', $jurisdiction);
            $right->determined_on && $x->writeElement('copyrightStatusDeterminationDate', $right->determined_on->toDateString());
            $right->note && $x->writeElement('copyrightNote', $right->note);
            $dates('copyrightApplicableDates');
            $x->endElement();
        } elseif ($basis === 'licence') {
            $x->startElement('licenseInformation');
            if ($right->licence) {
                $this->identifier($x, 'licenseDocumentationIdentifier', 'URI', $right->licence->iri);
            }
            $x->writeElement('licenseTerms', trim((string) $right->licence_terms) ?: (string) $right->licence?->label(['en']) ?: PublicRights::summary($right));
            $right->note && $x->writeElement('licenseNote', $right->note);
            $dates('licenseApplicableDates');
            $x->endElement();
        } elseif ($basis === 'statute') {
            $x->startElement('statuteInformation');
            $x->writeElement('statuteJurisdiction', $jurisdiction);
            $x->writeElement('statuteCitation', trim((string) $right->citation) ?: PublicRights::summary($right));
            $right->determined_on && $x->writeElement('statuteInformationDeterminationDate', $right->determined_on->toDateString());
            $right->note && $x->writeElement('statuteNote', $right->note);
            $dates('statuteApplicableDates');
            $x->endElement();
        } else {
            $x->startElement('otherRightsInformation');
            $x->writeElement('otherRightsBasis', (string) $right->basis->label(['en']));
            $dates('otherRightsApplicableDates');
            $right->note && $x->writeElement('otherRightsNote', $right->note);
            $x->endElement();
        }
        foreach ($right->grants as $grant) {
            $x->startElement('rightsGranted');
            $x->writeElement('act', (string) $grant->act->notation);
            $grant->restriction && $x->writeElement('restriction', (string) $grant->restriction->notation);
            if ($grant->starts_on) {
                $x->startElement('termOfGrant');
                $x->writeElement('startDate', $grant->starts_on->toDateString());
                $grant->ends_on && $x->writeElement('endDate', $grant->ends_on->toDateString());
                $x->endElement();
            }
            $grant->note && $x->writeElement('rightsGrantedNote', $grant->note);
            $x->endElement();
        }
        $x->startElement('linkingObjectIdentifier');
        $x->writeElement('linkingObjectIdentifierType', 'URI');
        $x->writeElement('linkingObjectIdentifierValue', $object);
        $x->endElement();
        if ($right->holder) {
            $x->startElement('linkingAgentIdentifier');
            $x->writeElement('linkingAgentIdentifierType', 'URI');
            $x->writeElement('linkingAgentIdentifierValue', $right->holder->iri);
            $x->writeElement('linkingAgentRole', 'rightsholder');
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
    }

    public static function fileId(DigitalFile $file): string
    {
        return 'urn:uuid:'.$file->id;
    }

    private static function agentId(PreservationEvent $event): string
    {
        return $event->user_id ? 'user:'.$event->user_id : 'software:'.$event->agent_name;
    }

    private function object(XMLWriter $x, string $kind, string $id, callable $body): void
    {
        $x->startElement('object');
        $x->writeAttribute('xsi:type', $kind);
        $this->identifier($x, 'objectIdentifier', str_starts_with($id, 'urn:uuid:') ? 'UUID' : 'URI', $id);
        $body();
        $x->endElement();
    }

    private function identifier(XMLWriter $x, string $element, string $type, string $value): void
    {
        $x->startElement($element);
        $x->writeElement($element.'Type', $type);
        $x->writeElement($element.'Value', $value);
        $x->endElement();
    }

    private function relationship(XMLWriter $x, string $type, string $subType, string $related): void
    {
        $x->startElement('relationship');
        $x->writeElement('relationshipType', $type);
        $x->writeElement('relationshipSubType', $subType);
        $x->startElement('relatedObjectIdentifier');
        $x->writeElement('relatedObjectIdentifierType', str_starts_with($related, 'urn:uuid:') ? 'UUID' : 'URI');
        $x->writeElement('relatedObjectIdentifierValue', $related);
        $x->endElement();
        $x->endElement();
    }

    /** @return array<string, string> what a 3D model is made of, as PREMIS significant properties */
    public static function modelProperties($file): array
    {
        $p = (array) ($file->model_properties ?? []);
        $names = ['format_version' => 'format', 'vertex_count' => 'vertices', 'face_count' => 'faces', 'texture_count' => 'textures', 'animation_count' => 'animations',
            'has_materials' => 'materials', 'has_rig' => 'rig', 'compression' => 'compression', 'bounding_box' => 'bounding box (model units)', 'pbr_maps' => 'material maps'];
        $out = [];
        foreach ($names as $key => $name) {
            if (isset($p[$key]) && $p[$key] !== '') {
                $out['3D '.$name] = in_array($key, ['has_materials', 'has_rig'], true) ? ($p[$key] ? 'yes' : 'no') : (string) $p[$key];
            }
        }

        return $out;
    }
}
