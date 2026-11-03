<?php

// OpenRiC Workspace - labels for records and containers, printed on a sheet of a template's size (roadmap phase 22a).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-label (LabelController, label_template): each label gives the entity's title, identifier and
// repository as the template asks, a Code 128 barcode of its identifier, reference code, container barcode or
// persistent IRI, and a QR code of its page or identifier. Barcodes and QR codes are drawn here (Code128, and the
// BaconQrCode library Heratio uses for QR) rather than fetched from an outside service, as Heratio's Code 128 was.
// A template is a row; an institution with none is given Heratio's default, three columns of eight on A4.

namespace App\Labels;

use App\Models\Concept;
use App\Models\Container;
use App\Models\LabelTemplate;
use App\Models\RecordResource;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Labels
{
    /** The page sizes, width and height in millimetres. */
    public const PAGES = ['a4' => [210, 297], 'letter' => [215.9, 279.4]];

    /** The institution's templates, Heratio's default made where there is none. */
    public function templates(string $institutionId): Collection
    {
        if (! LabelTemplate::where('institution_id', $institutionId)->exists()) {
            LabelTemplate::create(['institution_id' => $institutionId, 'name' => 'Standard (3 x 8, A4)', 'page_size_id' => $this->concept('label-page-size', 'a4'),
                'barcode_source_id' => $this->concept('label-barcode-source', 'identifier'), 'qr_target_id' => $this->concept('label-qr-target', 'url'), 'is_default' => true]);
        }

        return LabelTemplate::where('institution_id', $institutionId)->with('pageSize', 'barcodeSource', 'qrTarget')->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function save(string $institutionId, array $data, ?LabelTemplate $template = null): LabelTemplate
    {
        $page = self::PAGES[$data['page_size'] ?? 'a4'] ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $data['page_size'] ?? '']));
        [$columns, $rows] = [(int) ($data['columns'] ?? 3), (int) ($data['rows'] ?? 8)];
        [$width, $height, $margin, $gutter] = [(float) ($data['label_width_mm'] ?? 63.5), (float) ($data['label_height_mm'] ?? 33.9), (float) ($data['margin_mm'] ?? 10), (float) ($data['gutter_mm'] ?? 2.5)];
        if ($columns < 1 || $rows < 1 || $width <= 0 || $height <= 0 || 2 * $margin + $columns * $width + ($columns - 1) * $gutter > $page[0] + 0.01
            || 2 * $margin + $rows * $height + ($rows - 1) * $gutter > $page[1] + 0.01) {
            throw new InvalidArgumentException(__('The labels, margins and gaps do not fit on the page.'));
        }
        $fields = ['name' => trim((string) ($data['name'] ?? '')) ?: throw new InvalidArgumentException(__('Name the template.')),
            'page_size_id' => $this->concept('label-page-size', $data['page_size'] ?? 'a4'), 'columns' => $columns, 'rows' => $rows, 'label_width_mm' => $width, 'label_height_mm' => $height,
            'margin_mm' => $margin, 'gutter_mm' => $gutter, 'font_size_pt' => max(5, min(24, (int) ($data['font_size_pt'] ?? 9))),
            'show_title' => ! empty($data['show_title']), 'show_identifier' => ! empty($data['show_identifier']), 'show_repository' => ! empty($data['show_repository']),
            'show_barcode' => ! empty($data['show_barcode']), 'barcode_source_id' => $this->concept('label-barcode-source', $data['barcode_source'] ?? 'identifier'),
            'show_qr' => ! empty($data['show_qr']), 'qr_target_id' => $this->concept('label-qr-target', $data['qr_target'] ?? 'url'), 'is_default' => ! empty($data['is_default'])];
        if ($fields['is_default']) {
            LabelTemplate::where('institution_id', $institutionId)->when($template, fn ($q) => $q->whereKeyNot($template->id))->update(['is_default' => false]);
        }

        return $template ? tap($template)->update($fields) : LabelTemplate::create($fields + ['institution_id' => $institutionId]);
    }

    /** @return array{title: string, identifier: ?string, repository: ?string, barcode: ?string, qr: ?string} what one label shows */
    public function label(Model $entity, LabelTemplate $template): array
    {
        $identifier = $entity instanceof RecordResource ? ($entity->referenceCode() ?? $entity->identifier) : $entity->identifier;
        $barcodeValue = match ($template->barcodeSource->notation) {
            'reference-code' => $entity instanceof RecordResource ? $entity->referenceCode() : $entity->identifier,
            'barcode' => $entity instanceof Container ? $entity->identifiers->first(fn ($i) => $i->identifierType?->notation === 'barcode')?->value : null,
            'iri' => $entity->iri,
            default => $entity->identifier,
        } ?? $entity->identifier;
        $repository = $entity instanceof RecordResource ? ($entity->repository ?? collect($entity->ancestors())->first()?->repository) : null;
        $barcode = null;
        if ($template->show_barcode && $barcodeValue !== null && preg_match('/^[\x20-\x7E]+$/', $barcodeValue)) {
            $barcode = Code128::svg($barcodeValue, 40, 1, true);
        }
        $qr = $template->show_qr ? $this->qr($template->qrTarget->notation === 'identifier' ? ($identifier ?? $entity->iri) : $entity->iri) : null;

        return ['title' => (string) EntityKinds::label($entity), 'identifier' => $identifier, 'repository' => $repository ? (string) EntityKinds::label($repository) : null,
            'barcode' => $barcode, 'qr' => $qr];
    }

    /** The QR code of a text as SVG (Heratio's QrCodeService). */
    public function qr(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd)))->writeString($text);

        return preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
