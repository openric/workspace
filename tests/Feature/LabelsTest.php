<?php

// OpenRiC Workspace - labels (roadmap phase 22a): Code 128 drawn by the Workspace (symbols and check worked by hand,
// and the same strings read back by zbarimg when the templates were written), QR codes, templates that must fit their
// sheet, and sheets for a record, what is inside it and a container, each only as the account may read.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\StorageService;
use App\Labels\Code128;
use App\Models\Institution;
use App\Models\LabelTemplate;
use App\Models\User;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LabelsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private Institution $institution;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->officer = app(AccountService::class)->create($this->institution, 'Officer', 'officer@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->officer, 'administrator');
    }

    public function test_code_128_symbols_and_check(): void
    {
        // Set C throughout: start C, 12 34 56 78, check (105 + 12 + 2x34 + 3x56 + 4x78) mod 103 = 47.
        $this->assertSame([105, 12, 34, 56, 78, 47], Code128::symbols('12345678'));
        // Set B, an odd run of digits leaving its first in B, then set C: check 785 mod 103 = 64.
        $this->assertSame([104, 33, 17, 99, 23, 45, 64], Code128::symbols('A12345'));
        foreach (Code128::PATTERNS as $value => $pattern) {
            $this->assertSame($value === 106 ? 13 : 11, array_sum(str_split($pattern)), "symbol $value");
        }
        $this->assertStringContainsString('aria-label="BOX-0042"', Code128::svg('BOX-0042'));
        $this->expectException(InvalidArgumentException::class);
        Code128::symbols('Kaapstad é');
    }

    public function test_sheets_for_records_and_containers_from_templates_that_fit(): void
    {
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Council records', 'en', null, $holder, 'C');
        foreach (['Minute book 1902' => '1', 'Minute book 1903' => '2'] as $title => $code) {
            app(RecordService::class)->create($this->institution, self::LEVEL.'file', $title, 'en', $fonds, null, $code);
        }
        $box = app(StorageService::class)->create($this->institution, 'Box 42', 'en', null, 'B-42', $this->officer);
        app(StorageService::class)->setBarcode($box, 'BOX-0042', $this->officer);

        // Heratio's default template is made where there is none; one that does not fit its sheet is refused.
        $this->actingAs($this->officer)->get(route('labels.templates'))->assertOk()->assertSee('Standard (3 x 8, A4)');
        $this->actingAs($this->officer)->post(route('labels.templates.store'), ['name' => 'Too wide', 'page_size' => 'a4', 'columns' => 4, 'rows' => 8, 'label_width_mm' => 63.5,
            'label_height_mm' => 33.9, 'margin_mm' => 10, 'gutter_mm' => 2.5])->assertSessionHasErrors('labels');
        $this->actingAs($this->officer)->post(route('labels.templates.store'), ['name' => 'Box labels', 'page_size' => 'a4', 'columns' => 2, 'rows' => 5, 'label_width_mm' => 90,
            'label_height_mm' => 50, 'margin_mm' => 10, 'gutter_mm' => 5, 'show_title' => 1, 'show_barcode' => 1, 'barcode_source' => 'barcode', 'show_qr' => 1, 'qr_target' => 'url'])
            ->assertSessionHasNoErrors();
        $boxes = LabelTemplate::where('name', 'Box labels')->sole();

        // What is inside the fonds, in order, each with its reference code, and its identifier as its barcode (the default).
        $sheet = $this->actingAs($this->officer)->get(route('labels.print', ['kind' => 'records', 'ids' => [$fonds->id], 'inside' => 1]))->assertOk();
        $sheet->assertSeeInOrder(['Minute book 1902', 'Minute book 1903'])->assertSee('RA C/1')->assertSee('aria-label="1"', false)->assertSee('size: 210mm 297mm', false);
        // The box, its barcode and a QR code of its page, two labels already used on the sheet.
        $sheet = $this->actingAs($this->officer)->get(route('labels.print', ['kind' => 'containers', 'ids' => [$box->id], 'template' => $boxes->id, 'skip' => 2]))->assertOk();
        $sheet->assertSee('Box 42')->assertSee('aria-label="BOX-0042"', false)->assertSee('<svg', false);
        $this->assertSame(3, substr_count($sheet->getContent(), '<div class="label">'));

        // Another institution's account prints nothing of these.
        $other = Institution::create(['name' => 'Elsewhere', 'base_iri' => 'https://elsewhere.example.org']);
        app(RoleService::class)->seedDefaults($other);
        $stranger = app(AccountService::class)->create($other, 'Stranger', 'stranger@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($stranger, 'administrator');
        $this->actingAs($stranger)->get(route('labels.print', ['kind' => 'records', 'ids' => [$fonds->id]]))->assertNotFound();
        $this->actingAs($stranger)->get(route('labels.print', ['kind' => 'containers', 'ids' => [$box->id]]))->assertNotFound();
    }
}
