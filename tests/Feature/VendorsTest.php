<?php

// OpenRiC Workspace - vendors (roadmap phase 22d, ADR 0020): an authority record made a vendor, numbered as Heratio
// numbers them, its bank details encrypted and kept out of the audit log; work requested only of an active vendor for
// a service it offers, due back after the service's usual days; records sent only once approved, insured and valued
// where the service asks it, and never while out with another vendor; each status stamping its date and who did it
// and kept in the history; documents filed; and the pages, for who may read vendors only.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\AgentService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Vendors\Vendors;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class VendorsTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $editor;

    private RecordResource $map;

    private RecordResource $volume;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        $this->editor = app(AccountService::class)->create($this->institution, 'Editor', 'editor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($this->editor, 'editor');
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Riverside Parish Council records', 'en', null, $holder, 'RPC');
        $this->map = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Map of the commonage, 1902', 'en', $fonds, null, '1');
        $this->volume = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minute book, 1902 to 1910', 'en', $fonds, null, '2');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    private function vendor(string $name, array $data = []): Vendor
    {
        $agent = app(AgentService::class)->create($this->institution, 'corporate-body', $name, 'en');

        return app(Vendors::class)->create($agent, $data + ['services' => ['conservation' => ['offered' => 1, 'hourly_rate' => '450'], 'binding' => ['offered' => 1]]], $this->editor);
    }

    private function refused(callable $do, string $why): void
    {
        try {
            $do();
            $this->fail("accepted, though: $why");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }
    }

    public function test_an_authority_record_is_made_a_vendor_with_its_bank_details_encrypted(): void
    {
        $paper = $this->vendor('Paper Conservation Studio', ['bank_name' => 'First Bank', 'bank_account_number' => '62001234567', 'vat_number' => '4123456789']);
        $this->assertSame('VND'.now()->format('y').'0001', $paper->vendor_code);
        $this->assertSame('VND'.now()->format('y').'0002', $this->vendor('Bindery')->vendor_code);
        $this->assertSame(['active', 'company'], [$paper->status->notation, $paper->vendorType->notation]);
        $this->assertSame(['binding', 'conservation'], $paper->services()->with('serviceType')->get()->map(fn ($s) => $s->serviceType->notation)->sort()->values()->all());
        $this->assertSame('62001234567', $paper->fresh()->bank_account_number);
        $raw = DB::table('vendors')->where('id', $paper->id)->value('bank_account_number');
        $this->assertNotSame('62001234567', $raw, 'encrypted in the table');
        $this->assertStringNotContainsString('62001234567', DB::table('audit_log')->where('subject_table', 'vendors')->get(['before', 'after'])->toJson(), 'and never in the audit log');
        $this->assertSame(2, DB::table('audit_log')->where('subject_table', 'vendors')->count(), 'each vendor made is audited');
        $this->refused(fn () => app(Vendors::class)->create($paper->agent, [], $this->editor), 'a vendor already');
        $this->refused(fn () => app(Vendors::class)->update($paper, ['quality_rating' => '7'], $this->editor), 'from 1 to 5');

        // A vendor that has done work is made inactive, not deleted.
        app(Vendors::class)->request($paper, ['service_type' => 'binding'], $this->editor);
        $this->refused(fn () => app(Vendors::class)->delete($paper, $this->editor), 'make it inactive');
    }

    public function test_work_is_sent_approved_insured_valued_and_once_and_each_change_is_kept(): void
    {
        $vendors = app(Vendors::class);
        $paper = $this->vendor('Paper Conservation Studio');
        $this->refused(fn () => $vendors->request($paper, ['service_type' => 'framing'], $this->editor), 'does not offer');
        $work = $vendors->request($paper, ['service_type' => 'conservation', 'request_date' => '2026-11-03'], $this->editor);
        $this->assertSame('TXN-'.now()->format('Ym').'-0001', $work->transaction_number);
        $this->assertSame('2026-12-03', $work->expected_return_date->toDateString(), 'conservation usually takes 30 days');

        // Not sent before it is approved, nor without records.
        $this->refused(fn () => $vendors->setStatus($work, 'dispatched', null, $this->editor), 'Approve the work');
        $vendors->setStatus($work, 'approved', 'Quote accepted', $this->editor);
        $work->refresh();
        $this->assertSame([now()->toDateString(), $this->editor->id], [$work->approval_date->toDateString(), $work->approved_by_id]);
        $this->refused(fn () => $vendors->setStatus($work, 'dispatched', null, $this->editor), 'Add the records');

        // Conservation needs insurance and a valuation.
        $vendors->addItem($work, $this->map, ['condition_before_grade' => 'poor', 'condition_before' => 'Torn along the fold'], $this->editor);
        $this->refused(fn () => $vendors->setStatus($work->refresh(), 'dispatched', null, $this->editor), 'needs insurance');
        $vendors->updateTransaction($work, ['insurance_arranged' => 1, 'insurance_reference' => 'TRANSIT-77'], $this->editor);
        $this->refused(fn () => $vendors->setStatus($work->refresh(), 'dispatched', null, $this->editor), 'declared value');
        $vendors->updateItem($work->items()->sole(), ['condition_before_grade' => 'poor', 'condition_before' => 'Torn along the fold', 'declared_value' => '12000'], $this->editor);
        $vendors->setStatus($work->refresh(), 'dispatched', 'Courier collected', $this->editor);
        $this->assertNotNull($work->items()->sole()->dispatched_at);

        // A record out with one vendor is not sent with other work.
        $bindery = $this->vendor('Bindery');
        $other = $vendors->request($bindery, ['service_type' => 'binding'], $this->editor);
        $this->refused(fn () => $vendors->addItem($other, $this->map, [], $this->editor), 'out with a vendor already, under '.$work->transaction_number);
        $vendors->addItem($other, $this->volume, [], $this->editor);
        $this->refused(fn () => $vendors->removeItem($work->items()->sole(), $this->editor), 'was sent');

        // Brought back: dated, received by whom, and over.
        $vendors->setStatus($work->refresh(), 'returned', null, $this->editor);
        $work->refresh();
        $this->assertSame($this->editor->id, $work->received_by_id);
        $this->assertNotNull($work->items()->sole()->returned_at);
        $this->assertSame(['pending-approval', 'approved', 'dispatched', 'returned'], $work->history()->reorder('created_at')->with('to')->get()->map(fn ($h) => $h->to->notation)->all());
        $this->refused(fn () => $vendors->setStatus($work, 'in-progress', null, $this->editor), 'The work is over');
        $vendors->addItem($other, $this->map, [], $this->editor);
        $this->assertSame(1, $vendors->figures($paper)['completed']);

        // Inactive, the vendor gets no new work.
        $vendors->update($paper, ['status' => 'inactive'], $this->editor);
        $this->refused(fn () => $vendors->request($paper->fresh(), ['service_type' => 'conservation'], $this->editor), 'active vendor');
    }

    public function test_the_pages_and_documents_are_for_who_may_read_vendors(): void
    {
        $paper = $this->vendor('Paper Conservation Studio', ['bank_account_number' => '62001234567']);
        $work = app(Vendors::class)->request($paper, ['service_type' => 'binding'], $this->editor);
        $as = $this->actingAs($this->editor);
        $as->get(route('vendors.index'))->assertOk()->assertSee($work->transaction_number);
        $as->get(route('vendors.list', ['service' => 'binding']))->assertOk()->assertSee('Paper Conservation Studio');
        $as->get(route('vendors.list', ['service' => 'framing']))->assertOk()->assertDontSee('Paper Conservation Studio');
        $as->get(route('vendors.show', $paper))->assertOk()->assertSee($paper->vendor_code)->assertSee('account ending 4567')->assertDontSee('62001234567');
        $as->get(route('vendors.transaction', [$work, 'q' => 'commonage']))->assertOk()->assertSee('Map of the commonage, 1902');
        $as->post(route('vendors.items.add', $work), ['record' => $this->map->id, 'condition_before_grade' => 'fair'])->assertRedirect(route('vendors.transaction', $work));
        $as->post(route('vendors.attach', $work), ['type' => 'quote', 'file' => UploadedFile::fake()->createWithContent('quote.txt', 'Rebind: R 900')])->assertSessionHas('status', 'Document kept.');
        $document = $work->attachments()->sole();
        $as->get(route('vendors.attachment', [$work, $document]))->assertOk();
        $as->get(route('agents.show', $paper->agent))->assertOk()->assertSee($paper->vendor_code);
        $as->get(route('vendors.services'))->assertOk()->assertSee('Deacidification');
        $as->post(route('vendors.services.save'), ['services' => ['binding' => ['typical_duration_days' => '10', 'is_active' => 1]]])->assertRedirect();
        $this->assertSame(10, app(Vendors::class)->services($this->institution->id)->first(fn ($s) => $s->concept->notation === 'binding')->typical_duration_days);
        $as->post(route('vendors.store'), ['new_name' => 'Cape Imaging Bureau', 'vendor_type' => 'company', 'services' => ['digitisation' => ['offered' => 1]]])->assertRedirect();
        $this->assertSame(2, Vendor::count());

        // A contributor may not read vendors.
        $contributor = app(AccountService::class)->create($this->institution, 'Contributor', 'contributor@example.org', 'correct horse battery staple');
        app(AccountService::class)->grantTemplateRole($contributor, 'contributor');
        $this->actingAs($contributor)->get(route('vendors.index'))->assertForbidden();
        $this->actingAs($contributor)->get(route('vendors.transaction', $work))->assertForbidden();
        $this->actingAs($contributor)->get(route('agents.show', $paper->agent))->assertOk()->assertDontSee($paper->vendor_code);
        $this->assertSame(1, VendorTransaction::count());
    }
}
