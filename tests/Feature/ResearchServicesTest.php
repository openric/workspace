<?php

// OpenRiC Workspace - research services, part 19a (roadmap phase 19, ADR 0017): favourites and their folders and
// exports, share links that never open more than their issuer may read, feedback from visitors, and the cart and
// the shop with PayFast's notification trusted only once verified.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\Accounts\AccountService;
use App\Description\InstantiationService;
use App\Description\PublicationService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\AccessRequest;
use App\Models\CartItem;
use App\Models\Favourite;
use App\Models\FavouriteFolder;
use App\Models\Feedback;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductType;
use App\Models\RecordResource;
use App\Models\ShareLink;
use App\Models\User;
use App\Research\Shop;
use App\Vocabulary\SkosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class ResearchServicesTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = 'https://workspace.openric.org/vocab/level-of-description/';

    private string $store;

    private Institution $institution;

    private User $admin;

    private User $editor;

    private User $reader;

    private RecordResource $fonds;

    private RecordResource $letter;

    private RecordResource $draft;

    private Instantiation $scan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $this->institution = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($this->institution);
        foreach (['admin' => 'administrator', 'editor' => 'editor', 'reader' => 'signed-in'] as $who => $template) {
            $this->{$who} = app(AccountService::class)->create($this->institution, ucfirst($who), "$who@example.org", 'correct horse battery staple');
            if ($template !== 'signed-in') {
                app(AccountService::class)->grantTemplateRole($this->{$who}, $template);
            }
        }
        $holder = app(RepositoryService::class)->create($this->institution, 'Riverside Archives', 'en', 'RA');
        $this->fonds = app(RecordService::class)->create($this->institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $this->letter = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Letter of 3 May 1902', 'en', $this->fonds, null, '1');
        $this->draft = app(RecordService::class)->create($this->institution, self::LEVEL.'item', 'Minutes not yet published', 'en', $this->fonds, null, '2');
        $image = new Imagick;
        $image->newImage(300, 200, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());
        $this->scan = app(InstantiationService::class)->upload($this->letter, $path, 'letter.png', 'en', 'Scan of the letter', null, 'online', $this->editor);
        foreach ([$holder, $this->fonds, $this->letter, $this->scan] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $this->editor);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    public function test_favourites_are_kept_in_folders_shared_and_exported(): void
    {
        $this->actingAs($this->reader)->post(route('favourites.toggle', ['records', $this->letter->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post(route('favourites.toggle', ['records', $this->draft->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post(route('favourites.toggle', ['records', $this->letter->id]));
        $this->actingAs($this->editor)->post(route('favourites.folders.store'), ['name' => 'Parish letters'])->assertSessionHasNoErrors();
        $folder = FavouriteFolder::where('user_id', $this->editor->id)->sole();
        $this->actingAs($this->editor)->post(route('favourites.bulk'), ['action' => 'move', 'folder_id' => $folder->id,
            'ids' => Favourite::where('user_id', $this->editor->id)->pluck('id')->all()])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->get(route('favourites.index', ['folder' => $folder->id]))->assertOk()->assertSee('Minutes not yet published')->assertSee('PC/1');

        $bib = $this->actingAs($this->editor)->get(route('favourites.export', ['bibtex', 'folder' => $folder->id]))->assertOk()->getContent();
        $this->assertStringContainsString('title = {Letter of 3 May 1902}', $bib);
        $this->assertStringContainsString('note = {Reference code: RA PC/1}', $bib);
        $this->assertStringContainsString("TY  - UNPB\r\nTI  - Letter of 3 May 1902", $this->actingAs($this->editor)->get(route('favourites.export', ['ris']))->getContent());
        $this->assertStringContainsString('Parish letters', $this->actingAs($this->editor)->get(route('favourites.export', ['ead', 'folder' => $folder->id]))->getContent());
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->editor)->get(route('favourites.export', ['pdf']))->getContent());

        // A shared folder shows a visitor only what the public may read.
        $this->actingAs($this->editor)->post(route('favourites.folders.share', $folder), ['days' => 7])->assertSessionHas('share_link');
        $link = session('share_link');
        auth()->logout();
        $this->get($link)->assertOk()->assertSee('Letter of 3 May 1902')->assertDontSee('Minutes not yet published');
        $this->assertSame(1, $folder->fresh()->share_access_count);
        $this->actingAs($this->editor)->post(route('favourites.folders.unshare', $folder));
        auth()->logout();
        $this->get($link)->assertNotFound();

        // Import by IRI, and one's favourites are one's own.
        $this->actingAs($this->reader)->post(route('favourites.clear'));
        $this->actingAs($this->reader)->post(route('favourites.import'), ['iris' => $this->letter->iri."\nhttps://elsewhere.example.org/x"])->assertSessionHas('status', '1 record was added.');
        $this->actingAs($this->reader)->post(route('favourites.folders.share', $folder), ['days' => 7])->assertSessionHasErrors('favourites');
    }

    public function test_a_share_link_opens_a_record_as_its_issuer_reads_it_while_it_lasts(): void
    {
        $this->actingAs($this->reader)->post(route('records.share-links.issue', $this->draft), [])->assertSessionHasErrors('share_link');
        $this->actingAs($this->editor)->post(route('records.share-links.issue', $this->draft), ['expires_on' => now()->addDays(200)->format('Y-m-d')])->assertSessionHasErrors('share_link');
        $this->actingAs($this->editor)->post(route('records.share-links.issue', $this->draft), ['recipient_email' => 'scholar@example.org', 'max_access' => 2])->assertSessionHasNoErrors();
        $draftLink = session('share_link');
        $this->actingAs($this->editor)->post(route('records.share-links.issue', $this->letter), ['allows_download' => '1'])->assertSessionHasNoErrors();
        $letterLink = session('share_link');
        auth()->logout();

        $this->get(route('discover.record', $this->draft->id))->assertNotFound();
        $this->get($draftLink)->assertOk()->assertSee('Minutes not yet published')->assertSee('PC/2');
        $this->get($draftLink)->assertOk();
        $this->get($draftLink)->assertStatus(410)->assertSee('used as often as it may be');
        $this->get($letterLink)->assertOk()->assertSee('letter.png');
        $this->get(route('share-links.file', [substr($letterLink, -48), $this->scan->file('reference')]))->assertOk();
        $this->get(route('share-links.file', [substr($draftLink, -48), $this->scan->file('reference')]))->assertNotFound();

        $link = ShareLink::where('record_resource_id', $this->letter->id)->sole();
        $this->actingAs($this->reader)->post(route('share-links.revoke', $link), ['reason' => 'x'])->assertSessionHasErrors('share_link');
        $this->actingAs($this->editor)->post(route('share-links.revoke', $link), ['reason' => 'Sent to the wrong person'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get($letterLink)->assertStatus(410)->assertSee('revoked');
        $this->get('/shared/'.str_repeat('a', 48))->assertNotFound();
        $this->travel(15)->days();
        $this->artisan('workspace:share-links:prune')->assertSuccessful();
        $this->assertSame(['view', 'view', 'denied-quota', 'auto-expired'], ShareLink::where('record_resource_id', $this->draft->id)->sole()->events()->with('event')->orderBy('happened_at')->get()
            ->map(fn ($e) => $e->event->notation)->all());
        $this->actingAs($this->admin)->get(route('share-links.index'))->assertOk()->assertSee('scholar@example.org')->assertSee('Sent to the wrong person');
    }

    public function test_feedback_is_sent_by_anyone_and_dealt_with_by_staff(): void
    {
        $this->get(route('feedback.create', ['record' => $this->letter->id]))->assertOk()->assertSee('Letter of 3 May 1902');
        $data = ['type' => 'correction', 'subject' => 'Wrong date', 'remarks' => 'The letter is dated 13 May.', 'name' => 'Anna', 'surname' => 'Smit', 'email' => 'anna@example.org', 'record' => $this->letter->id];
        $this->post(route('feedback.store'), $data + ['website' => 'http://spam.example'])->assertRedirect();
        $this->assertSame(0, Feedback::count(), 'a robot filled the hidden field');
        $this->post(route('feedback.store'), $data)->assertRedirect(route('feedback.thanks', ['record' => $this->letter->id]));
        $entry = Feedback::sole();
        $this->assertSame(['correction', 'pending', $this->letter->id], [$entry->type->notation, $entry->status->notation, $entry->record_resource_id]);

        $this->actingAs($this->reader)->get(route('feedback.index'))->assertForbidden();
        $this->actingAs($this->editor)->get(route('feedback.index'))->assertOk()->assertSee('Wrong date');
        $this->actingAs($this->editor)->post(route('feedback.update', $entry), ['status' => 'completed', 'staff_notes' => 'Date corrected.'])->assertSessionHasNoErrors();
        $this->assertSame(['completed', $this->editor->id], [$entry->fresh()->status->notation, $entry->fresh()->completed_by_id]);
    }

    public function test_with_the_shop_closed_the_cart_asks_for_each_record(): void
    {
        $this->post(route('shop.cart.add', $this->draft->id))->assertSessionHasErrors('cart');
        $this->assertSame(0, CartItem::count(), 'a visitor cannot put what the public may not read into the cart');
        $this->actingAs($this->reader)->post(route('shop.cart.add', $this->letter->id))->assertSessionHasNoErrors();
        $this->actingAs($this->reader)->post(route('shop.checkout.store'), [])->assertSessionHasErrors('cart');
        $this->actingAs($this->reader)->post(route('shop.checkout.store'), ['planned_use' => 'A book on the parish.'])->assertRedirect(route('access-requests.mine'));
        $request = AccessRequest::sole();
        $this->assertSame(['publish', $this->letter->id, 'A book on the parish.'], [$request->requestType->notation, $request->record_resource_id, $request->reason]);
        $this->assertSame(0, CartItem::count());
    }

    public function test_an_order_is_priced_paid_through_payfast_and_downloaded(): void
    {
        $shop = app(Shop::class);
        $settings = $shop->settings($this->institution->id);
        $settings->update(['is_enabled' => true, 'payfast_merchant_id' => '10000100', 'payfast_merchant_key' => '46f0cd694581a', 'payfast_passphrase' => 'jt7NOE43FZPn', 'payfast_sandbox' => true]);
        $low = ProductType::where(['institution_id' => $this->institution->id, 'name' => 'Digital Download - Low Resolution'])->sole();
        $free = ProductType::where(['institution_id' => $this->institution->id, 'name' => 'Research Use Only'])->sole();

        // A visitor's cart joins the account on signing in.
        $this->post(route('shop.cart.add', $this->letter->id), ['product_type_id' => $low->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame(1, CartItem::whereNotNull('session_id')->count());
        $this->post(route('sign-in'), ['email' => 'reader@example.org', 'password' => 'correct horse battery staple'])->assertRedirect();
        $this->assertSame([1, $this->reader->id], [CartItem::count(), CartItem::sole()->user_id]);

        $this->actingAs($this->reader)->get(route('shop.cart'))->assertOk()->assertSee('ZAR 100.00')->assertSee('including VAT of ZAR 13.04 (15%)');
        $this->actingAs($this->reader)->post(route('shop.checkout.store'), ['customer_name' => 'Rita Reader', 'customer_email' => 'reader@example.org'])->assertRedirect();
        $order = Order::sole();
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-0001$/', $order->reference);
        $this->assertSame(['pending', '100.00', '13.04'], [$order->status->notation, $order->total, $order->vat_amount]);
        $redirect = $this->actingAs($this->reader)->post(route('shop.orders.pay', $order))->headers->get('Location');
        $this->assertStringStartsWith('https://sandbox.payfast.co.za/eng/process?merchant_id=10000100', $redirect);

        // PayFast's notification: forged, then the wrong amount, then true.
        Http::fake(['sandbox.payfast.co.za/eng/query/validate' => Http::response('VALID')]);
        $itn = ['m_payment_id' => $order->reference, 'pf_payment_id' => '1089250', 'payment_status' => 'COMPLETE', 'amount_gross' => '100.00', 'merchant_id' => '10000100'];
        $this->post(route('shop.payfast.notify'), $itn + ['signature' => 'forged'])->assertOk();
        $wrong = ['amount_gross' => '1.00'] + $itn;
        $this->post(route('shop.payfast.notify'), $wrong + ['signature' => Shop::sign($wrong, 'jt7NOE43FZPn')])->assertOk();
        $this->assertSame('pending', $order->fresh()->status->notation);
        $this->assertSame(['signature', 'amount'], Payment::orderBy('created_at')->pluck('refused_because')->all());
        $this->post(route('shop.payfast.notify'), $itn + ['signature' => Shop::sign($itn, 'jt7NOE43FZPn')])->assertOk();
        $this->assertSame(['paid', '1089250'], [$order->fresh()->status->notation, $order->fresh()->payment_reference]);

        // The paid download: the reference copy, a few times, for the buyer only.
        $item = $order->items()->sole();
        $token = $shop->downloadToken($item);
        $this->actingAs($this->editor)->get(route('shop.download', [$token, $this->scan->file('reference')->id]))->assertNotFound();
        $this->actingAs($this->reader)->get(route('shop.download', [$token, $this->scan->file('reference')->id]))->assertOk();
        $this->actingAs($this->reader)->get(route('shop.download', [$token, $this->scan->file('master')->id]))->assertNotFound();
        $this->actingAs($this->reader)->get(route('shop.orders.show', $order))->assertOk()->assertSee('letter-reference.jpg');

        // A free order is paid at once.
        $this->actingAs($this->reader)->post(route('shop.cart.add', $this->letter->id), ['product_type_id' => $free->id]);
        $this->actingAs($this->reader)->post(route('shop.checkout.store'), ['customer_name' => 'Rita Reader', 'customer_email' => 'reader@example.org']);
        $this->assertSame('paid', Order::orderByDesc('reference')->first()->status->notation);
        $this->actingAs($this->reader)->get(route('shop.orders'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('shop.orders'))->assertOk()->assertSee($order->reference);
        $this->actingAs($this->admin)->post(route('shop.orders.status', $order), ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('shop.orders.status', $order), ['status' => 'pending'])->assertSessionHasErrors('cart');
    }
}
