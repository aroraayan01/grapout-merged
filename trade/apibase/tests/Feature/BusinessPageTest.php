<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Business\Page;
use App\Models\Connection;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GrapOut Trade as an upgrade to the account you already have.
 *
 * A person opens a page beside their profile, puts products on it, and is
 * found by what they do. Buyers ask, show interest, follow, connect — all
 * with the ordinary account, the ordinary chat, the ordinary privacy.
 */
class BusinessPageTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seller = $this->person('seller', 'Importer of brass & handicrafts');
        $this->buyer = $this->person('buyer');
    }

    private function person(string $username, ?string $headline = null): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($username), 'username' => $username,
            'email' => $username . '@netvork.test', 'email_verified_at' => now(),
        ]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata', 'headline' => $headline]);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    private function connect(User $a, User $b): void
    {
        Connection::create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => 'accepted', 'responded_at' => now()]);
    }

    private function openPage(User $owner, string $name = 'Sunrise Handicrafts', array $extra = []): Page
    {
        $slug = $this->actingAs($owner)
            ->postJson('/api/v1/business/page', $extra + ['name' => $name, 'tagline' => 'Brass and copper décor', 'country' => 'IN', 'keywords' => ['brass', 'handicraft']])
            ->assertCreated()->json('data.slug');

        return Page::where('slug', $slug)->firstOrFail();
    }

    private function addProduct(User $as, string $name, array $extra = [])
    {
        return $this->actingAs($as)->postJson('/api/v1/business/products', $extra + [
            'name' => $name, 'summary' => 'Fine ' . $name, 'price_type' => 'range',
            'price_min' => 10, 'price_max' => 12, 'currency' => 'USD', 'terms' => ['FOB', 'CIF'],
            'keywords' => [strtolower($name)], 'moq' => 500, 'moq_unit' => 'pcs',
        ]);
    }

    // --- The page ---------------------------------------------------------------

    public function test_a_person_opens_one_page_and_the_account_knows_it(): void
    {
        $me = $this->actingAs($this->seller)->getJson('/api/v1/business/me')->assertOk()->json('data');
        $this->assertNull($me['page']);
        $this->assertSame('Importer of brass & handicrafts', $me['profile']['headline']);

        $page = $this->openPage($this->seller);
        $this->assertSame($this->seller->id, $page->user_id);

        $me = $this->actingAs($this->seller)->getJson('/api/v1/business/me')->assertOk()->json('data');
        $this->assertSame($page->slug, $me['page']['slug']);
        $this->assertTrue($me['page']['is_mine']);
        $this->assertSame(0, $me['counts']['products']);

        $this->actingAs($this->seller)->postJson('/api/v1/business/page', ['name' => 'Second'])->assertStatus(422);
    }

    public function test_products_carry_price_terms_moq_and_up_to_eight_pictures(): void
    {
        Storage::fake('public');
        $page = $this->openPage($this->seller);
        $p = $this->addProduct($this->seller, 'Brass Lamp')->assertCreated()->json('data');
        $this->assertSame(['FOB', 'CIF'], $p['terms']);
        $this->assertSame('500.00', $p['moq']);

        $files = array_map(fn ($i) => UploadedFile::fake()->image("lamp{$i}.jpg", 1600, 1200), range(1, 8));
        $full = $this->actingAs($this->seller)
            ->post("/api/v1/business/products/{$p['uuid']}/images", ['images' => $files], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $this->assertCount(8, $full['images']);
        Storage::disk('public')->assertExists($full['images'][0]['display']);

        $this->actingAs($this->seller)
            ->post("/api/v1/business/products/{$p['uuid']}/images", ['images' => [UploadedFile::fake()->image('nine.jpg')]], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // The dashboard picture is one of your own product photos, or nothing.
        $this->actingAs($this->seller)->postJson('/api/v1/business/page/hero', ['path' => $full['images'][0]['path']])->assertOk();
        $this->assertSame($full['images'][0]['path'], $page->fresh()->hero_path);
        $this->actingAs($this->seller)->postJson('/api/v1/business/page/hero', ['path' => 'business/pages/999/other.jpg'])->assertStatus(422);

        // A buyer sees it in full, with the owner beside it.
        $sheet = $this->actingAs($this->buyer)->getJson("/api/v1/business/products/{$p['uuid']}")->assertOk()->json('data');
        $this->assertSame('Seller', $sheet['owner']['name']);
        $this->assertSame('none', $sheet['owner']['connection']);
        $this->assertFalse($sheet['is_interested']);
    }

    public function test_closing_the_page_takes_everything_with_it_and_a_new_one_can_open(): void
    {
        Storage::fake('public');
        $page = $this->openPage($this->seller);
        $p = $this->addProduct($this->seller, 'Brass Lamp')->json('data');
        $this->actingAs($this->seller)
            ->post("/api/v1/business/products/{$p['uuid']}/images", ['images' => [UploadedFile::fake()->image('lamp.jpg', 800, 600)]], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->actingAs($this->buyer)->postJson("/api/v1/business/pages/{$page->slug}/follow")->assertOk();

        $this->actingAs($this->seller)->deleteJson('/api/v1/business/page')->assertOk();

        $this->assertNull($this->actingAs($this->seller)->getJson('/api/v1/business/me')->json('data.page'));
        $this->assertDatabaseMissing('business_pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('business_products', ['uuid' => $p['uuid']]);
        $this->assertDatabaseMissing('business_follows', ['page_id' => $page->id]);
        $this->actingAs($this->buyer)->getJson("/api/v1/business/pages/{$page->slug}")->assertNotFound();

        // The brand goes back to plain GrapOut — and a new page can open.
        $this->openPage($this->seller, 'Second Life Exports');
    }

    // --- Being asked ---------------------------------------------------------

    public function test_an_enquiry_reaches_the_owner_and_either_side_can_open_the_chat(): void
    {
        Notification::fake();
        $this->openPage($this->seller);
        $p = $this->addProduct($this->seller, 'Brass Lamp')->json('data');

        $this->actingAs($this->buyer)->postJson('/api/v1/business/enquiries', [
            'product' => $p['uuid'], 'message' => 'Price for 1,000 pcs?', 'quantity' => '1000 pcs',
        ])->assertCreated();
        Notification::assertSentTo($this->seller, \App\Notifications\SocialNotification::class);

        $inbox = $this->actingAs($this->seller)->getJson('/api/v1/business/enquiries')->assertOk()->json('data');
        $this->assertCount(1, $inbox);
        $this->assertSame('Brass Lamp', $inbox[0]['product']['name']);
        $this->assertSame('Buyer', $inbox[0]['from']['name']);
        $this->assertSame('received', $inbox[0]['direction']);

        // Not connected, default privacy — and the chat still opens, because
        // the buyer wrote first. Opening it marks the enquiry answered.
        $conv = $this->actingAs($this->seller)->postJson("/api/v1/business/enquiries/{$inbox[0]['uuid']}/chat")->assertOk()->json('data.conversation_uuid');
        $this->assertNotNull($conv);
        $this->assertSame('replied', $this->actingAs($this->seller)->getJson('/api/v1/business/enquiries')->json('data.0.status'));

        // The buyer sees the same thread from their side.
        $sent = $this->actingAs($this->buyer)->getJson('/api/v1/business/enquiries/sent')->assertOk()->json('data');
        $this->assertSame($conv, $sent[0]['conversation_uuid']);

        // A stranger to the enquiry cannot open its chat.
        $this->actingAs($this->person('nosy'))->postJson("/api/v1/business/enquiries/{$inbox[0]['uuid']}/chat")->assertForbidden();

        // And you cannot ask your own page.
        $this->actingAs($this->seller)->postJson('/api/v1/business/enquiries', ['product' => $p['uuid'], 'message' => 'hi me'])->assertStatus(422);
    }

    public function test_interested_is_one_tap_counted_and_told(): void
    {
        Notification::fake();
        $this->openPage($this->seller);
        $p = $this->addProduct($this->seller, 'Copper Bowl')->json('data');

        $this->actingAs($this->buyer)->postJson("/api/v1/business/products/{$p['uuid']}/interested")->assertOk()->assertJsonPath('data.interested', true);
        $this->assertSame(1, $this->actingAs($this->buyer)->getJson("/api/v1/business/products/{$p['uuid']}")->json('data.interested_count'));
        Notification::assertSentTo($this->seller, \App\Notifications\SocialNotification::class);

        $this->actingAs($this->buyer)->postJson("/api/v1/business/products/{$p['uuid']}/interested")->assertOk()->assertJsonPath('data.interested', false);
        $this->assertSame(0, $this->actingAs($this->buyer)->getJson("/api/v1/business/products/{$p['uuid']}")->json('data.interested_count'));
    }

    public function test_following_a_page_counts_and_tells_the_owner(): void
    {
        Notification::fake();
        $page = $this->openPage($this->seller);

        $this->actingAs($this->buyer)->postJson("/api/v1/business/pages/{$page->slug}/follow")->assertOk()->assertJsonPath('data.followers_count', 1);
        $this->actingAs($this->buyer)->postJson("/api/v1/business/pages/{$page->slug}/follow")->assertOk()->assertJsonPath('data.followers_count', 1);
        $this->assertTrue($this->actingAs($this->buyer)->getJson("/api/v1/business/pages/{$page->slug}")->json('data.is_following'));

        $followers = $this->actingAs($this->seller)->getJson('/api/v1/business/page/followers')->assertOk()->json('data');
        $this->assertSame('Buyer', $followers[0]['name']);

        $this->actingAs($this->buyer)->deleteJson("/api/v1/business/pages/{$page->slug}/follow")->assertOk()->assertJsonPath('data.followers_count', 0);
        $this->actingAs($this->seller)->postJson("/api/v1/business/pages/{$page->slug}/follow")->assertStatus(422);
    }

    // --- The feed --------------------------------------------------------------

    public function test_the_feed_is_your_connections_and_the_pages_you_follow(): void
    {
        Notification::fake();
        $page = $this->openPage($this->seller);
        $stranger = $this->person('stranger');
        $friend = $this->person('friend');
        $this->connect($this->buyer, $friend);
        $this->actingAs($this->buyer)->postJson("/api/v1/business/pages/{$page->slug}/follow")->assertOk();

        $this->actingAs($this->seller)->post('/api/v1/posts', ['body' => 'New brass range out', 'as_page' => 1], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->seller)->post('/api/v1/posts', ['body' => 'Personal note from seller'], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($friend)->post('/api/v1/posts', ['body' => 'Hello from a friend'], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($stranger)->post('/api/v1/posts', ['body' => 'Nobody follows me'], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->buyer)->post('/api/v1/posts', ['body' => 'My own post'], ['Accept' => 'application/json'])->assertCreated();

        $bodies = collect($this->actingAs($this->buyer)->getJson('/api/v1/posts')->assertOk()->json('data'))->pluck('body')->all();

        // The page's post (followed) and the friend's (connected) and my own —
        // not the seller's personal post (not connected), not the stranger's.
        $this->assertContains('New brass range out', $bodies);
        $this->assertContains('Hello from a friend', $bodies);
        $this->assertContains('My own post', $bodies);
        $this->assertNotContains('Personal note from seller', $bodies);
        $this->assertNotContains('Nobody follows me', $bodies);
    }

    public function test_like_comment_repost_and_forward_to_a_connection(): void
    {
        Notification::fake();
        $friend = $this->person('friend');
        $this->connect($this->buyer, $friend);
        $post = $this->actingAs($friend)->post('/api/v1/posts', ['body' => 'Original'], ['Accept' => 'application/json'])->json('data');

        $this->actingAs($this->buyer)->postJson("/api/v1/posts/{$post['uuid']}/like")->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->actingAs($this->buyer)->postJson("/api/v1/posts/{$post['uuid']}/comments", ['body' => 'Nice'])->assertCreated();
        $this->assertSame(1, Post::where('uuid', $post['uuid'])->value('comments_count'));

        $repost = $this->actingAs($this->buyer)->post('/api/v1/posts', ['repost_of' => $post['uuid'], 'body' => 'Look at this'], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame('Original', $repost['repost_of']['body']);
        $this->assertSame(1, Post::where('uuid', $post['uuid'])->value('reposts_count'));

        // Forwarding goes to a connection, in chat; a stranger is refused.
        $this->actingAs($this->buyer)->postJson("/api/v1/posts/{$post['uuid']}/forward", ['identifier' => 'friend'])->assertOk();
        $this->actingAs($this->buyer)->postJson("/api/v1/posts/{$post['uuid']}/forward", ['identifier' => 'seller'])->assertForbidden();

        $thread = $this->actingAs($friend)->getJson('/api/v1/conversations')->json('data');
        $this->assertNotEmpty($thread);
    }

    // --- Being found -----------------------------------------------------------

    public function test_the_network_search_reads_what_people_do_and_what_their_pages_sell(): void
    {
        $this->openPage($this->seller);
        $this->addProduct($this->seller, 'Brass Diya Lamp', ['keywords' => ['diya', 'lamp']])->assertCreated();
        $this->person('steve', 'Exporter of steel pipes');

        $names = fn (string $q) => collect($this->actingAs($this->buyer)->getJson('/api/v1/network/search?q=' . urlencode($q))->assertOk()->json('data'))->pluck('name')->all();

        // Headline words, in any order; the page comes with the person.
        $this->assertSame(['Seller'], $names('import handicraft'));
        $hit = $this->actingAs($this->buyer)->getJson('/api/v1/network/search?q=handicraft')->json('data.0');
        $this->assertSame('Sunrise Handicrafts', $hit['page']['name']);
        // A product keyword finds its seller.
        $this->assertSame(['Seller'], $names('diya'));
        // Boolean: OR, NOT, and a phrase.
        $this->assertEqualsCanonicalizing(['Seller', 'Steve'], $names('brass OR steel'));
        $this->assertSame(['Steve'], $names('exporter NOT brass'));
        $this->assertSame(['Seller'], $names('"brass & handicrafts"'));
        $this->assertSame([], $names('cotton'));
    }

    public function test_the_network_search_respects_who_can_find_me(): void
    {
        $this->seller->settings->update(['privacy' => ['who_can_find_me' => 'nobody']]);
        $this->assertSame([], $this->actingAs($this->buyer)->getJson('/api/v1/network/search?q=handicraft')->json('data'));
    }

    // --- Jobs, switched off -----------------------------------------------------
}
