<?php

namespace Tests\Feature;

use App\Mail\SeededEnquiry;
use App\Models\Business\CompanyMember;
use App\Models\Business\Page;
use App\Models\Role;
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
 * Companies that exist before their people arrive.
 *
 * The research becomes pages; buyers find them; enquiries reach them by
 * email; the right person claims them — at once by email domain, or by a
 * super admin's decision.
 */
class TradeSeedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = $this->person('boss', 'boss@grapout.test');
        $this->admin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);
    }

    private function person(string $username, ?string $email = null, bool $verified = true): User
    {
        $user = User::factory()->create(['name' => ucfirst($username), 'username' => $username, 'email' => $email ?? $username . '@grapout.test', 'email_verified_at' => $verified ? now() : null]);
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

    private function seedCompanies(int $expectCreated = 2, int $expectUpdated = 0): void
    {
        $csv = "ref,name,country,city,kind,tagline,website,email,phone,sells,buys,keywords,markets\n"
            . "GO-1,ABC Hardware Inc,us,Houston,importer,Architectural hardware importer,www.abchardware.example,purchasing@abchardware.example,,,8302 Door handles and hinges; 7318 Screws,door hardware|hinges,US|CA\n"
            . "GO-2,XYZ Hardware Pvt Ltd,IN,Aligarh,manufacturer,,xyzhardware.example,,,830241 Stainless door handles; 830210 Hinges,,brass,GB|AE\n"
            . ",No Country Ltd,,,,,,,,,,,\n";
        $this->as($this->admin)->post('/api/v1/admin/business/seed', ['file' => UploadedFile::fake()->createWithContent('grapout-sep.csv', $csv), 'source' => 'grapout-sep'], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.created', $expectCreated)->assertJsonPath('data.updated', $expectUpdated)->assertJsonPath('data.skipped.0.row', 4);
    }

    public function test_the_research_becomes_unclaimed_pages_the_world_can_read(): void
    {
        $this->seedCompanies();
        $abc = Page::where('seeded_ref', 'GO-1')->firstOrFail();
        $this->assertTrue($abc->isUnclaimed());
        $this->assertSame('US', $abc->country);
        $this->assertSame(['door hardware', 'hinges'], $abc->keywords);
        $this->assertSame('8302', $abc->tradeLines()->where('direction', 'buy')->first()->hs_code);
        $this->assertSame(2, $abc->tradeLines()->count());

        $this->app['auth']->forgetGuards();
        $page = $this->getJson("/api/v1/trade/pages/{$abc->slug}")->assertOk()->json('data');
        $this->assertFalse($page['claimed']);
        $this->assertTrue($page['seeded']);
        $this->assertFalse($page['is_online']);
        $this->assertNull($page['email'], 'Seeded contacts stay private until the company shows them.');
        $this->assertCount(0, $page['team']);
        $this->assertCount(1, $this->getJson('/api/v1/trade/search?q=8302&country=US')->json('data.pages'));

        // Loading the same file again updates rather than duplicates, and never touches a claimed page.
        $this->seedCompanies(expectCreated: 0, expectUpdated: 2);
        $this->assertSame(2, Page::whereNotNull('seeded_source')->count());
        $status = $this->as($this->admin)->getJson('/api/v1/admin/business/seed')->assertOk()->json('data');
        $this->assertSame(2, $status['seeded']);
        $this->assertSame(0, $status['claimed']);
        $this->as($this->person('nobody'))->getJson('/api/v1/admin/business/seed')->assertForbidden();
    }

    public function test_an_enquiry_to_an_unclaimed_page_goes_to_the_company_by_email_and_waits(): void
    {
        Mail::fake();
        $this->seedCompanies();
        $abc = Page::where('seeded_ref', 'GO-1')->firstOrFail();
        $rajesh = $this->person('rajesh');

        $r = $this->as($rajesh)->postJson('/api/v1/business/enquiries', ['page' => $abc->slug, 'message' => 'We manufacture SS door handles, 100k/month. Interested?'])->assertCreated();
        $this->assertStringContainsString('by email', $r->json('message'));
        Mail::assertSent(SeededEnquiry::class, fn (SeededEnquiry $m) => $m->hasTo('purchasing@abchardware.example') && str_contains($m->enquiry->message, '100k/month'));
        $this->as($rajesh)->postJson("/api/v1/business/enquiries/{$r->json('data.uuid')}/chat")->assertStatus(422);

        // A visitor's enquiry goes the same way; a page with no email simply waits.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/trade/enquiries', ['page' => $abc->slug, 'name' => 'Hans', 'email' => 'hans@x.test', 'message' => 'Catalogue please?', 'form_started_at' => (microtime(true) - 30) * 1000])->assertCreated();
        $waiting = \App\Models\Business\Enquiry::latest('id')->first();
        $this->postJson("/api/v1/trade/enquiries/{$waiting->uuid}/confirm", ['code' => $waiting->guest_code])->assertOk();
        Mail::assertSent(SeededEnquiry::class, 2);
        $xyz = Page::where('seeded_ref', 'GO-2')->firstOrFail();
        $this->as($rajesh)->postJson('/api/v1/business/enquiries', ['page' => $xyz->slug, 'message' => 'Hello?'])->assertCreated();
        Mail::assertSent(SeededEnquiry::class, 2);
    }

    public function test_the_company_domain_claims_at_once_and_the_waiting_enquiries_are_there(): void
    {
        Mail::fake();
        Notification::fake();
        $this->seedCompanies();
        $abc = Page::where('seeded_ref', 'GO-1')->firstOrFail();
        $this->as($this->person('rajesh'))->postJson('/api/v1/business/enquiries', ['page' => $abc->slug, 'message' => 'Interested in your requirement.'])->assertCreated();

        // Unverified: no. Gmail: not proof. The company's own domain: yes, at once.
        $john = $this->person('john', 'john@abchardware.example');
        $this->as($this->person('unverified', 'x@abchardware.example', verified: false))->postJson("/api/v1/business/pages/{$abc->slug}/claim")->assertStatus(403);
        $this->as($this->person('gmail', 'abchardware@gmail.com'))->postJson("/api/v1/business/pages/{$abc->slug}/claim")->assertCreated()->assertJsonPath('data.status', 'requested');
        $this->as($john)->postJson("/api/v1/business/pages/{$abc->slug}/claim", ['function' => 'procurement', 'title' => 'Procurement Manager'])->assertOk()->assertJsonPath('data.status', 'claimed');

        $abc->refresh();
        $this->assertNotNull($abc->claimed_at);
        $this->assertSame($john->id, $abc->user_id);
        $this->assertSame(0, CompanyMember::where('is_claim', true)->count(), 'The gmail claim is gone once the domain claim lands.');
        $me = $this->as($john)->getJson('/api/v1/business/me')->assertOk()->json('data');
        $this->assertSame('ABC Hardware Inc', $me['page']['name']);
        $this->assertSame('owner', $me['membership']['role']);
        $this->assertSame(1, $me['counts']['new_enquiries'], 'The enquiry that arrived before the claim is waiting.');
        $this->assertTrue($me['page']['claimed']);

        // Claimed now: nobody else can claim it; the world sees it as run.
        $this->as($this->person('late', 'late@abchardware.example'))->postJson("/api/v1/business/pages/{$abc->slug}/claim")->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->assertTrue($this->getJson("/api/v1/trade/pages/{$abc->slug}")->json('data.claimed'));
    }

    public function test_a_claim_without_the_domain_waits_for_a_super_admin(): void
    {
        Notification::fake();
        $this->seedCompanies();
        $xyz = Page::where('seeded_ref', 'GO-2')->firstOrFail();
        $rajesh = $this->person('rajesh', 'rajesh@gmail.com');
        $this->as($rajesh)->postJson("/api/v1/business/pages/{$xyz->slug}/claim", ['note' => 'I am the export director; GST 09ABCDE1234F1Z5.', 'function' => 'sales'])->assertCreated()->assertJsonPath('data.status', 'requested');
        Notification::assertSentTo($this->admin, SocialNotification::class, fn ($n) => $n->kind === 'company_claim');
        $this->assertNull($this->as($rajesh)->getJson('/api/v1/business/me')->json('data.page'));

        $claims = $this->as($this->admin)->getJson('/api/v1/admin/business/claims')->assertOk()->json('data');
        $this->assertCount(1, $claims);
        $this->assertSame('XYZ Hardware Pvt Ltd', $claims[0]['page']['name']);
        $this->assertSame('rajesh@gmail.com', $claims[0]['user']['email']);
        $this->assertStringContainsString('export director', $claims[0]['note']);

        $this->as($this->admin)->putJson("/api/v1/admin/business/claims/{$claims[0]['id']}", ['decision' => 'approve'])->assertOk();
        Notification::assertSentTo($rajesh, SocialNotification::class, fn ($n) => str_contains($n->message, 'is yours'));
        $this->assertSame('owner', $this->as($rajesh)->getJson('/api/v1/business/me')->json('data.membership.role'));
        $this->assertNotNull($xyz->fresh()->claimed_at);
        $this->assertCount(0, $this->as($this->admin)->getJson('/api/v1/admin/business/claims')->json('data'));

        // A rejected claim tells the claimant why, and leaves the page unclaimed.
        $abc = Page::where('seeded_ref', 'GO-1')->firstOrFail();
        $other = $this->person('other', 'other@yahoo.com');
        $id = $this->as($other)->postJson("/api/v1/business/pages/{$abc->slug}/claim")->assertCreated()->json('data');
        $claimId = CompanyMember::where('is_claim', true)->where('user_id', $other->id)->value('id');
        $this->as($this->admin)->putJson("/api/v1/admin/business/claims/{$claimId}", ['decision' => 'reject', 'note' => 'No link to the company found.'])->assertOk();
        Notification::assertSentTo($other, SocialNotification::class, fn ($n) => str_contains($n->message, 'not approved'));
        $this->assertTrue($abc->fresh()->isUnclaimed());
        $this->assertNotNull($id);
    }
}
