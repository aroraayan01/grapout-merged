<?php

namespace Tests\Feature;

use App\Models\Business\Page;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The GrapOut team at a company's desk: with a page picked the admin is
 * its owner for everything under Trade; with none, the Trade lists show
 * every page's entries. And the owner's own switches for calls and
 * meetings, under the team's locks.
 */
class ActingPageTest extends TestCase
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

    public function test_the_team_acts_for_a_chosen_page_and_otherwise_sees_every_page(): void
    {
        $admin = $this->person('boss');
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $owner = $this->person('sunrise');
        $other = $this->person('alnoor');
        $slug = $this->as($owner)->postJson('/api/v1/business/page', ['name' => 'Sunrise Handicrafts', 'country' => 'IN', 'keywords' => ['brass']])->assertCreated()->json('data.slug');
        $page = Page::where('slug', $slug)->firstOrFail();
        $slug2 = $this->as($other)->postJson('/api/v1/business/page', ['name' => 'Al Noor Trading', 'country' => 'AE', 'keywords' => ['dates']])->assertCreated()->json('data.slug');
        $this->as($owner)->postJson('/api/v1/requirements', ['title' => 'Brass lamps, 500 pcs', 'keywords' => ['brass'], 'kind' => 'sell', 'description' => 'For export.'])->assertCreated();
        $this->as($other)->postJson('/api/v1/requirements', ['title' => 'Dates, 2 MT', 'keywords' => ['dates'], 'kind' => 'buy', 'description' => 'For import.'])->assertCreated();

        // No page picked: the admin has no page, but "mine" is every page's and the inbox is every page's.
        $this->assertNull($this->as($admin)->getJson('/api/v1/business/me')->assertOk()->json('data.page'));
        $this->assertCount(2, $this->as($admin)->getJson('/api/v1/requirements?mine=1')->assertOk()->json('data'));
        $this->as($admin)->getJson('/api/v1/business/enquiries')->assertOk();
        $this->as($admin)->getJson('/api/v1/business/pipeline')->assertOk();

        // Wearing Sunrise: the admin is its owner — My Company, its opportunities, and posting for it.
        $wear = ['X-Acting-Page' => $page->uuid];
        $me = $this->as($admin)->withHeaders($wear)->getJson('/api/v1/business/me')->assertOk()->json('data');
        $this->assertSame($slug, $me['page']['slug']);
        $this->assertSame('owner', $me['page']['my_role']);
        $this->assertSame('owner', $me['membership']['role']);
        $this->assertTrue($me['membership']['acting']);
        $this->assertSame($owner->uuid, $me['acting_page']['owner_uuid']);
        $mine = $this->as($admin)->withHeaders($wear)->getJson('/api/v1/requirements?mine=1')->assertOk()->json('data');
        $this->assertSame(['Brass lamps, 500 pcs'], array_column($mine, 'title'));
        $posted = $this->as($admin)->withHeaders($wear)->postJson('/api/v1/requirements', ['title' => 'Brass bowls, 1000 pcs', 'keywords' => ['brass'], 'kind' => 'sell', 'description' => 'For export.'])->assertCreated()->json('data');
        $this->assertSame($slug, $posted['page']['slug'], 'Posted for the page, not for the admin.');
        $this->assertCount(2, $this->as($owner)->getJson('/api/v1/requirements?mine=1')->assertOk()->json('data'), "The owner sees it under Mine: it is the page's.");
        $this->as($admin)->withHeaders($wear)->putJson('/api/v1/business/page', ['tagline' => 'Brass since 1982'])->assertOk();
        $this->assertSame('Brass since 1982', $page->fresh()->tagline);
        $this->as($admin)->withHeaders($wear)->getJson('/api/v1/business/enquiries')->assertOk()->assertJsonCount(0, 'data');

        // Only staff wear a page; a member sending the header is still themselves. An unknown page is no page.
        $this->assertSame($slug2, $this->as($other)->withHeaders($wear)->getJson('/api/v1/business/me')->assertOk()->json('data.page.slug'));
        $this->assertNull($this->as($admin)->withHeaders(['X-Acting-Page' => 'nope'])->getJson('/api/v1/business/me')->assertOk()->json('data.page'));
    }

    public function test_an_owner_switches_calls_and_meetings_off_for_the_page(): void
    {
        $owner = $this->person('sunrise');
        $this->as($owner)->postJson('/api/v1/business/page', ['name' => 'Sunrise Handicrafts', 'country' => 'IN', 'keywords' => ['brass']])->assertCreated();
        $this->assertTrue($this->as($owner)->getJson('/api/v1/me')->assertOk()->json('data.features.calls'));
        $this->as($owner)->putJson('/api/v1/business/page', ['accept_calls' => false, 'accept_meetings' => false])->assertOk()
            ->assertJsonPath('data.accept_calls', false)->assertJsonPath('data.accept_meetings', false);
        $features = $this->as($owner)->getJson('/api/v1/me')->assertOk()->json('data.features');
        $this->assertFalse($features['calls']);
        $this->assertFalse($features['meetings']);
        $this->as($owner)->postJson('/api/v1/meetings', ['title' => 'Quick call'])->assertForbidden();
        $this->as($owner)->putJson('/api/v1/business/page', ['accept_meetings' => true])->assertOk();
        $this->assertTrue($this->as($owner)->getJson('/api/v1/me')->assertOk()->json('data.features.meetings'));
    }
}
