<?php

namespace Tests\Feature;

use App\Mail\TeamInvite;
use App\Models\Business\CompanyMember;
use App\Models\Business\Page;
use App\Models\HsCode;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A company has people, and says what it buys and sells.
 *
 * The creator owns the page; they invite colleagues, who represent the
 * company without owning it; a colleague can ask to join. What the
 * company trades is a list of lines keyed by HS code, and the search
 * reads them.
 */
class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->owner = $this->person('rajesh');
        $slug = $this->actingAs($this->owner)->postJson('/api/v1/business/page', ['name' => 'XYZ Hardware', 'country' => 'IN', 'kind' => 'manufacturer', 'keywords' => ['hardware']])->assertCreated()->json('data.slug');
        $this->page = Page::where('slug', $slug)->firstOrFail();
        $this->app['auth']->forgetGuards();
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

    // --- The team -----------------------------------------------------------------------------

    public function test_the_creator_owns_the_page_and_a_person_represents_one_company(): void
    {
        $me = $this->as($this->owner)->getJson('/api/v1/business/me')->assertOk()->json('data');
        $this->assertSame('owner', $me['membership']['role']);
        $this->assertSame('owner', $me['page']['my_role']);
        $this->assertCount(1, $me['page']['team']);
        $this->assertTrue($me['page']['is_mine']);

        $this->as($this->owner)->postJson('/api/v1/business/page', ['name' => 'Second'])->assertStatus(422);
    }

    public function test_an_invited_colleague_joins_as_a_representative_and_may_act_but_not_run_the_page(): void
    {
        Notification::fake();
        $priya = $this->person('priya', 'sales');

        $row = $this->as($this->owner)->postJson('/api/v1/business/page/team/invite', ['identifier' => 'priya', 'function' => 'sales', 'title' => 'Export Manager'])->assertCreated()->json('data');
        $this->assertSame('invited', $row['status']);
        Notification::assertSentTo($priya, SocialNotification::class, fn ($n) => $n->kind === 'company_invite');

        // Not on the team yet: no page, and no company to act for.
        $this->assertNull($this->as($priya)->getJson('/api/v1/business/me')->json('data.page'));
        $this->assertCount(1, $this->as($priya)->getJson('/api/v1/business/me')->json('data.invitations'));

        $this->as($priya)->postJson('/api/v1/business/page/team/accept', ['member_id' => $row['id']])->assertOk();
        $me = $this->as($priya)->getJson('/api/v1/business/me')->assertOk()->json('data');
        $this->assertSame('XYZ Hardware', $me['page']['name']);
        $this->assertSame('representative', $me['membership']['role']);
        $this->assertSame('Export Manager', $me['membership']['title']);

        // A representative lists products and answers enquiries...
        $this->as($priya)->postJson('/api/v1/business/products', ['name' => 'Door handle', 'price_type' => 'on_request', 'currency' => 'USD', 'keywords' => ['handle']])->assertCreated();
        // ...but does not change the page, the team, or ask for the badge.
        $this->as($priya)->putJson('/api/v1/business/page', ['tagline' => 'x'])->assertForbidden();
        $this->as($priya)->postJson('/api/v1/business/page/team/invite', ['identifier' => 'rajesh'])->assertForbidden();
        $this->as($priya)->deleteJson('/api/v1/business/page')->assertForbidden();

        // The public page shows the team with what each person does.
        $this->app['auth']->forgetGuards();
        $team = $this->getJson("/api/v1/trade/pages/{$this->page->slug}")->assertOk()->json('data.team');
        $this->assertCount(2, $team);
        $this->assertSame('Sales / Export', $team[1]['function_label']);
        $this->assertSame('Priya', $team[1]['user']['name']);

        // Made admin, she runs the page; then she leaves.
        $this->as($this->owner)->putJson("/api/v1/business/page/team/{$row['id']}", ['role' => 'admin'])->assertOk();
        $this->as($priya)->putJson('/api/v1/business/page', ['tagline' => 'Door hardware since 1998'])->assertOk();
        $this->as($priya)->deleteJson("/api/v1/business/page/team/{$row['id']}")->assertOk();
        $this->assertNull($this->as($priya)->getJson('/api/v1/business/me')->json('data.page'));
    }

    public function test_an_outsider_is_invited_by_email_and_arrives_through_the_link(): void
    {
        Mail::fake();
        $row = $this->as($this->owner)->postJson('/api/v1/business/page/team/invite', ['identifier' => 'amit@example.test', 'role' => 'admin', 'function' => 'procurement'])->assertCreated()->json('data');
        $this->assertSame('amit@example.test', $row['invited_email']);
        Mail::assertSent(TeamInvite::class, fn (TeamInvite $m) => $m->hasTo('amit@example.test'));

        $token = CompanyMember::find($row['id'])->invite_token;
        $this->app['auth']->forgetGuards();
        $peek = $this->getJson("/api/v1/trade/invites/{$token}")->assertOk()->json('data');
        $this->assertSame('XYZ Hardware', $peek['company']['name']);
        $this->assertSame('Rajesh', $peek['inviter']);

        // The wrong person cannot take it; the right one can.
        $this->as($this->person('someone'))->postJson('/api/v1/business/page/team/accept', ['token' => $token])->assertForbidden();
        $amit = User::factory()->create(['name' => 'Amit', 'username' => 'amit', 'email' => 'amit@example.test', 'email_verified_at' => now()]);
        $amit->settings()->create([]);
        $amit->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->as($amit)->postJson('/api/v1/business/page/team/accept', ['token' => $token])->assertOk();
        $this->assertSame('admin', $this->as($amit)->getJson('/api/v1/business/me')->json('data.membership.role'));
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/trade/invites/{$token}")->assertNotFound();
    }

    public function test_a_person_asks_to_join_and_an_admin_approves(): void
    {
        Notification::fake();
        $sunil = $this->person('sunil', 'logistics');
        $row = $this->as($sunil)->postJson("/api/v1/business/pages/{$this->page->slug}/join", ['function' => 'logistics'])->assertCreated()->json('data');
        $this->assertSame('requested', $row['status']);
        Notification::assertSentTo($this->owner, SocialNotification::class, fn ($n) => str_contains($n->message, 'asked to join'));
        $this->assertNull($this->as($sunil)->getJson('/api/v1/business/me')->json('data.page'));

        // The owner sees the request on the team list; the public does not.
        $this->assertCount(2, $this->as($this->owner)->getJson('/api/v1/business/page/team')->json('data'));
        $this->app['auth']->forgetGuards();
        $this->assertCount(1, $this->getJson("/api/v1/trade/pages/{$this->page->slug}")->json('data.team'));

        $this->as($this->owner)->putJson("/api/v1/business/page/team/{$row['id']}", ['status' => 'active'])->assertOk();
        $this->assertSame('XYZ Hardware', $this->as($sunil)->getJson('/api/v1/business/me')->json('data.page.name'));

        // Represented already: cannot ask another company.
        $other = $this->person('other');
        $otherSlug = $this->as($other)->postJson('/api/v1/business/page', ['name' => 'Other Co', 'country' => 'IN'])->assertCreated()->json('data.slug');
        $this->as($sunil)->postJson("/api/v1/business/pages/{$otherSlug}/join")->assertStatus(422);
    }

    public function test_ownership_can_be_handed_over(): void
    {
        $priya = $this->person('priya');
        $row = $this->as($this->owner)->postJson('/api/v1/business/page/team/invite', ['identifier' => 'priya'])->json('data');
        $this->as($priya)->postJson('/api/v1/business/page/team/accept', ['member_id' => $row['id']])->assertOk();
        $this->as($priya)->postJson('/api/v1/business/page/team/transfer', ['member_id' => $row['id']])->assertForbidden();
        $this->as($this->owner)->postJson('/api/v1/business/page/team/transfer', ['member_id' => $row['id']])->assertOk();
        $this->assertSame('owner', $this->as($priya)->getJson('/api/v1/business/me')->json('data.membership.role'));
        $this->assertSame('admin', $this->as($this->owner)->getJson('/api/v1/business/me')->json('data.membership.role'));
        $this->assertSame($priya->id, $this->page->fresh()->user_id);
    }

    // --- Enquiries reach the company, not one person -------------------------------------------

    public function test_an_enquiry_reaches_the_team_and_a_representative_answers_it(): void
    {
        Notification::fake();
        $priya = $this->person('priya', 'sales');
        $row = $this->as($this->owner)->postJson('/api/v1/business/page/team/invite', ['identifier' => 'priya'])->json('data');
        $this->as($priya)->postJson('/api/v1/business/page/team/accept', ['member_id' => $row['id']])->assertOk();
        $buyer = $this->person('john', 'procurement');

        // A team member cannot ask their own company.
        $this->as($priya)->postJson('/api/v1/business/enquiries', ['page' => $this->page->slug, 'message' => 'asking myself'])->assertStatus(422);

        $this->as($buyer)->postJson('/api/v1/business/enquiries', ['page' => $this->page->slug, 'message' => 'Price for 20,000 handles?'])->assertCreated();
        Notification::assertSentTo($this->owner, SocialNotification::class, fn ($n) => $n->kind === 'business_enquiry');
        Notification::assertSentTo($priya, SocialNotification::class, fn ($n) => $n->kind === 'business_enquiry');

        $inbox = $this->as($priya)->getJson('/api/v1/business/enquiries')->assertOk()->json('data');
        $this->assertCount(1, $inbox);
        $conv = $this->as($priya)->postJson("/api/v1/business/enquiries/{$inbox[0]['uuid']}/chat")->assertOk()->json('data.conversation_uuid');
        // The buyer lands in the same chat, with Priya, not with the owner.
        $this->assertSame($conv, $this->as($buyer)->postJson("/api/v1/business/enquiries/{$inbox[0]['uuid']}/chat")->assertOk()->json('data.conversation_uuid'));
    }

    // --- What the company trades ----------------------------------------------------------------------

    public function test_trade_lines_are_keyed_by_hs_code_and_the_search_reads_them(): void
    {
        $this->as($this->owner)->putJson('/api/v1/business/page/trade-lines', ['lines' => [
            ['direction' => 'sell', 'hs_code' => '8302.41', 'description' => 'Stainless steel door handles'],
            ['direction' => 'sell', 'hs_code' => '8302', 'description' => 'Door hinges and fittings'],
            ['direction' => 'buy', 'hs_code' => '7219', 'description' => 'Stainless steel flat-rolled sheet'],
        ]])->assertOk();
        $this->as($this->owner)->putJson('/api/v1/business/page', ['markets' => ['us', 'GB', 'ae'], 'certifications' => ['ISO 9001', 'BIS'], 'year_established' => 1998])->assertOk();

        $this->app['auth']->forgetGuards();
        $page = $this->getJson("/api/v1/trade/pages/{$this->page->slug}")->assertOk()->json('data');
        $this->assertSame('830241', $page['sells'][0]['hs_code']);
        $this->assertSame('7219', $page['buys'][0]['hs_code']);
        $this->assertSame(['US', 'GB', 'AE'], $page['markets']);
        $this->assertSame(1998, $page['year_established']);

        // Found by code, by prefix, and by what the line says.
        $this->assertCount(1, $this->getJson('/api/v1/trade/search?q=8302')->assertOk()->json('data.pages'));
        $this->assertCount(1, $this->getJson('/api/v1/trade/search?q=hinges')->assertOk()->json('data.pages'));
        $this->assertCount(0, $this->getJson('/api/v1/trade/search?q=8544')->assertOk()->json('data.pages'));

        $this->as($this->owner)->putJson('/api/v1/business/page/trade-lines', ['lines' => [['direction' => 'sell', 'hs_code' => 'ABC', 'description' => 'x']]])->assertStatus(422);
    }

    public function test_hs_codes_are_loaded_from_a_file_and_looked_up_never_typed(): void
    {
        $this->app['auth']->forgetGuards();
        $r = $this->getJson('/api/v1/trade/hs?q=8302')->assertOk()->json();
        $this->assertSame([], $r['data']);
        $this->assertFalse($r['loaded']);

        $csv = "code,description\n83,Miscellaneous articles of base metal\n8302,\"Base metal mountings, fittings and similar articles\"\n830241,\"Mountings, fittings and similar articles suitable for buildings\"\n";
        $admin = $this->person('boss');
        $admin->roles()->attach(\App\Models\Role::where('slug', 'super_admin')->first()->id);
        $this->as($admin)->post('/api/v1/admin/business/hs/import', ['file' => UploadedFile::fake()->createWithContent('hs.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(3, HsCode::count());
        $this->assertSame('8302', HsCode::find('830241')->parent);
        $this->assertSame(4, HsCode::find('8302')->level);

        $this->app['auth']->forgetGuards();
        $r = $this->getJson('/api/v1/trade/hs?q=8302')->assertOk()->json();
        $this->assertTrue($r['loaded']);
        $this->assertSame(['8302', '830241'], array_column($r['data'], 'code'));
        $this->assertSame('830241', $this->getJson('/api/v1/trade/hs?q=buildings')->json('data.0.code'));
        $this->as($this->person('nobody'))->post('/api/v1/admin/business/hs/import', ['file' => UploadedFile::fake()->createWithContent('hs.csv', $csv)], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_person_says_what_they_do(): void
    {
        $this->as($this->owner)->putJson('/api/v1/me/profile', ['role_function' => 'procurement', 'designation' => 'Procurement Manager'])->assertOk()
            ->assertJsonPath('data.profile.role_function', 'procurement');
        $this->as($this->owner)->putJson('/api/v1/me/profile', ['role_function' => 'astronaut'])->assertStatus(422);
    }
}
