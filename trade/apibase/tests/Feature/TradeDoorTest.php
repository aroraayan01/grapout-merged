<?php

namespace Tests\Feature;

use App\Mail\EnquiryReply;
use App\Models\Business\Enquiry;
use App\Models\Business\Page;
use App\Models\Message;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The trade door: GrapOut Trade without an account.
 *
 * A stranger reads a page and searches; asks with a name and an email;
 * gets the answer by email; and, if they follow the link, becomes a
 * member mid-conversation.
 */
class TradeDoorTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Page $page;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seller = $this->person('seller');
        $this->page = $this->openPage($this->seller);
        $this->product = $this->actingAs($this->seller)->postJson('/api/v1/business/products', [
            'name' => 'Brass Diya Lamp', 'summary' => 'Hand-finished brass diya', 'category' => 'Handicrafts & Home Décor',
            'price_type' => 'range', 'price_min' => 2, 'price_max' => 3, 'currency' => 'USD', 'terms' => ['FOB'],
            'keywords' => ['brass', 'diya', 'lamp'], 'moq' => 500, 'moq_unit' => 'pcs',
        ])->assertCreated()->json('data');
        $this->app['auth']->forgetGuards();
    }

    /** Drop the acting-as session, so the next request is a stranger's. */
    private function forgetGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function person(string $username): User
    {
        $user = User::factory()->create(['name' => ucfirst($username), 'username' => $username, 'email' => $username . '@netvork.test', 'email_verified_at' => now()]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    private function openPage(User $owner): Page
    {
        $slug = $this->actingAs($owner)->postJson('/api/v1/business/page', [
            'name' => 'Sunrise Handicrafts', 'tagline' => 'Brass and copper décor', 'country' => 'IN', 'city' => 'Moradabad',
            'keywords' => ['brass', 'handicraft'], 'email' => 'sales@sunrise.test', 'phone' => '+91 99999 00000', 'website' => 'sunrise.test',
        ])->assertCreated()->json('data.slug');

        return Page::where('slug', $slug)->firstOrFail();
    }

    /** The code from the email, typed back: the enquiry goes through and the visitor gets an account. */
    private function confirmAsk(?Enquiry $e = null)
    {
        $e ??= Enquiry::latest('id')->first();
        $this->app['auth']->forgetGuards();

        return $this->postJson("/api/v1/trade/enquiries/{$e->uuid}/confirm", ['code' => $e->guest_code]);
    }

    /** A guest's enquiry, with the human guard satisfied. */
    private function ask(array $extra = [])
    {
        return $this->postJson('/api/v1/trade/enquiries', $extra + [
            'product' => $this->product['uuid'], 'name' => 'Hans Müller', 'company' => 'Müller Import GmbH', 'email' => 'Hans@mueller.test',
            'country' => 'de', 'message' => 'Price for 2,000 pcs CIF Hamburg?', 'quantity' => '2000 pcs',
            'form_started_at' => (microtime(true) - 30) * 1000,
        ]);
    }

    // --- Reading without an account -----------------------------------------------

    public function test_a_page_and_its_products_read_without_login_and_contacts_are_the_owners_call(): void
    {
        $page = $this->getJson("/api/v1/trade/pages/{$this->page->slug}")->assertOk()->json('data');
        $this->assertSame('Sunrise Handicrafts', $page['name']);
        $this->assertCount(1, $page['products']);
        $this->assertSame('Seller', $page['owner']['name']);
        $this->assertNull($page['owner']['app_id'], 'A stranger is not handed the owner\'s App ID.');
        $this->assertSame('none', $page['owner']['connection']);
        $this->assertNull($page['email']);
        $this->assertNull($page['phone']);
        $this->assertSame('sunrise.test', $page['website']);

        // The owner's switch does nothing until the GrapOut team allows contacts on this page.
        $this->actingAs($this->seller)->putJson('/api/v1/business/page', ['show_contacts' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertNull($this->getJson("/api/v1/trade/pages/{$this->page->slug}")->json('data.email'));
        $this->page->update(['contacts_allowed' => true]);
        $this->actingAs($this->seller)->putJson('/api/v1/business/page', ['show_contacts' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        $page = $this->getJson("/api/v1/trade/pages/{$this->page->slug}")->assertOk()->json('data');
        $this->assertSame('sales@sunrise.test', $page['email']);
        $this->assertSame('+91 99999 00000', $page['phone']);

        $sheet = $this->getJson("/api/v1/trade/products/{$this->product['uuid']}")->assertOk()->json('data');
        $this->assertSame('Brass Diya Lamp', $sheet['name']);
        $this->assertSame(['FOB'], $sheet['terms']);
        $this->assertFalse($sheet['page']['is_following']);

        // A hidden product is not on the public sheet.
        $this->actingAs($this->seller)->putJson("/api/v1/business/products/{$this->product['uuid']}", ['status' => 'hidden'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/trade/products/{$this->product['uuid']}")->assertNotFound();
    }

    public function test_a_stranger_reads_what_the_page_posted(): void
    {
        $this->actingAs($this->seller)->post('/api/v1/posts', ['body' => 'New brass range this month.', 'as_page' => '1'], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->seller)->post('/api/v1/posts', ['body' => 'Just me, not the page.'], ['Accept' => 'application/json'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $rows = $this->getJson("/api/v1/trade/pages/{$this->page->slug}/posts")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('New brass range this month.', $rows[0]['body']);
        $this->assertFalse($rows[0]['liked']);
        $this->assertFalse($rows[0]['is_mine']);
        $this->assertNull($rows[0]['author']['app_id']);
    }

    public function test_a_signed_in_member_on_the_public_page_is_recognised(): void
    {
        $buyer = $this->person('buyer');
        $this->actingAs($buyer)->postJson("/api/v1/business/pages/{$this->page->slug}/follow")->assertOk();
        $token = $buyer->createToken('web')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $page = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/trade/pages/{$this->page->slug}")->assertOk()->json('data');
        $this->assertTrue($page['is_following']);
        $this->assertNotNull($page['owner']['app_id']);
    }

    public function test_the_public_search_and_directory_find_pages_and_products_by_what_they_sell(): void
    {
        $r = $this->getJson('/api/v1/trade/search?q=brass%20lamp')->assertOk()->json('data');
        $this->assertSame('Sunrise Handicrafts', $r['pages'][0]['name']);
        $this->assertSame('Brass Diya Lamp', $r['products'][0]['name']);
        $this->assertSame('Sunrise Handicrafts', $r['products'][0]['page']['name']);

        $this->assertCount(0, $this->getJson('/api/v1/trade/search?q=brass%20-lamp')->assertOk()->json('data.products'));
        $this->assertCount(0, $this->getJson('/api/v1/trade/search?q=brass&country=US')->assertOk()->json('data.pages'));
        $this->assertCount(1, $this->getJson('/api/v1/trade/search?category=' . urlencode('handicrafts & home décor'))->assertOk()->json('data.products'));
        $this->getJson('/api/v1/trade/search?q=b')->assertStatus(422);

        $d = $this->getJson('/api/v1/trade/directory')->assertOk()->json('data');
        // The standard list, the filled shelf first, the empty ones after it.
        $this->assertSame(['name' => 'Handicrafts & Home Décor', 'count' => 1], $d['categories'][0]);
        $this->assertCount(count(\App\Support\TradeCategories::LIST), $d['categories']);
        $this->assertSame(0, $d['categories'][1]['count']);
        $this->assertSame('IN', $d['countries'][0]['iso']);
        $this->assertSame('Brass Diya Lamp', $d['recent'][0]['name']);
        $this->assertSame(1, $d['counts']['pages']);
    }

    // --- Asking without an account -----------------------------------------------

    public function test_a_visitor_asks_and_the_owner_sees_who_and_is_told(): void
    {
        Notification::fake();
        Mail::fake();
        $this->ask()->assertCreated()->assertJsonPath('pending', true);
        $e = Enquiry::first();
        $this->assertSame(8, strlen($e->guest_code));
        Mail::assertSent(\App\Mail\EnquiryCode::class, fn ($m) => $m->hasTo('hans@mueller.test') && $m->code === $e->guest_code);

        // Nothing reaches the company until the code comes back.
        $this->assertCount(0, $this->actingAs($this->seller)->getJson('/api/v1/business/enquiries')->assertOk()->json('data'));
        Notification::assertNothingSent();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/trade/enquiries/{$e->uuid}/confirm", ['code' => 'WRONG123'])->assertStatus(422);

        // The code: the enquiry goes through, and Hans has an account, signed in, on the code as password.
        $c = $this->confirmAsk($e)->assertOk()->json();
        $this->assertFalse($c['existing']);
        $this->assertNotEmpty($c['token']);
        $hans = User::where('email', 'hans@mueller.test')->firstOrFail();
        $this->assertNotNull($hans->email_verified_at);
        $this->assertTrue((bool) $hans->force_password_change, 'Asked to change the temporary password.');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($e->guest_code, $hans->password));
        $this->assertSame('Müller Import GmbH', $hans->profile->company_name);
        Notification::assertSentTo($this->seller, \App\Notifications\SocialNotification::class);
        $this->confirmAsk($e)->assertNotFound();

        $row = $this->actingAs($this->seller)->getJson('/api/v1/business/enquiries')->assertOk()->json('data.0');
        $this->assertFalse($row['is_guest']);
        $this->assertTrue($row['via_email']);
        $this->assertSame('Hans Müller', $row['from']['name']);
        $this->assertSame('hans@mueller.test', $row['guest']['email']);
        $this->assertSame('DE', $row['guest']['country']);
        $this->assertSame('received', $row['direction']);
        $this->assertArrayNotHasKey('guest_token', $row);
        $this->assertSame(1, \App\Models\Business\Product::where('uuid', $this->product['uuid'])->value('enquiry_count'));

        // With an account on the other side, the chat can open.
        $this->actingAs($this->seller)->postJson("/api/v1/business/enquiries/{$row['uuid']}/chat")->assertOk();
    }

    public function test_the_guard_refuses_a_script_and_the_owner_cannot_ask_their_own_page(): void
    {
        $this->ask(['company_website' => 'http://spam.test'])->assertStatus(422);
        $this->ask(['form_started_at' => microtime(true) * 1000])->assertStatus(422);
        $this->ask(['email' => 'not-an-email'])->assertStatus(422);
        $this->assertSame(0, Enquiry::count());

        $token = $this->seller->createToken('web')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/trade/enquiries', [
            'product' => $this->product['uuid'], 'name' => 'Me', 'email' => 'me@x.test', 'message' => 'asking myself', 'form_started_at' => (microtime(true) - 30) * 1000,
        ])->assertStatus(422);
    }

    public function test_a_member_who_asks_through_the_public_form_gets_a_members_enquiry(): void
    {
        $buyer = $this->person('buyer');
        $token = $buyer->createToken('web')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/trade/enquiries', [
            'product' => $this->product['uuid'], 'name' => 'ignored', 'email' => 'ignored@x.test', 'message' => 'Best price for 500?', 'form_started_at' => (microtime(true) - 30) * 1000,
        ])->assertCreated();

        $e = Enquiry::first();
        $this->assertSame($buyer->id, $e->from_user_id);
        $this->assertNull($e->guest_email);
        $this->assertNull($e->guest_token);
    }

    // --- The reply, and the arrival -------------------------------------------------

    public function test_the_owner_replies_by_email_and_in_the_visitors_new_account(): void
    {
        Mail::fake();
        Notification::fake();
        $this->ask()->assertCreated();
        $this->confirmAsk()->assertOk();
        $enquiry = Enquiry::first();
        $hans = User::where('email', 'hans@mueller.test')->firstOrFail();
        $this->app['auth']->forgetGuards();

        // Only the company replies; by email, because that is where Hans asked from.
        $this->actingAs($this->person('nosy'))->postJson("/api/v1/business/enquiries/{$enquiry->uuid}/reply", ['message' => 'hi'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $row = $this->actingAs($this->seller)->postJson("/api/v1/business/enquiries/{$enquiry->uuid}/reply", ['message' => 'USD 2.40 CIF Hamburg for 2,000 pcs, 30 days.'])
            ->assertOk()->json('data');
        $this->assertSame('replied', $row['status']);
        $this->assertSame('USD 2.40 CIF Hamburg for 2,000 pcs, 30 days.', $row['owner_reply']);
        Mail::assertSent(EnquiryReply::class, fn (EnquiryReply $m) => $m->hasTo('hans@mueller.test') && $m->enquiry->is($enquiry));
        Notification::assertSentTo($hans, \App\Notifications\SocialNotification::class, fn ($n) => str_contains($n->message, 'replied'));

        // The email's link leads Hans to sign in; the reply is waiting under Sent.
        $this->app['auth']->forgetGuards();
        $sent = $this->actingAs($hans)->getJson('/api/v1/business/enquiries/sent')->assertOk()->json('data.0');
        $this->assertSame('USD 2.40 CIF Hamburg for 2,000 pcs, 30 days.', $sent['owner_reply']);
        $this->assertFalse($sent['is_guest']);

        // Opening the chat carries the exchange so far into it.
        $conv = $this->actingAs($hans)->postJson("/api/v1/business/enquiries/{$enquiry->uuid}/chat")->assertOk()->json('data.conversation_uuid');
        $enquiry->refresh();
        $bodies = Message::where('conversation_id', $enquiry->conversation_id)->orderBy('id')->pluck('body', 'user_id')->all();
        $this->assertStringContainsString('Price for 2,000 pcs CIF Hamburg?', $bodies[$hans->id]);
        $this->assertSame('USD 2.40 CIF Hamburg for 2,000 pcs, 30 days.', $bodies[$this->seller->id]);
        $this->assertSame($conv, $this->actingAs($hans)->getJson('/api/v1/business/enquiries/sent')->json('data.0.conversation_uuid'));

        // The old continue link still answers, and says the exchange is already somebody's.
        $token = $enquiry->guest_token;
        $this->app['auth']->forgetGuards();
        $this->assertTrue($this->getJson("/api/v1/trade/enquiries/{$token}")->assertOk()->json('data.claimed'));
        $this->actingAs($this->person('other'))->postJson('/api/v1/business/enquiries/claim', ['token' => $token])->assertStatus(409);

        // The same address asking again keeps the one account: no second sign-up.
        $this->app['auth']->forgetGuards();
        $this->ask(['message' => 'And the lead time?'])->assertCreated();
        $this->confirmAsk()->assertOk()->assertJsonPath('existing', true)->assertJsonMissing(['token']);
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['hans@mueller.test'])->count());
        $this->assertSame($hans->id, Enquiry::latest('id')->first()->from_user_id);
    }

    public function test_an_enquiry_sent_by_email_before_joining_belongs_to_the_account_that_verifies_that_address(): void
    {
        $this->ask()->assertCreated();
        $this->assertNull(Enquiry::first()->from_user_id);
        // The seller quotes while the enquiry is still a visitor's.
        $this->actingAs($this->seller)->postJson('/api/v1/business/enquiries/' . Enquiry::first()->uuid . '/quote', ['price' => 2.5, 'currency' => 'USD', 'price_unit' => 'pc'])->assertCreated();
        $this->forgetGuards();

        // Hans joins with the same address, verified.
        $hans = User::factory()->create(['name' => 'Hans', 'username' => 'hans', 'email' => 'hans@mueller.test', 'email_verified_at' => now()]);
        $hans->settings()->create([]);
        $hans->profile()->create(['timezone' => 'Europe/Berlin']);
        app(AppIdService::class)->generateFor($hans);

        $this->forgetGuards();
        $sent = $this->actingAs($hans)->getJson('/api/v1/business/enquiries/sent')->assertOk()->json('data');
        $this->assertCount(1, $sent, 'The enquiry he sent from the landing page is under Sent.');
        $this->assertSame($hans->id, Enquiry::first()->from_user_id);
        $this->assertNotNull(Enquiry::first()->claimed_at);
        $this->assertCount(1, $this->actingAs($hans)->getJson('/api/v1/business/quotes?box=received')->assertOk()->json('data'), 'The quote sent to the visitor is his now.');

        // Somebody else with another address gets nothing.
        $other = $this->person('other');
        $this->forgetGuards();
        $this->actingAs($other)->getJson('/api/v1/business/enquiries/sent')->assertOk()->assertJsonCount(0, 'data');
    }
}
