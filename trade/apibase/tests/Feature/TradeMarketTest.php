<?php

namespace Tests\Feature;

use App\Mail\EnquiryReply;
use App\Models\AppSetting;
use App\Models\Business\Enquiry;
use App\Models\Business\Page;
use App\Models\Business\Quote;
use App\Models\Business\Requirement;
use App\Models\Translation;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Requirements, quotes, trust, and words.
 *
 * A buyer posts what they need and matching suppliers hear. Suppliers
 * answer with quotes the buyer compares and decides on. A page earns a
 * badge from a document and response figures from how it answers. And
 * everything can be read in another language, bought once.
 */
class TradeMarketTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seller = $this->person('seller');
        $this->buyer = $this->person('buyer');
        $this->page = $this->openPage($this->seller, 'Sunrise Handicrafts', ['brass', 'handicraft']);
        $this->actingAs($this->seller)->postJson('/api/v1/business/products', [
            'name' => 'Brass Diya Lamp', 'category' => 'Handicrafts & Home Décor', 'price_type' => 'range', 'price_min' => 2, 'price_max' => 3,
            'currency' => 'USD', 'keywords' => ['brass', 'diya'],
        ])->assertCreated();
    }

    private function person(string $username): User
    {
        $user = User::factory()->create(['name' => ucfirst($username), 'username' => $username, 'email' => $username . '@netvork.test', 'email_verified_at' => now()]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    private function openPage(User $owner, string $name, array $keywords): Page
    {
        $slug = $this->actingAs($owner)->postJson('/api/v1/business/page', ['name' => $name, 'country' => 'IN', 'keywords' => $keywords])->assertCreated()->json('data.slug');
        $this->app['auth']->forgetGuards();

        return Page::where('slug', $slug)->firstOrFail();
    }

    private function requirement(array $extra = [])
    {
        return $this->actingAs($this->buyer)->postJson('/api/v1/requirements', $extra + [
            'title' => 'Brass diyas, 5,000 pcs', 'description' => 'Hand-finished brass diyas, 4 inch, gift boxed. CIF Hamburg.',
            'category' => 'Handicrafts & Home Décor', 'keywords' => ['brass', 'diya'], 'quantity' => 5000, 'quantity_unit' => 'pcs',
            'target_price' => 2.1, 'currency' => 'USD', 'terms' => ['CIF'], 'destination_country' => 'de', 'destination_port' => 'Hamburg',
        ]);
    }

    private function quote(User $as, string $requirementUuid, array $extra = [])
    {
        return $this->actingAs($as)->postJson("/api/v1/requirements/{$requirementUuid}/quotes", $extra + [
            'price' => 2.4, 'currency' => 'USD', 'price_unit' => 'pc', 'moq' => 1000, 'moq_unit' => 'pcs', 'lead_time_days' => 30, 'valid_days' => 15, 'terms' => 'CIF', 'notes' => 'Gift box included.',
        ]);
    }

    // --- Requirements -----------------------------------------------------------------------

    public function test_a_requirement_reaches_the_suppliers_it_fits_and_not_the_others(): void
    {
        Notification::fake();
        $textiles = $this->person('weaver');
        $this->openPage($textiles, 'Loom House', ['cotton', 'yarn']);

        $r = $this->requirement()->assertCreated();
        $this->assertSame('Posted. 1 matching supplier have been told.', $r->json('message'));
        $this->assertSame(1, $r->json('data.matched_count'));
        $this->assertSame('DE', $r->json('data.destination_country'));
        Notification::assertSentTo($this->seller, SocialNotification::class, fn ($n) => $n->kind === 'business_requirement');
        Notification::assertNotSentTo($textiles, SocialNotification::class);
        Notification::assertNotSentTo($this->buyer, SocialNotification::class);

        // Open requirements are listed for members, searchable, and public.
        $this->app['auth']->forgetGuards();
        $this->assertCount(1, $this->actingAs($this->seller)->getJson('/api/v1/requirements?q=brass')->assertOk()->json('data'));
        $this->assertCount(0, $this->actingAs($this->seller)->getJson('/api/v1/requirements?q=cotton')->assertOk()->json('data'));
        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/v1/trade/requirements')->assertOk()->json('data.0');
        $this->assertSame('Brass diyas, 5,000 pcs', $public['title']);
        $this->assertArrayNotHasKey('app_id', $public['buyer']);

        // Closing it takes it off the open list; `mine` still shows it.
        $this->actingAs($this->buyer)->putJson("/api/v1/requirements/{$r->json('data.uuid')}", ['status' => 'closed'])->assertOk();
        $this->assertCount(0, $this->actingAs($this->buyer)->getJson('/api/v1/requirements')->json('data'));
        $this->assertCount(1, $this->actingAs($this->buyer)->getJson('/api/v1/requirements?mine=1')->json('data'));
        $this->actingAs($this->seller)->putJson("/api/v1/requirements/{$r->json('data.uuid')}", ['status' => 'open'])->assertForbidden();
    }

    // --- Quotes ---------------------------------------------------------------------------------

    public function test_suppliers_quote_a_requirement_and_the_buyer_compares_and_decides(): void
    {
        Notification::fake();
        $uuid = $this->requirement()->json('data.uuid');
        $rival = $this->person('rival');
        $this->openPage($rival, 'Moon Brass Works', ['brass']);

        // Only a page can quote; not your own requirement.
        $this->actingAs($this->person('nopage'))->postJson("/api/v1/requirements/{$uuid}/quotes", ['price' => 1, 'currency' => 'USD'])->assertStatus(422);
        $this->actingAs($this->buyer)->postJson("/api/v1/requirements/{$uuid}/quotes", ['price' => 1, 'currency' => 'USD'])->assertStatus(422);

        $q1 = $this->quote($this->seller, $uuid)->assertCreated()->json('data');
        $q2 = $this->quote($rival, $uuid, ['price' => 2.2, 'notes' => null])->assertCreated()->json('data');
        $this->assertSame('USD 2.40 / pc', $q1['price_label']);
        Notification::assertSentTo($this->buyer, SocialNotification::class, fn ($n) => $n->kind === 'business_quote');

        // The buyer sees both, cheapest first; a supplier sees only their own.
        $view = $this->actingAs($this->buyer)->getJson("/api/v1/requirements/{$uuid}")->assertOk()->json('data');
        $this->assertSame(2, $view['quotes_count']);
        $this->assertSame(['Moon Brass Works', 'Sunrise Handicrafts'], array_column(array_column($view['quotes'], 'page'), 'name'));
        $this->assertTrue($view['quotes'][0]['is_for_me']);
        $mine = $this->actingAs($this->seller)->getJson("/api/v1/requirements/{$uuid}")->assertOk()->json('data');
        $this->assertCount(1, $mine['quotes']);
        $this->assertTrue($mine['quotes'][0]['is_mine']);
        $this->assertFalse($mine['can_quote'] === false && $mine['quotes'] === []);

        // Accepting is the buyer's; the supplier hears; the other cannot decide.
        $this->actingAs($this->seller)->putJson("/api/v1/business/quotes/{$q2['uuid']}", ['status' => 'accepted'])->assertForbidden();
        $this->actingAs($this->buyer)->putJson("/api/v1/business/quotes/{$q2['uuid']}", ['status' => 'accepted'])->assertOk();
        $this->actingAs($this->buyer)->putJson("/api/v1/business/quotes/{$q2['uuid']}", ['status' => 'declined'])->assertStatus(422);
        Notification::assertSentTo($rival, SocialNotification::class, fn ($n) => str_contains($n->message, 'accepted'));
        $this->actingAs($this->seller)->putJson("/api/v1/business/quotes/{$q1['uuid']}", ['status' => 'withdrawn'])->assertOk();

        $received = $this->actingAs($this->buyer)->getJson('/api/v1/business/quotes?box=received')->assertOk()->json('data');
        $this->assertCount(2, $received);
        $this->assertSame('requirement', $received[0]['about']['kind']);
        $sent = $this->actingAs($rival)->getJson('/api/v1/business/quotes?box=sent')->assertOk()->json('data');
        $this->assertSame('accepted', $sent[0]['status']);
    }

    public function test_a_quote_answers_an_enquiry_and_reaches_a_visitor_by_email(): void
    {
        Mail::fake();
        Notification::fake();
        $product = \App\Models\Business\Product::first();

        // A member's enquiry: the quote sits on the enquiry, and they are told.
        $this->actingAs($this->buyer)->postJson('/api/v1/business/enquiries', ['product' => $product->uuid, 'message' => 'Best price for 1,000?'])->assertCreated();
        $enquiry = Enquiry::first();
        $this->actingAs($this->seller)->postJson("/api/v1/business/enquiries/{$enquiry->uuid}/quote", ['price' => 2.3, 'currency' => 'USD', 'price_unit' => 'pc', 'lead_time_days' => 20])->assertCreated();
        Notification::assertSentTo($this->buyer, SocialNotification::class, fn ($n) => $n->kind === 'business_quote');
        $sent = $this->actingAs($this->buyer)->getJson('/api/v1/business/enquiries/sent')->assertOk()->json('data.0');
        $this->assertSame('replied', $sent['status']);
        $this->assertSame('USD 2.30 / pc', $sent['quotes'][0]['price_label']);
        $this->assertTrue($sent['quotes'][0]['is_for_me']);

        // A visitor's enquiry: the quote goes by email, and becomes theirs when they join.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/trade/enquiries', ['product' => $product->uuid, 'name' => 'Hans', 'email' => 'hans@mueller.test', 'message' => 'CIF Hamburg price?', 'form_started_at' => (microtime(true) - 30) * 1000])->assertCreated();
        $guest = Enquiry::whereNotNull('guest_token')->first();
        $this->postJson("/api/v1/trade/enquiries/{$guest->uuid}/confirm", ['code' => $guest->guest_code])->assertOk();
        $hans = User::where('email', 'hans@mueller.test')->firstOrFail();
        $this->actingAs($this->seller)->postJson("/api/v1/business/enquiries/{$guest->uuid}/quote", ['price' => 2.5, 'currency' => 'USD', 'terms' => 'CIF', 'notes' => 'Includes insurance.'])->assertCreated();
        Mail::assertSent(EnquiryReply::class, fn (EnquiryReply $m) => $m->hasTo('hans@mueller.test') && str_contains($m->enquiry->owner_reply, 'USD 2.50') && str_contains($m->enquiry->owner_reply, 'Includes insurance.'));
        $this->assertSame($hans->id, Quote::where('enquiry_id', $guest->id)->value('buyer_user_id'), 'The account the code opened is the buyer.');
        $this->app['auth']->forgetGuards();
        $this->assertCount(1, $this->actingAs($hans)->getJson('/api/v1/business/quotes')->assertOk()->json('data'));
    }

    // --- Trust ------------------------------------------------------------------------------

    public function test_answering_enquiries_builds_the_response_figures_on_the_page(): void
    {
        $product = \App\Models\Business\Product::first();
        $this->travelTo(now()->subHours(5));
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $u = $this->person('buyer' . $n);
            $this->actingAs($u)->postJson('/api/v1/business/enquiries', ['product' => $product->uuid, 'message' => "Question {$n} please"])->assertCreated();
            $this->app['auth']->forgetGuards();
        }
        $this->travelBack();
        $this->travelTo(now()->subHours(2));
        $ids = Enquiry::orderBy('id')->pluck('uuid');
        // Three of four answered — one by chat, one by quote, one by chat again — 3 hours in.
        $this->actingAs($this->seller)->postJson("/api/v1/business/enquiries/{$ids[0]}/chat")->assertOk();
        $this->actingAs($this->seller)->postJson("/api/v1/business/enquiries/{$ids[1]}/quote", ['price' => 2, 'currency' => 'USD'])->assertCreated();
        $this->actingAs($this->seller)->postJson("/api/v1/business/enquiries/{$ids[2]}/chat")->assertOk();
        $this->travelBack();

        $trust = $this->getJson("/api/v1/trade/pages/{$this->page->slug}")->assertOk()->json('data.trust');
        $this->assertSame(75, $trust['response_rate']);
        $this->assertEqualsWithDelta(3.0, $trust['response_hours'], 0.2);
        $this->assertSame('within 3 h', $trust['response_label']);
        $this->assertFalse($trust['verified']);
        $this->assertSame(0, $trust['years']);
    }

    public function test_a_document_earns_the_badge_only_when_a_super_admin_says_so(): void
    {
        Storage::fake('local');
        Notification::fake();
        $admin = $this->person('boss');
        $admin->roles()->attach(\App\Models\Role::where('slug', 'super_admin')->first()->id);

        // One document is not enough to ask.
        $this->actingAs($this->seller)->post('/api/v1/business/page/documents', [
            'kind' => 'gst', 'number' => '09ABCDE1234F1Z5', 'document' => UploadedFile::fake()->create('gst.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->seller)->postJson('/api/v1/business/page/verification')->assertStatus(422);
        $this->assertSame('none', $this->page->fresh()->verification_status);

        $iec = $this->actingAs($this->seller)->post('/api/v1/business/page/documents', [
            'kind' => 'iec', 'number' => 'IEC0512345678', 'document' => UploadedFile::fake()->create('iec.pdf', 90, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $folder = $this->actingAs($this->seller)->getJson('/api/v1/business/page/documents')->assertOk()->json('data');
        $this->assertCount(2, $folder['documents']);
        $this->assertSame(2, $folder['verification']['documents_count']);

        $this->actingAs($this->seller)->postJson('/api/v1/business/page/verification')->assertOk();
        $page = $this->page->fresh();
        $this->assertSame('pending', $page->verification_status);
        $this->assertSame('gst', $page->verification_kind, 'The first document names the badge.');
        Storage::disk('local')->assertExists($page->verification_document_path);
        $this->actingAs($this->seller)->postJson('/api/v1/business/page/verification')->assertStatus(422);
        // The folder stands as judged while the team looks.
        $this->actingAs($this->seller)->deleteJson("/api/v1/business/page/documents/{$iec['uuid']}")->assertStatus(422);

        // The document is for the admin's eyes. Nobody else.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->seller)->getJson("/api/v1/admin/business/verifications/{$page->uuid}/document")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $me = $this->actingAs($this->seller)->getJson('/api/v1/business/me')->assertOk()->json('data.page.verification');
        $this->assertSame('pending', $me['status']);
        $this->assertFalse($this->getJson("/api/v1/trade/pages/{$page->slug}")->json('data.trust.verified'));

        $this->app['auth']->forgetGuards();
        $list = $this->actingAs($admin)->getJson('/api/v1/admin/business/verifications')->assertOk()->json('data');
        $this->assertSame('Sunrise Handicrafts', $list[0]['name']);
        $this->assertTrue($list[0]['has_document']);
        $this->assertCount(2, $list[0]['documents']);
        $this->actingAs($admin)->get("/api/v1/admin/business/verifications/{$page->uuid}/document")->assertOk();
        $this->actingAs($admin)->get("/api/v1/admin/business/verifications/{$page->uuid}/documents/{$list[0]['documents'][1]['uuid']}")->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/admin/business/verifications/{$page->uuid}", ['decision' => 'verified'])->assertOk();
        Notification::assertSentTo($this->seller, SocialNotification::class, fn ($n) => $n->kind === 'business_verification');

        $this->app['auth']->forgetGuards();
        $trust = $this->getJson("/api/v1/trade/pages/{$page->slug}")->assertOk()->json('data.trust');
        $this->assertTrue($trust['verified']);
        $this->assertSame('gst', $trust['verified_kind']);
        $this->assertCount(0, $this->actingAs($admin)->getJson('/api/v1/admin/business/verifications')->json('data'));

        // Rejection carries the note back to the owner.
        $this->actingAs($admin)->putJson("/api/v1/admin/business/verifications/{$page->uuid}", ['decision' => 'rejected', 'note' => 'Number does not match the certificate.'])->assertOk();
        $this->assertSame('Number does not match the certificate.', $this->actingAs($this->seller)->getJson('/api/v1/business/me')->json('data.page.verification.note'));
    }

    // --- Words --------------------------------------------------------------------------------

    public function test_translation_is_off_until_a_provider_is_configured_and_then_cached(): void
    {
        $this->assertFalse($this->getJson('/api/v1/trade/config')->assertOk()->json('data.translate'));
        $this->postJson('/api/v1/trade/translate', ['texts' => ['Brass lamp'], 'target' => 'de'])->assertStatus(503);

        AppSetting::set('translate_provider', 'google');
        AppSetting::set('translate_key', 'test-key');
        Http::fake(['translation.googleapis.com/*' => Http::response(['data' => ['translations' => [['translatedText' => 'Messinglampe'], ['translatedText' => 'Preis auf Anfrage']]]])]);

        $this->assertTrue($this->getJson('/api/v1/trade/config')->json('data.translate'));
        $r = $this->postJson('/api/v1/trade/translate', ['texts' => ['Brass lamp', 'Ask for price'], 'target' => 'de'])->assertOk()->json('data');
        $this->assertSame(['Messinglampe', 'Preis auf Anfrage'], $r);
        $this->assertSame(2, Translation::count());

        // The same sentence again is answered from the cache, not the provider.
        Http::fake(['translation.googleapis.com/*' => Http::response([], 500)]);
        $this->assertSame(['Messinglampe'], $this->postJson('/api/v1/trade/translate', ['texts' => ['Brass lamp'], 'target' => 'de'])->assertOk()->json('data'));
        Http::assertNothingSent();

        // The admin's key never comes back to the browser.
        $admin = $this->person('boss');
        $admin->roles()->attach(\App\Models\Role::where('slug', 'super_admin')->first()->id);
        $settings = $this->actingAs($admin)->getJson('/api/v1/admin/settings')->assertOk()->json('data');
        $this->assertSame('', $settings['translate_key']);
        $this->assertTrue($settings['translate_key_saved']);
        $this->assertSame('google', $settings['translate_provider']);
    }

    public function test_the_claude_provider_reuses_the_assistants_key_and_reads_numbered_lines(): void
    {
        AppSetting::set('translate_provider', 'claude');
        AppSetting::set('voice_ai_key', 'sk-test');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "1. Messinglampe\n2. Preis auf Anfrage"]]])]);

        $r = $this->postJson('/api/v1/trade/translate', ['texts' => ['Brass lamp', 'Ask for price'], 'target' => 'de'])->assertOk()->json('data');
        $this->assertSame(['Messinglampe', 'Preis auf Anfrage'], $r);
        Http::assertSent(fn ($req) => $req->hasHeader('x-api-key', 'sk-test') && str_contains($req['system'], 'Deutsch'));
    }
}
