<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Business\Page;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AppIdService;
use App\Services\PageRanker;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who comes first in a search: the plan, then the team's boost, then the
 * days the page was worked on. And the day's allowance of connection
 * requests, and one switch for many pages.
 */
class RankingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
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

    private function pageFor(User $u, string $name): Page
    {
        $slug = $this->as($u)->postJson('/api/v1/business/page', ['name' => $name, 'country' => 'IN', 'keywords' => ['brass', 'handicraft']])->assertCreated()->json('data.slug');

        return Page::where('slug', $slug)->firstOrFail();
    }

    private function onPlan(User $u, string $slug): void
    {
        Subscription::create(['user_id' => $u->id, 'plan_id' => Plan::where('slug', $slug)->value('id'), 'status' => 'active', 'started_at' => now()]);
    }

    private function searchOrder(): array
    {
        return array_column($this->getJson('/api/v1/trade/search?q=brass')->assertOk()->json('data.pages'), 'slug');
    }

    public function test_plan_then_boost_then_days_worked_decide_the_order(): void
    {
        [$u1, $u2, $u3, $u4] = [$this->person('one'), $this->person('two'), $this->person('three'), $this->person('four')];
        $p1 = $this->pageFor($u1, 'One Brass Works');   // free, but works on it every day
        $p2 = $this->pageFor($u2, 'Two Brass Works');   // gold
        $p3 = $this->pageFor($u3, 'Three Brass Works'); // platinum
        $p4 = $this->pageFor($u4, 'Four Brass Works');  // diamond
        $this->onPlan($u2, 'gold');
        $this->onPlan($u3, 'platinum');
        $this->onPlan($u4, 'diamond');
        foreach (range(1, 10) as $d) {
            DB::table('business_page_activity')->insert(['page_id' => $p1->id, 'day' => now()->subDays($d)->toDateString()]);
        }
        $this->artisan('trade:rank')->assertSuccessful();

        // Condition 2: Platinum, Diamond, Gold, then the free page however hard it works.
        $this->assertSame([$p3->slug, $p4->slug, $p2->slug, $p1->slug], $this->searchOrder());

        // Condition 3: two on Platinum — the team's boost decides.
        $this->onPlan($u1, 'platinum');
        $admin = $this->person('boss');
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $this->as($admin)->putJson("/api/v1/admin/business/outreach/pages/{$p1->uuid}", ['rank_boost' => 10])->assertOk()->assertJsonPath('data.rank_boost', 10);
        $this->artisan('trade:rank')->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->assertSame([$p1->slug, $p3->slug, $p4->slug, $p2->slug], $this->searchOrder());
        $row = collect($this->as($admin)->getJson('/api/v1/admin/business/outreach/pages?sort=rank')->assertOk()->json('data'))->firstWhere('slug', $p1->slug);
        $this->assertSame('Platinum', $row['rank_tier_label']);
        $this->assertSame(10, $row['activity_score']);

        // Condition 1: same plan, no boost — the page worked on comes first, and working on it counts today.
        $this->as($admin)->putJson("/api/v1/admin/business/outreach/pages/{$p1->uuid}", ['rank_boost' => 0])->assertOk();
        $this->artisan('trade:rank')->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->assertSame([$p1->slug, $p3->slug], array_slice($this->searchOrder(), 0, 2), 'Both on Platinum, no boost: the one worked on for ten days comes first.');
        $this->as($u3)->postJson('/api/v1/business/products', ['name' => 'Brass Diya', 'price_type' => 'on_request', 'currency' => 'USD', 'keywords' => ['brass'], 'category' => 'Handicrafts & Home Décor'])->assertCreated();
        $this->assertSame(1, $p3->fresh()->activity_score, 'Adding a product counts as a day worked, at once.');
        $this->assertSame(10, PageRanker::activityOf($p1));
    }

    public function test_so_many_connection_requests_a_day(): void
    {
        $me = $this->person('sender');
        $a = $this->person('alpha');
        $b = $this->person('beta');
        $c = $this->person('gamma');
        AppSetting::set('connection_daily_limit', '1');
        $this->as($me)->postJson('/api/v1/connections', ['app_id' => $a->username])->assertCreated();
        $this->as($me)->postJson('/api/v1/connections', ['app_id' => $b->username])->assertStatus(429);

        // This account's own number, set by the team.
        $admin = $this->person('boss');
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $this->as($admin)->putJson("/api/v1/admin/users/{$me->uuid}/features", ['connection_daily_limit' => 3])->assertOk()->assertJsonPath('data.blocks.connection_daily_limit', 3);
        $this->as($me)->postJson('/api/v1/connections', ['app_id' => $b->username])->assertCreated();
        $this->as($me)->postJson('/api/v1/connections', ['app_id' => $c->username])->assertCreated();
        $this->as($me)->postJson('/api/v1/connections', ['app_id' => $admin->username])->assertStatus(429);
    }

    public function test_one_switch_for_many_pages(): void
    {
        $p1 = $this->pageFor($this->person('one'), 'One Brass Works');
        $p2 = $this->pageFor($this->person('two'), 'Two Brass Works');
        $p3 = $this->pageFor($this->person('three'), 'Three Copper Works');
        $admin = $this->person('boss');
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $this->as($admin)->putJson('/api/v1/admin/business/outreach/pages/bulk', ['all' => true, 'q' => 'brass', 'calls_disabled' => true])->assertOk()->assertJsonPath('data.pages', 2);
        $this->assertTrue($p1->fresh()->calls_disabled);
        $this->assertTrue($p2->fresh()->calls_disabled);
        $this->assertFalse($p3->fresh()->calls_disabled);
        $this->as($admin)->putJson('/api/v1/admin/business/outreach/pages/bulk', ['uuids' => [$p1->uuid, $p3->uuid], 'meetings_disabled' => true, 'calls_disabled' => false])->assertOk()->assertJsonPath('data.pages', 2);
        $this->assertFalse($p1->fresh()->calls_disabled);
        $this->assertTrue($p1->fresh()->meetings_disabled);
        $this->assertTrue($p3->fresh()->meetings_disabled);
        $this->assertTrue($p2->fresh()->calls_disabled);
        $this->as($admin)->putJson('/api/v1/admin/business/outreach/pages/bulk', ['all' => true])->assertStatus(422);
    }
}
