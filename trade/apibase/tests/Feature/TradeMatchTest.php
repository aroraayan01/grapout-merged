<?php

namespace Tests\Feature;

use App\Models\Business\Page;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Why two companies should talk, and who to talk to.
 *
 * Reasons, not a percentage. HS heading first, then the open opportunity,
 * then words and markets. The person named is the one whose function
 * faces the other side.
 */
class TradeMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function person(string $username, ?string $function = null): User
    {
        $user = User::factory()->create(['name' => ucfirst($username), 'username' => $username, 'email' => $username . '@grapout.test', 'email_verified_at' => now()]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata', 'role_function' => $function]);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u->fresh());
    }

    private function company(User $owner, string $name, string $country, array $sells = [], array $buys = [], array $markets = []): Page
    {
        $slug = $this->as($owner)->postJson('/api/v1/business/page', ['name' => $name, 'country' => $country, 'keywords' => [], 'markets' => $markets])->assertCreated()->json('data.slug');
        $lines = array_merge(
            array_map(fn ($l) => ['direction' => 'sell', 'hs_code' => $l[0], 'description' => $l[1]], $sells),
            array_map(fn ($l) => ['direction' => 'buy', 'hs_code' => $l[0], 'description' => $l[1]], $buys),
        );
        if ($lines) {
            $this->as($owner)->putJson('/api/v1/business/page/trade-lines', ['lines' => $lines])->assertOk();
        }

        return Page::where('slug', $slug)->firstOrFail();
    }

    public function test_a_company_sees_the_opportunities_the_buyers_and_the_suppliers_that_fit_with_reasons(): void
    {
        // XYZ sells door handles (8302) and buys stainless sheet (7219), ships to the US already.
        $rajesh = $this->person('rajesh', 'management');
        $this->company($rajesh, 'XYZ Hardware', 'IN', sells: [['830241', 'Stainless steel door handles']], buys: [['7219', 'Stainless steel sheet']], markets: ['US']);
        $priya = $this->person('priya', 'sales');
        $inv = $this->as($rajesh)->postJson('/api/v1/business/page/team/invite', ['identifier' => 'priya', 'function' => 'sales', 'title' => 'Export Manager'])->json('data');
        $this->as($priya)->postJson('/api/v1/business/page/team/accept', ['member_id' => $inv['id']])->assertOk();

        // ABC in the US buys door hardware; John does procurement there.
        $john = $this->person('john', 'procurement');
        $abc = $this->company($john, 'ABC Hardware', 'US', buys: [['8302', 'Architectural door hardware']]);
        $this->as($john)->putJson('/api/v1/business/page', ['verification_status' => 'verified'])->assertOk(); // ignored field, harmless
        $abc->update(['verification_status' => 'verified']);
        $this->as($john)->postJson('/api/v1/requirements', ['kind' => 'buy', 'title' => 'Stainless door handles, 20,000 pcs', 'description' => 'Brushed finish, for US retail chains.', 'keywords' => ['door handle'], 'hs_code' => '830241', 'origin_countries' => ['IN'], 'target_markets' => ['US']])->assertCreated();

        // Steelco in Korea sells stainless sheet; Loom House sells cotton.
        $kim = $this->person('kim', 'sales');
        $this->company($kim, 'Steelco', 'KR', sells: [['721931', 'Cold-rolled stainless sheet 304']]);
        $this->company($this->person('weaver'), 'Loom House', 'IN', sells: [['5208', 'Cotton fabric']]);

        $m = $this->as($rajesh)->getJson('/api/v1/business/matches')->assertOk()->json('data');
        $this->assertTrue($m['has_lines']);

        // The opportunity: ABC's BUY, because XYZ sells 8302 and is in the preferred origin.
        $this->assertCount(1, $m['opportunities']);
        $opp = $m['opportunities'][0];
        $this->assertSame('Stainless door handles, 20,000 pcs', $opp['intent']['title']);
        $kinds = array_column($opp['reasons'], 'kind');
        $this->assertSame('hs', $kinds[0], 'The HS heading is the first reason.');
        $this->assertContains('origin', $kinds);
        $this->assertSame('John', $opp['person']['user']['name']);
        $this->assertSame('procurement', $opp['person']['function']);

        // Buyers: ABC, for its buy line, its open requirement, and the market. Not Loom House.
        $this->assertCount(1, $m['buyers']);
        $buyer = $m['buyers'][0];
        $this->assertSame('ABC Hardware', $buyer['page']['name']);
        $texts = implode(' | ', array_column($buyer['reasons'], 'text'));
        $this->assertStringContainsString('They buy HS 8302', $texts);
        $this->assertStringContainsString('Open buy requirement', $texts);
        $this->assertStringContainsString('Verified company', $texts);
        $this->assertSame('John', $buyer['person']['user']['name']);

        // Suppliers: Steelco, for 7219; the person is whoever does sales there.
        $this->assertCount(1, $m['suppliers']);
        $this->assertSame('Steelco', $m['suppliers'][0]['page']['name']);
        $this->assertSame('sales', $m['suppliers'][0]['person']['function']);
        $this->assertSame('Kim', $m['suppliers'][0]['person']['user']['name']);

        // From ABC's side, the same BUY names XYZ, and the right person is Priya in sales, not the owner.
        $uuid = $this->as($john)->getJson('/api/v1/requirements?mine=1')->json('data.0.uuid');
        $fits = $this->as($john)->getJson("/api/v1/requirements/{$uuid}/matches")->assertOk()->json('data');
        $this->assertCount(1, $fits);
        $this->assertSame('XYZ Hardware', $fits[0]['page']['name']);
        $this->assertSame('Priya', $fits[0]['person']['user']['name']);
        $this->assertSame('Export Manager', $fits[0]['person']['title']);
        $this->assertStringContainsString('Sells HS 830241', $fits[0]['reasons'][0]['text']);
        $this->assertStringContainsString('Already trades with US', implode(' | ', array_column($fits[0]['reasons'], 'text')));

        // Somebody else's requirement is not theirs to see matches for; a company without lines gets none.
        $this->as($rajesh)->getJson("/api/v1/requirements/{$uuid}/matches")->assertForbidden();
        // No company page: still a match desk, run on what this person posted (nothing yet).
        $this->as($this->person('nobody'))->getJson('/api/v1/business/matches')->assertOk()->assertJsonPath('data.has_page', false)->assertJsonCount(0, 'data.suppliers');
    }

    public function test_a_buyer_without_a_company_page_is_matched_on_what_they_posted(): void
    {
        $maker = $this->person('maker', 'sales');
        $this->company($maker, 'XYZ Hardware', 'IN', sells: [['830241', 'Stainless steel door handles']]);
        $other = $this->person('other');
        $this->company($other, 'Loom House', 'IN', sells: [['5208', 'Cotton fabric']]);

        $buyer = $this->person('solo');
        $this->as($buyer)->getJson('/api/v1/business/matches')->assertOk()
            ->assertJsonPath('data.has_page', false)->assertJsonPath('data.has_intents', false)->assertJsonCount(0, 'data.suppliers');

        $this->as($buyer)->postJson('/api/v1/requirements', ['kind' => 'buy', 'title' => 'Stainless door handles, 5,000 pcs', 'description' => 'Brushed finish, for a retail chain.', 'keywords' => ['door handle'], 'hs_code' => '830241'])->assertCreated();
        $r = $this->as($buyer)->getJson('/api/v1/business/matches')->assertOk()
            ->assertJsonPath('data.has_page', false)->assertJsonPath('data.has_intents', true)
            ->assertJsonCount(1, 'data.suppliers')->assertJsonCount(0, 'data.buyers')->assertJsonCount(0, 'data.opportunities');
        $this->assertSame('XYZ Hardware', $r->json('data.suppliers.0.page.name'));
        $this->assertSame('hs', $r->json('data.suppliers.0.reasons.0.kind'));
        $this->assertSame('Maker', $r->json('data.suppliers.0.person.user.name'), 'The person to talk to is the one in sales.');
    }
}
