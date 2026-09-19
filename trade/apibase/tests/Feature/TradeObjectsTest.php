<?php

namespace Tests\Feature;

use App\Models\Business\Page;
use App\Models\Business\Product;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Products and opportunities speak HS.
 *
 * A product carries its code, capacity and markets, and is found by the
 * code. An opportunity is a BUY, a SELL or a PARTNER call; it reaches the
 * companies on the other side of it, by HS heading first, and the world
 * can read it.
 */
class TradeObjectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function person(string $username): User
    {
        $user = User::factory()->create(['name' => ucfirst($username), 'username' => $username, 'email' => $username . '@grapout.test', 'email_verified_at' => now()]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u->fresh());
    }

    /** A company with what it trades. */
    private function company(User $owner, string $name, string $country, array $sells = [], array $buys = []): Page
    {
        $slug = $this->as($owner)->postJson('/api/v1/business/page', ['name' => $name, 'country' => $country, 'keywords' => []])->assertCreated()->json('data.slug');
        $lines = array_merge(
            array_map(fn ($l) => ['direction' => 'sell', 'hs_code' => $l[0], 'description' => $l[1]], $sells),
            array_map(fn ($l) => ['direction' => 'buy', 'hs_code' => $l[0], 'description' => $l[1]], $buys),
        );
        if ($lines) {
            $this->as($owner)->putJson('/api/v1/business/page/trade-lines', ['lines' => $lines])->assertOk();
        }

        return Page::where('slug', $slug)->firstOrFail();
    }

    // --- Products ----------------------------------------------------------------------------------

    public function test_a_product_carries_its_hs_code_capacity_and_markets_and_is_found_by_the_code(): void
    {
        $rajesh = $this->person('rajesh');
        $this->company($rajesh, 'XYZ Hardware', 'IN');
        $p = $this->as($rajesh)->postJson('/api/v1/business/products', [
            'name' => 'Stainless steel door handle', 'price_type' => 'range', 'price_min' => 2, 'price_max' => 3, 'currency' => 'USD',
            'keywords' => ['handle'], 'hs_code' => '8302.41', 'capacity' => '100,000 pcs/month', 'certifications' => ['ISO 9001'], 'export_markets' => ['gb', 'ae'],
        ])->assertCreated()->json('data');
        $this->assertSame('830241', $p['hs_code']);
        $this->assertSame('100,000 pcs/month', $p['capacity']);
        $this->assertSame(['GB', 'AE'], $p['export_markets']);

        $this->app['auth']->forgetGuards();
        $this->assertCount(1, $this->getJson('/api/v1/trade/search?q=8302')->assertOk()->json('data.products'));
        $this->assertCount(0, $this->getJson('/api/v1/trade/search?q=7318')->assertOk()->json('data.products'));

        // The CSV knows the same columns.
        $csv = "name,price_type,price_min,currency,hs_code,capacity,certifications,export_markets\nBrass hinge,fixed,1.2,USD,8302.10,20000 pcs/month,ISO 9001|BIS,US|DE\n";
        $this->as($rajesh)->post('/api/v1/business/products/import', ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)], ['Accept' => 'application/json'])->assertCreated();
        $hinge = Product::where('name', 'Brass hinge')->first();
        $this->assertSame('830210', $hinge->hs_code);
        $this->assertSame(['ISO 9001', 'BIS'], $hinge->certifications);
        $this->assertSame(['US', 'DE'], $hinge->export_markets);
    }

    // --- Intents -------------------------------------------------------------------------------------

    public function test_a_buy_reaches_the_companies_that_sell_it_by_hs_heading_first(): void
    {
        Notification::fake();
        $seller = $this->person('rajesh');
        $this->company($seller, 'XYZ Hardware', 'IN', sells: [['830241', 'Stainless steel door handles']]);
        $anotherBuyer = $this->person('other');
        $this->company($anotherBuyer, 'Other Imports', 'DE', buys: [['8302', 'Door hardware']]);
        $unrelated = $this->person('weaver');
        $this->company($unrelated, 'Loom House', 'IN', sells: [['5208', 'Cotton fabric']]);

        $john = $this->person('john');
        $r = $this->as($john)->postJson('/api/v1/requirements', [
            'kind' => 'buy', 'title' => 'Stainless steel door handles', 'description' => 'Need 20,000 pcs, brushed finish, for the US market.',
            'keywords' => ['door handle'], 'hs_code' => '8302', 'quantity' => 20000, 'quantity_unit' => 'pcs', 'frequency' => 'monthly',
            'origin_countries' => ['in', 'vn'], 'target_markets' => ['us'], 'payment_terms' => 'LC at sight', 'terms' => ['CIF'],
        ])->assertCreated()->json('data');
        $this->assertSame('buy', $r['kind']);
        $this->assertSame('8302', $r['hs_code']);
        $this->assertSame(['IN', 'VN'], $r['origin_countries']);
        $this->assertSame(1, $r['matched_count']);
        Notification::assertSentTo($seller, SocialNotification::class, fn ($n) => $n->kind === 'business_requirement');
        Notification::assertNotSentTo($anotherBuyer, SocialNotification::class);
        Notification::assertNotSentTo($unrelated, SocialNotification::class);
    }

    public function test_a_sell_reaches_the_companies_that_buy_it_and_a_partner_call_reaches_its_markets(): void
    {
        Notification::fake();
        $seller = $this->person('rajesh');
        $this->company($seller, 'XYZ Hardware', 'IN', sells: [['830241', 'Door handles']]);
        $importer = $this->person('john');
        $this->company($importer, 'ABC Hardware', 'US', buys: [['8302', 'Architectural hardware']]);
        $rival = $this->person('rival');
        $this->company($rival, 'Moon Brass', 'IN', sells: [['8302', 'Handles']]);

        // XYZ offers: ABC (a buyer of 8302) hears; Moon Brass (a seller) does not.
        $this->as($seller)->postJson('/api/v1/requirements', ['kind' => 'sell', 'title' => 'Offering door handles', 'description' => 'Stainless 304, 100k/month capacity, ISO 9001.', 'keywords' => ['handles'], 'hs_code' => '830241'])->assertCreated()
            ->assertJsonPath('data.matched_count', 1)->assertJsonPath('data.page.name', 'XYZ Hardware');
        Notification::assertSentTo($importer, SocialNotification::class);
        Notification::assertNotSentTo($rival, SocialNotification::class);
        Notification::assertNotSentTo($seller, SocialNotification::class);

        // XYZ wants a distributor in the US: ABC, in the US and in the trade, hears; Moon Brass in India does not.
        Notification::fake();
        $this->as($seller)->postJson('/api/v1/requirements', ['kind' => 'partner', 'partner_type' => 'distributor', 'title' => 'Distributor wanted, USA', 'description' => 'Exclusive distributor for door hardware in the United States.', 'keywords' => ['hardware'], 'hs_code' => '8302', 'target_markets' => ['US']])->assertCreated()
            ->assertJsonPath('data.partner_type', 'distributor');
        Notification::assertSentTo($importer, SocialNotification::class);
        Notification::assertNotSentTo($rival, SocialNotification::class);
    }

    public function test_opportunities_are_listed_by_kind_searched_by_code_and_read_by_the_world(): void
    {
        $john = $this->person('john');
        $this->as($john)->postJson('/api/v1/requirements', ['kind' => 'buy', 'title' => 'Door handles wanted', 'description' => 'Twenty thousand pieces a month.', 'keywords' => ['handle'], 'hs_code' => '8302'])->assertCreated();
        $rajesh = $this->person('rajesh');
        $this->company($rajesh, 'XYZ Hardware', 'IN');
        $this->as($rajesh)->postJson('/api/v1/requirements', ['kind' => 'sell', 'title' => 'Brass hinges on offer', 'description' => 'Forged brass hinges, 4 inch, polished.', 'keywords' => ['hinge'], 'hs_code' => '830210'])->assertCreated();

        $this->assertCount(2, $this->as($john)->getJson('/api/v1/requirements')->json('data'));
        $this->assertCount(1, $this->as($john)->getJson('/api/v1/requirements?kind=sell')->json('data'));
        $this->assertCount(2, $this->as($john)->getJson('/api/v1/requirements?hs=8302')->json('data'));
        $this->assertCount(1, $this->as($john)->getJson('/api/v1/requirements?q=830210')->json('data'));

        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/v1/trade/requirements?kind=sell')->assertOk()->json('data');
        $this->assertSame('Brass hinges on offer', $public[0]['title']);
        $this->assertSame('XYZ Hardware', $public[0]['page']['name']);
        $this->assertArrayNotHasKey('app_id', $public[0]['buyer']);

        $search = $this->getJson('/api/v1/trade/search?q=8302')->assertOk()->json('data');
        $this->assertCount(2, $search['intents']);
        $home = $this->getJson('/api/v1/trade/directory')->assertOk()->json('data');
        $this->assertCount(2, $home['opportunities']);
        $this->assertSame(2, $home['counts']['opportunities']);
    }
}
