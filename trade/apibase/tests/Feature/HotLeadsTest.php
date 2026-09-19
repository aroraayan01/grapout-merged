<?php

namespace Tests\Feature;

use App\Models\Grap\Lead;
use App\Models\Grap\Reveal;
use App\Models\Grap\SearchLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AppIdService;
use Database\Factories\Grap\LeadFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hot Leads: GrapOut's buyers and suppliers, searched and unlocked.
 *
 * The behaviours worth pinning are the ones the old site got wrong — a
 * row with a phone and no email counting as unreachable, facet counts
 * collapsing the moment a facet is used, and the same contact charging
 * twice.
 */
class HotLeadsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->user = $this->person('scout');
    }

    private function person(string $username): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($username), 'username' => $username,
            'email' => $username . '@grapout.test', 'email_verified_at' => now(),
        ]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    /** Put the user on a plan with these Hot Leads allowances. */
    private function onPlan(?int $searches, ?int $reveals, bool $enabled = true, bool $export = true): void
    {
        $plan = Plan::create([
            'slug' => 'test-' . uniqid(), 'name' => 'Test', 'monthly_price' => 0, 'annual_price' => 0,
            'limits' => [
                'grap_searches_per_day' => $searches,
                'grap_reveals_per_day' => $reveals,
                'grap_reveals_per_month' => $reveals === null ? null : $reveals * 10,
            ],
            'features' => ['grap_leads' => $enabled, 'grap_export' => $export],
        ]);

        Subscription::create([
            'user_id' => $this->user->id, 'plan_id' => $plan->id,
            'status' => 'active', 'started_at' => now()->subDay(),
        ]);
    }

    private function lead(array $attrs = []): Lead
    {
        return LeadFactory::new()->create($attrs + ['kind' => Lead::KIND_BUYER]);
    }

    // --- Searching ---------------------------------------------------------

    public function test_search_returns_leads_of_that_kind_only(): void
    {
        $this->onPlan(null, null);
        $this->lead(['kind' => 'buyer', 'company_name' => 'Acme Importers', 'email' => 'a@acme.com']);
        $this->lead(['kind' => 'supplier', 'company_name' => 'Zenith Exports', 'email' => 'z@zenith.com']);

        $body = $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer')
            ->assertOk()->json();

        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame('Acme Importers', $body['data'][0]['company_name']);
    }

    /**
     * The old site's `email_status not in ('invalid','unknown')` ran against
     * a null status too, so a company with a good phone number and no email
     * on file was hidden entirely.
     */
    public function test_a_lead_with_a_phone_and_no_email_is_still_found(): void
    {
        $this->onPlan(null, null);
        $this->lead([
            'company_name' => 'Phone Only Trading', 'email' => null,
            'phone' => null, 'mobile' => '9812345678', 'dial_code' => '91',
        ]);

        $body = $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer')
            ->assertOk()->json();

        $this->assertSame(1, $body['meta']['total']);
        $this->assertTrue($body['data'][0]['has_phone']);
        $this->assertFalse($body['data'][0]['has_email']);
    }

    public function test_a_lead_with_no_way_to_reach_it_is_not_shown(): void
    {
        $this->onPlan(null, null);
        $this->lead(['company_name' => 'Ghost Ltd', 'email' => null, 'email_2' => null, 'phone' => null, 'mobile' => null]);

        $body = $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer')->assertOk()->json();

        $this->assertSame(0, $body['meta']['total']);
    }

    /** The second person is a way to reach the company too. */
    public function test_a_lead_reachable_only_through_its_second_contact_is_shown(): void
    {
        $this->onPlan(null, null);
        $this->lead([
            'company_name' => 'Accounts Only Ltd', 'email' => null, 'phone' => null, 'mobile' => null,
            'contact_person_2' => 'Aysun', 'email_2' => 'aysun@accountsonly.com',
        ]);

        $this->assertSame(1, $this->actingAs($this->user)
            ->getJson('/api/v1/grap/search?kind=buyer')->assertOk()->json('meta.total'));
    }

    public function test_the_text_box_understands_quotes_and_not(): void
    {
        $this->onPlan(null, null);
        /*
         * Every searched column is pinned, not just the two under test. The
         * factory fills the others with random words, and a random sentence
         * containing "steel" on the brass row makes `lamp NOT steel` exclude
         * it — a failure that appears about one run in fifty and looks like
         * a bug in the parser.
         */
        $quiet = [
            'country' => 'India', 'company_address' => 'Moradabad', 'website' => null,
            'input_name' => null,
        ];
        $this->lead($quiet + ['company_name' => 'Brass Lamp Co', 'business_category' => 'brass lamp fittings', 'brief_intro' => 'Makes lamps.', 'email' => 'a@a.com']);
        $this->lead($quiet + ['company_name' => 'Steel Lamp Co', 'business_category' => 'steel lamp fittings', 'brief_intro' => 'Makes lamps.', 'email' => 'b@b.com']);

        $hits = fn (string $q) => $this->actingAs($this->user)
            ->getJson('/api/v1/grap/search?kind=buyer&q=' . urlencode($q))->assertOk()->json('meta.total');

        $this->assertSame(2, $hits('lamp'), 'plain word');
        $this->assertSame(1, $hits('"brass lamp"'), 'phrase');
        $this->assertSame(1, $hits('lamp NOT steel'), 'negation');
    }

    // --- Facets ------------------------------------------------------------

    /**
     * Each facet is counted with every filter but its own, so choosing one
     * country still leaves the others visible and countable.
     */
    public function test_choosing_a_facet_does_not_empty_that_facet(): void
    {
        $this->onPlan(null, null);
        foreach ([['India', 'Textiles'], ['India', 'Spices'], ['Turkey', 'Textiles']] as [$country, $category]) {
            $this->lead(['country' => $country, 'business_category' => $category, 'email' => uniqid() . '@x.com']);
        }

        $body = $this->actingAs($this->user)
            ->getJson('/api/v1/grap/search?kind=buyer&country[]=India')
            ->assertOk()->json();

        $this->assertSame(2, $body['meta']['total']);

        // Turkey is still offered, with its real count, even though India is on.
        $countries = collect($body['facets']['country'])->pluck('total', 'value');
        $this->assertSame(2, $countries['India']);
        $this->assertSame(1, $countries['Turkey']);

        // The category panel, by contrast, is narrowed by the country choice.
        $categories = collect($body['facets']['category'])->pluck('total', 'value');
        $this->assertSame(1, $categories['Textiles']);
        $this->assertArrayNotHasKey('Ceramics', $categories->all());
    }

    public function test_two_facets_narrow_each_other(): void
    {
        $this->onPlan(null, null);
        $this->lead(['country' => 'India', 'business_category' => 'Textiles', 'email' => 'a@a.com']);
        $this->lead(['country' => 'India', 'business_category' => 'Spices', 'email' => 'b@b.com']);

        $total = $this->actingAs($this->user)
            ->getJson('/api/v1/grap/search?kind=buyer&country[]=India&category[]=Spices')
            ->assertOk()->json('meta.total');

        $this->assertSame(1, $total);
    }

    // --- Masking and revealing ---------------------------------------------

    public function test_contacts_are_masked_until_revealed(): void
    {
        $this->onPlan(null, 5);
        $lead = $this->lead(['email' => 'ravi@acme.com', 'mobile' => '9812345678', 'dial_code' => '91']);

        $row = $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer')
            ->assertOk()->json('data.0');

        $this->assertFalse($row['revealed']);
        $this->assertNotSame('ravi@acme.com', $row['email']);
        $this->assertStringContainsString('@acme.com', $row['email'], 'The domain survives the mask: it is the clue.');
        $this->assertStringNotContainsString('9812345678', (string) $row['mobile']);

        $revealed = $this->actingAs($this->user)
            ->postJson("/api/v1/grap/leads/{$lead->uuid}/reveal")->assertOk()->json('data');

        $this->assertTrue($revealed['revealed']);
        $this->assertSame('ravi@acme.com', $revealed['email']);
        $this->assertStringContainsString('9812345678', $revealed['mobile']);
    }

    public function test_revealing_the_same_lead_twice_is_free(): void
    {
        $this->onPlan(null, 1);
        $lead = $this->lead(['email' => 'a@a.com']);

        $this->actingAs($this->user)->postJson("/api/v1/grap/leads/{$lead->uuid}/reveal")->assertOk();
        // The allowance is one a day and is now spent; asking again for the
        // same lead must still work, because it has already been paid for.
        $this->actingAs($this->user)->postJson("/api/v1/grap/leads/{$lead->uuid}/reveal")->assertOk();

        $this->assertSame(1, Reveal::where('user_id', $this->user->id)->count());
    }

    public function test_a_second_lead_is_refused_once_the_allowance_is_gone(): void
    {
        $this->onPlan(null, 1);
        $first = $this->lead(['email' => 'a@a.com']);
        $second = $this->lead(['email' => 'b@b.com']);

        $this->actingAs($this->user)->postJson("/api/v1/grap/leads/{$first->uuid}/reveal")->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/grap/leads/{$second->uuid}/reveal")->assertStatus(429);
    }

    public function test_a_plan_without_hot_leads_cannot_search_or_reveal(): void
    {
        $this->onPlan(null, null, enabled: false);
        $lead = $this->lead(['email' => 'a@a.com']);

        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer')->assertForbidden();
        $this->actingAs($this->user)->postJson("/api/v1/grap/leads/{$lead->uuid}/reveal")->assertForbidden();
    }

    public function test_the_daily_search_allowance_runs_out(): void
    {
        $this->onPlan(2, null);
        $this->lead(['email' => 'a@a.com']);

        $ask = fn (string $q) => $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer&q=' . $q);

        $ask('lamp')->assertOk();
        $ask('brass')->assertOk();
        $ask('copper')->assertStatus(429);
    }

    /**
     * Opening the tab, and switching between the two tabs, is free.
     *
     * On a plan with one search a day, an unfiltered list must still be
     * readable any number of times — otherwise a Free account is locked out
     * of Hot Leads by looking at it.
     */
    public function test_browsing_with_no_query_does_not_spend_the_allowance(): void
    {
        $this->onPlan(1, null);
        $this->lead(['email' => 'a@a.com']);

        foreach (range(1, 4) as $_) {
            $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer')->assertOk();
            $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=supplier')->assertOk();
        }

        $this->assertSame(0, SearchLog::where('user_id', $this->user->id)->count());
        // And the one real search is still there to be spent.
        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer&q=lamp')->assertOk();
    }

    /** Paging through one search is one search, not one per page. */
    public function test_later_pages_of_a_search_are_not_charged_again(): void
    {
        $this->onPlan(1, null);
        LeadFactory::new()->buyer()->count(25)->create(['email' => 'a@a.com', 'business_category' => 'Lighting']);

        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer&category[]=Lighting')->assertOk();
        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer&category[]=Lighting&page=2')->assertOk();
        // And asking the same thing again later in the day is still free.
        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer&category[]=Lighting')->assertOk();

        $this->assertSame(1, SearchLog::where('user_id', $this->user->id)->count());
    }

    /**
     * Asking for page 2 of a search never made is a new search, and is
     * refused once the allowance is gone — otherwise `&page=2` is a way to
     * read the whole database for nothing.
     */
    public function test_page_two_of_an_unasked_search_is_still_charged(): void
    {
        $this->onPlan(1, null);
        LeadFactory::new()->buyer()->count(25)->create(['email' => 'a@a.com', 'business_category' => 'Lighting']);

        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer&q=lamp')->assertOk();
        $this->actingAs($this->user)
            ->getJson('/api/v1/grap/search?kind=buyer&category[]=Lighting&page=2')
            ->assertStatus(429);
    }

    /** Two facets ticked in either order are one search. */
    public function test_the_order_facets_were_ticked_does_not_make_a_new_search(): void
    {
        $this->onPlan(1, null);
        $this->lead(['country' => 'India', 'business_category' => 'Lighting', 'email' => 'a@a.com']);

        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer&country[]=India&category[]=Lighting')->assertOk();
        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=buyer&category[]=Lighting&country[]=India')->assertOk();

        $this->assertSame(1, SearchLog::where('user_id', $this->user->id)->count());
    }

    public function test_an_unlimited_plan_reports_null_rather_than_zero(): void
    {
        $this->onPlan(null, null);

        $allowance = $this->actingAs($this->user)->getJson('/api/v1/grap/allowance')->assertOk()->json('data');

        $this->assertNull($allowance['searches_left_today']);
        $this->assertNull($allowance['reveals_left_today']);
        $this->assertTrue($allowance['enabled']);
    }

    // --- Lists -------------------------------------------------------------

    public function test_a_list_holds_buyers_and_suppliers_together(): void
    {
        $this->onPlan(null, null);
        $buyer = $this->lead(['kind' => 'buyer', 'email' => 'a@a.com']);
        $supplier = $this->lead(['kind' => 'supplier', 'email' => 'b@b.com']);

        $list = $this->actingAs($this->user)->postJson('/api/v1/grap/lists', ['name' => 'Dubai trip'])
            ->assertCreated()->json('data');

        $this->actingAs($this->user)
            ->postJson("/api/v1/grap/lists/{$list['uuid']}/leads", ['leads' => [$buyer->uuid, $supplier->uuid]])
            ->assertOk();

        $body = $this->actingAs($this->user)->getJson("/api/v1/grap/lists/{$list['uuid']}")->assertOk()->json();
        $this->assertCount(2, $body['leads']);
        $this->assertSame(2, $body['data']['count']);
    }

    public function test_adding_the_same_lead_twice_is_not_an_error(): void
    {
        $this->onPlan(null, null);
        $lead = $this->lead(['email' => 'a@a.com']);
        $list = $this->actingAs($this->user)->postJson('/api/v1/grap/lists', ['name' => 'Keep'])->json('data');

        foreach ([1, 2] as $_) {
            $this->actingAs($this->user)
                ->postJson("/api/v1/grap/lists/{$list['uuid']}/leads", ['leads' => [$lead->uuid]])
                ->assertOk();
        }

        $this->assertSame(1, $this->actingAs($this->user)
            ->getJson("/api/v1/grap/lists/{$list['uuid']}")->json('data.count'));
    }

    public function test_deleting_a_list_keeps_what_was_unlocked(): void
    {
        $this->onPlan(null, 5);
        $lead = $this->lead(['email' => 'a@a.com']);
        $list = $this->actingAs($this->user)->postJson('/api/v1/grap/lists', ['name' => 'Temp'])->json('data');
        $this->actingAs($this->user)->postJson("/api/v1/grap/lists/{$list['uuid']}/leads", ['leads' => [$lead->uuid]]);
        $this->actingAs($this->user)->postJson("/api/v1/grap/leads/{$lead->uuid}/reveal")->assertOk();

        $this->actingAs($this->user)->deleteJson("/api/v1/grap/lists/{$list['uuid']}")->assertOk();

        $this->assertDatabaseHas('grap_reveals', ['user_id' => $this->user->id, 'grap_lead_id' => $lead->id]);
        $this->assertDatabaseHas('grap_leads', ['id' => $lead->id]);
    }

    public function test_one_persons_list_is_invisible_to_another(): void
    {
        $this->onPlan(null, null);
        $list = $this->actingAs($this->user)->postJson('/api/v1/grap/lists', ['name' => 'Mine'])->json('data');

        $other = $this->person('nosy');
        $this->actingAs($other)->getJson("/api/v1/grap/lists/{$list['uuid']}")->assertNotFound();
    }

    public function test_export_is_refused_without_the_feature(): void
    {
        $this->onPlan(null, null, export: false);
        $list = $this->actingAs($this->user)->postJson('/api/v1/grap/lists', ['name' => 'Mine'])->json('data');

        $this->actingAs($this->user)->get("/api/v1/grap/lists/{$list['uuid']}/export")->assertForbidden();
    }

    /** A lead that was never unlocked exports with its contact still hidden. */
    public function test_export_does_not_leak_unrevealed_contacts(): void
    {
        $this->onPlan(null, null);
        $lead = $this->lead(['email' => 'secret@acme.com', 'mobile' => '9812345678']);
        $list = $this->actingAs($this->user)->postJson('/api/v1/grap/lists', ['name' => 'Mine'])->json('data');
        $this->actingAs($this->user)->postJson("/api/v1/grap/lists/{$list['uuid']}/leads", ['leads' => [$lead->uuid]]);

        $csv = $this->actingAs($this->user)->get("/api/v1/grap/lists/{$list['uuid']}/export")
            ->assertOk()->streamedContent();

        $this->assertStringNotContainsString('secret@acme.com', $csv);
        $this->assertStringNotContainsString('9812345678', $csv);
        $this->assertStringContainsString('@acme.com', $csv);
    }

    // --- Importing ---------------------------------------------------------

    /** The 28 columns the research delivers, written as it writes them. */
    private function exportCsv(array $rows): string
    {
        $header = 'ID,Code,"Code 2","Input Name","Company Name","Company ID","Contact Person Name",Designation,'
            . '"Mobile Number","Phone Number","Email Id","Email Catch All","Website Address","Contact Person Name-2",'
            . 'Designation-2,"Mobile Number-2","Phone Number-2","Email Id-2","Email 2 Catch All","Company Address",'
            . '"Country Name","Business Category","Brief Intro of Company","Company LinkedIn URL","Created By",'
            . '"Created On","Updated By","Updated On"';

        $file = tempnam(sys_get_temp_dir(), 'grap') . '.csv';
        file_put_contents($file, $header . "\n" . implode("\n", $rows) . "\n");

        return $file;
    }

    public function test_the_importer_reads_the_export(): void
    {
        $file = $this->exportCsv([
            '296068,,,,"DANTECHS TECHNOLOGY LTD.",0,"Mr. Oguz",Owner,,(+90)2324792737,oguldali@dantechs.net,,'
            . 'dantechs.net,,,,,,,"Izmir Turkey",Turkey,"Industrial machinery","Sells and installs machines.",'
            . 'https://tr.linkedin.com/company/dantechs,,"01/01/1970 01:00",,"14/03/2026 09:30"',
        ]);

        $this->artisan('grap:import', ['file' => $file, '--kind' => 'buyer', '--source' => 'research'])
            ->assertSuccessful();

        $lead = Lead::where('source_ref', '296068')->firstOrFail();
        $this->assertSame(Lead::KIND_BUYER, $lead->kind, 'The file says nothing about kind; --kind does.');
        $this->assertSame('DANTECHS TECHNOLOGY LTD.', $lead->company_name);
        $this->assertSame('Mr. Oguz', $lead->contact_person);
        $this->assertSame('Owner', $lead->designation);
        $this->assertSame('Turkey', $lead->country);
        $this->assertSame('dantechs.net', $lead->website);
        $this->assertSame('Industrial machinery', $lead->business_category);
        $this->assertSame('Izmir Turkey', $lead->company_address);
        $this->assertSame('research', $lead->data_source);

        // The dial code travels inside the number in this export.
        $this->assertSame('90', $lead->dial_code, 'The (+90) prefix becomes the dial code.');
        $this->assertSame('2324792737', $lead->phone, 'And leaves the number dialable.');

        // A Company ID of 0 is the export saying it has not got one, and
        // the epoch is how it writes "never".
        $this->assertNull($lead->company_ref);
        $this->assertNull($lead->source_created_at);
        $this->assertSame('2026-03-14', $lead->source_updated_at?->toDateString(), 'Day first, not month first.');

        unlink($file);
    }

    /** The same file twice corrects rows rather than doubling them. */
    public function test_importing_the_same_file_twice_does_not_duplicate(): void
    {
        $file = $this->exportCsv(['5,,,,"Acme Importers",0,,,,,a@acme.com,,,,,,,,,,,,,,,,,']);

        $this->artisan('grap:import', ['file' => $file, '--kind' => 'buyer', '--source' => 'research'])->assertSuccessful();
        $this->artisan('grap:import', ['file' => $file, '--kind' => 'buyer', '--source' => 'research'])->assertSuccessful();

        $this->assertSame(1, Lead::where('data_source', 'research')->count());

        unlink($file);
    }

    /**
     * One sample file, halved: the first rows become buyers and the rest
     * suppliers. The research proper arrives as two files and needs none
     * of this, but a single sample has to fill both tabs.
     */
    public function test_a_file_can_be_split_between_the_two_kinds(): void
    {
        $rows = [];
        foreach (range(1, 10) as $i) {
            $rows[] = "{$i},,,,\"Company {$i}\",0,,,,,c{$i}@x.com,,,,,,,,,,,,,,,,,";
        }
        $file = $this->exportCsv($rows);

        $this->artisan('grap:import', ['file' => $file, '--kind' => 'buyer', '--source' => 'research', '--split' => 'first'])->assertSuccessful();
        $this->artisan('grap:import', ['file' => $file, '--kind' => 'supplier', '--source' => 'research', '--split' => 'second'])->assertSuccessful();

        $this->assertSame(5, Lead::where('kind', 'buyer')->where('data_source', 'research')->count());
        $this->assertSame(5, Lead::where('kind', 'supplier')->where('data_source', 'research')->count());
        // And no row landed in both halves.
        $this->assertSame(10, Lead::where('data_source', 'research')->count());

        unlink($file);
    }

    /**
     * Half this export has been through UTF-8 twice — Turkish and
     * Vietnamese names arrive as Latin gibberish, which is unreadable and,
     * worse, unsearchable. One row in the sample went through twice over.
     */
    public function test_the_importer_repairs_double_encoded_text(): void
    {
        $mangled = "DANTEEL HOME \xc3\x84\xc2\xb0\xc3\x83\xe2\x80\xa1";
        // Taken byte for byte from the export rather than hand-written: the
        // second pass goes through CP1252, so the intermediate is U+201E and
        // not the U+0084 that a Latin-1 reading would give.
        $twice = "DANYAKIM K\xc3\x83\xe2\x80\x9e\xc3\x82\xc2\xb0MYA";
        $german = "M\xc3\xbcller Handels GmbH";
        $viet = "Vi\xc3\xa1\xc2\xbb\xe2\x80\xa1t Nam";

        $file = $this->exportCsv([
            "1,,,,\"{$mangled}\",0,,,,,a@a.com,,,,,,,,,,\"{$viet}\",,,,,,,",
            "2,,,,\"{$twice}\",0,,,,,b@b.com,,,,,,,,,,Turkey,,,,,,,",
            "3,,,,\"{$german}\",0,,,,,c@c.com,,,,,,,,,,Germany,,,,,,,",
        ]);

        $this->artisan('grap:import', ['file' => $file, '--kind' => 'buyer', '--source' => 'x'])->assertSuccessful();

        $this->assertSame('DANTEEL HOME İÇ', Lead::where('source_ref', '1')->value('company_name'));
        $this->assertSame('Việt Nam', Lead::where('source_ref', '1')->value('country'));
        $this->assertSame('DANYAKIM KİMYA', Lead::where('source_ref', '2')->value('company_name'));
        $this->assertSame('Müller Handels GmbH', Lead::where('source_ref', '3')->value('company_name'), 'A real umlaut is not repaired into something else.');

        unlink($file);
    }

    /** Grap Company is a later conversation, not a third pile of rows. */
    public function test_company_is_not_an_importable_kind(): void
    {
        $file = $this->exportCsv(['1,,,,"Acme",0,,,,,a@a.com,,,,,,,,,,,,,,,,,']);

        $this->artisan('grap:import', ['file' => $file, '--kind' => 'company'])->assertFailed();

        unlink($file);
    }

    public function test_the_search_refuses_a_kind_that_is_not_a_kind(): void
    {
        $this->onPlan(null, null);

        $this->actingAs($this->user)->getJson('/api/v1/grap/search?kind=company')->assertStatus(422);
    }
}
