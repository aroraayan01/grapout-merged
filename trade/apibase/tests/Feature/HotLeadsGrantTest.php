<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AppIdService;
use App\Services\SubscriptionEntitlementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An admin opening Hot Leads to one account, whatever its plan.
 *
 * Every plan lists Hot Leads, but Free allows no unlocks, so switching the
 * feature on alone would open nothing. A grant lifts the limits too.
 */
class HotLeadsGrantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->person('admin');
        $this->admin->roles()->attach(Role::where('slug', 'admin')->first()->id);

        $this->member = $this->person('member');
    }

    private function person(string $name): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($name), 'username' => $name,
            'email' => "{$name}@grapout.test", 'email_verified_at' => now(),
        ]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    /** The Free plan as seeded: Hot Leads listed, no unlocks allowed. */
    private function onFreePlan(User $user): void
    {
        $plan = Plan::create([
            'slug' => 'free-test', 'name' => 'Free', 'monthly_price' => 0, 'annual_price' => 0,
            'limits' => ['grap_searches_per_day' => 10, 'grap_reveals_per_day' => 0, 'grap_reveals_per_month' => 0],
            'features' => ['grap_leads' => true, 'grap_export' => false],
        ]);
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => 'active', 'started_at' => now()->subDay(),
        ]);
    }

    private function plans(): SubscriptionEntitlementService
    {
        return new SubscriptionEntitlementService;
    }

    public function test_an_admin_grant_lifts_every_hot_leads_limit(): void
    {
        $this->onFreePlan($this->member);

        $this->assertFalse($this->plans()->canRevealGrap($this->member), 'Free allows no unlocks');
        $this->assertFalse($this->plans()->hasFeature($this->member, 'grap_export'));

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->member->uuid}/features", ['grap_leads' => true])
            ->assertOk()
            ->assertJsonPath('data.blocks.grap_leads_granted', true);

        $member = $this->member->fresh();
        $this->assertTrue((bool) $member->grap_leads_granted);
        $this->assertTrue($this->plans()->canRevealGrap($member));
        $this->assertTrue($this->plans()->canSearchGrap($member));
        $this->assertTrue($this->plans()->hasFeature($member, 'grap_export'), 'a grant includes export');
        $this->assertNull($this->plans()->grapLimit($member, 'grap_reveals_per_day'), 'null is unlimited');
    }

    public function test_a_grant_opens_hot_leads_even_with_no_plan_at_all(): void
    {
        $this->assertFalse($this->plans()->hasFeature($this->member, 'grap_leads'));

        $this->member->forceFill(['grap_leads_granted' => true])->save();

        $this->assertTrue($this->plans()->hasFeature($this->member->fresh(), 'grap_leads'));
    }

    public function test_taking_the_grant_away_hands_the_decision_back_to_the_plan(): void
    {
        $this->onFreePlan($this->member);
        $this->member->forceFill(['grap_leads_granted' => true])->save();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/users/{$this->member->uuid}/features", ['grap_leads' => false])
            ->assertOk();

        $this->assertFalse($this->plans()->canRevealGrap($this->member->fresh()));
    }

    public function test_a_member_cannot_grant_themselves_hot_leads(): void
    {
        $this->actingAs($this->member)
            ->putJson("/api/v1/admin/users/{$this->member->uuid}/features", ['grap_leads' => true])
            ->assertForbidden();

        $this->assertFalse((bool) $this->member->fresh()->grap_leads_granted);
    }
}
