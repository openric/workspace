<?php

// OpenRiC Workspace - contact points (ISDIAH 5.2), the control area (5.6), and audited removals.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Accounts\AccountService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\ContactPoint;
use App\Models\Institution;
use App\Models\Repository;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactAndControlTest extends TestCase
{
    use RefreshDatabase;

    private User $archivist;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadBundledVocabularies();
        $accounts = app(AccountService::class);
        $institution = Institution::create(['name' => 'Riverside Parish Council Archives', 'base_iri' => 'https://archives.example.org']);
        $accounts->seedDefaultGrants($institution);
        $this->archivist = $accounts->create($institution, 'An Archivist', 'archivist@example.org', 'correct horse battery staple');
        $accounts->grantRole($this->archivist, Vocab::role('archivist'));
        $this->repository = app(RepositoryService::class)->create($institution, 'Riverside Parish Council Archives', 'en');
    }

    private function url(string $suffix = ''): string
    {
        return "/repositories/{$this->repository->id}$suffix";
    }

    public function test_contact_points_are_added_changed_and_removed(): void
    {
        $this->actingAs($this->archivist)->post($this->url('/contacts'), [
            'label' => 'Reading room', 'street_address' => '1 Church Street', 'city' => 'Riverside', 'country_code' => 'za',
            'email' => 'reading@example.org', 'is_primary' => '1',
        ])->assertRedirect($this->url());
        $first = ContactPoint::firstOrFail();
        $this->assertSame('ZA', $first->country_code);
        $this->assertTrue($first->is_primary);

        $this->actingAs($this->archivist)->post($this->url('/contacts'), ['label' => 'Office', 'telephone' => '+27 11 000 0000', 'is_primary' => '1']);
        $this->assertFalse($first->fresh()->is_primary, 'only one contact point is primary');

        $this->actingAs($this->archivist)->post($this->url("/contacts/{$first->id}"), ['label' => 'Reading room', 'city' => 'Riverside East', 'is_primary' => '0']);
        $this->assertSame('Riverside East', $first->fresh()->city);
        $this->assertNull($first->fresh()->street_address, 'a field left empty is cleared');

        $this->actingAs($this->archivist)->post($this->url("/contacts/{$first->id}/remove"))->assertRedirect($this->url());
        $this->assertNull(ContactPoint::find($first->id));
        $removed = DB::table('audit_log as a')->join('concepts as c', 'c.id', '=', 'a.action_id')
            ->where('c.notation', 'delete')->where('a.subject_table', 'contact_points')->first();
        $this->assertSame('Riverside East', json_decode($removed->before, true)['city']);
    }

    public function test_a_country_code_must_be_two_letters(): void
    {
        $this->actingAs($this->archivist)->post($this->url('/contacts'), ['country_code' => 'ZAF'])->assertSessionHasErrors('country_code');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('contact_points_country_code');
        DB::table('contact_points')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $this->repository->institution_id,
            'subject_table' => 'repositories', 'subject_id' => $this->repository->id, 'country_code' => 'z1']);
    }

    public function test_a_contact_point_of_another_repository_is_not_found(): void
    {
        $other = app(RepositoryService::class)->create($this->repository->institution, 'Second Repository', 'en');
        $this->actingAs($this->archivist)->post("/repositories/{$other->id}/contacts", ['label' => 'Theirs']);
        $theirs = ContactPoint::firstOrFail();

        $this->actingAs($this->archivist)->post($this->url("/contacts/{$theirs->id}/remove"))->assertNotFound();
        $this->assertNotNull(ContactPoint::find($theirs->id));
    }

    public function test_the_control_area_takes_concepts_from_its_vocabularies(): void
    {
        $this->actingAs($this->archivist)->post($this->url(), [
            'language' => 'en', 'description_identifier' => 'ZA-RPC-DESC-1',
            'description_status' => Vocab::DESCRIPTION_STATUSES.'final', 'description_detail' => Vocab::DESCRIPTION_DETAILS.'partial',
            'values' => ['rules-conventions' => 'ISDIAH, first edition (2008).'],
        ])->assertRedirect($this->url());

        $repository = $this->repository->fresh();
        $this->assertSame('ZA-RPC-DESC-1', $repository->description_identifier);
        $this->assertSame(Vocab::DESCRIPTION_STATUSES.'final', $repository->descriptionStatus->iri);
        $this->assertSame('ISDIAH, first edition (2008).', $repository->described('rules-conventions', ['en']));

        $this->actingAs($this->archivist)->post($this->url(), ['language' => 'en', 'description_status' => Vocab::role('archivist')])
            ->assertSessionHasErrors('description_status');
    }

    public function test_the_database_refuses_a_status_from_the_wrong_vocabulary(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('must be a concept of');
        $this->repository->update(['description_status_id' => Concept::where('iri', Vocab::DESCRIPTION_DETAILS.'full')->value('id')]);
    }

    public function test_an_emptied_value_is_kept_in_the_audit_log(): void
    {
        $service = app(RepositoryService::class);
        $service->describe($this->repository, ['history' => 'Founded in 1902.'], 'en');
        $service->describe($this->repository, ['history' => ''], 'en');

        $removed = DB::table('audit_log as a')->join('concepts as c', 'c.id', '=', 'a.action_id')
            ->where('c.notation', 'delete')->where('a.subject_table', 'description_values')->first();
        $this->assertSame('Founded in 1902.', json_decode($removed->before, true)['value']);
    }
}
