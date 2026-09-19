<?php

namespace Tests\Feature;

use App\Models\Business\Page;
use App\Models\Business\Product;
use App\Models\Event;
use App\Models\Plan;
use App\Models\SavedSearch;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The catalogue, the share, the fairs, the standing searches, the sheet
 * import, and the plan that decides how many products a page lists.
 */
class TradeToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seller = $this->person('seller');
        $slug = $this->actingAs($this->seller)->postJson('/api/v1/business/page', ['name' => 'Sunrise Handicrafts', 'country' => 'IN', 'keywords' => ['brass'], 'tagline' => 'Brass décor', 'website' => 'sunrise.test', 'email' => 'sales@sunrise.test'])->assertCreated()->json('data.slug');
        $this->page = Page::where('slug', $slug)->firstOrFail();
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

    private function product(string $name, array $extra = [])
    {
        return $this->actingAs($this->seller)->postJson('/api/v1/business/products', $extra + [
            'name' => $name, 'price_type' => 'fixed', 'price_min' => 5, 'currency' => 'USD', 'keywords' => [mb_strtolower($name)], 'category' => 'Handicrafts & Home Décor',
        ]);
    }

    // --- 8: the catalogue and the share ---------------------------------------------------------

    public function test_the_catalogue_pdf_carries_the_page_and_its_products_and_respects_contacts(): void
    {
        Storage::fake('public');
        $p = $this->product('Brass Diya Lamp', ['moq' => 500, 'moq_unit' => 'pcs', 'terms' => ['FOB']])->assertCreated()->json('data');
        $this->actingAs($this->seller)->post("/api/v1/business/products/{$p['uuid']}/images", ['images' => [UploadedFile::fake()->image('lamp.jpg', 800, 600)]], ['Accept' => 'application/json'])->assertCreated();
        $this->product('Hidden thing', ['status' => 'hidden'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $html = \App\Support\Catalogue::html($this->page->fresh(), 'http://localhost:5173/trade/' . $this->page->slug);
        $this->assertStringContainsString('Sunrise Handicrafts', $html);
        $this->assertStringContainsString('Brass Diya Lamp', $html);
        $this->assertStringContainsString('USD 5.00', $html);
        $this->assertStringContainsString('MOQ 500.00 pcs', $html);
        $this->assertStringContainsString('data:image/', $html, 'The picture is embedded, so the file stands alone.');
        $this->assertStringNotContainsString('Hidden thing', $html);
        $this->assertStringContainsString('sunrise.test', $html);
        $this->assertStringNotContainsString('sales@sunrise.test', $html, 'Contacts stay private until the owner says otherwise.');

        $this->page->update(['show_contacts' => true, 'contacts_allowed' => true]);
        $this->assertStringContainsString('sales@sunrise.test', \App\Support\Catalogue::html($this->page->fresh(), 'x'));

        $r = $this->get("/api/v1/trade/pages/{$this->page->slug}/catalogue.pdf")->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->get('/api/v1/trade/pages/nobody/catalogue.pdf')->assertNotFound();
    }

    // --- 9: fairs ----------------------------------------------------------------------------------

    public function test_a_fair_is_listed_publicly_and_attending_puts_it_in_the_calendar(): void
    {
        $r = $this->actingAs($this->seller)->postJson('/api/v1/trade/events', [
            'title' => 'IHGF Delhi Fair', 'kind' => 'fair', 'starts_on' => now()->addDays(30)->toDateString(), 'ends_on' => now()->addDays(33)->toDateString(),
            'venue' => 'India Expo Centre', 'city' => 'Greater Noida', 'country' => 'in', 'website' => 'ihgfdelhifair.in', 'keywords' => ['handicraft', 'gifts'],
        ])->assertCreated()->json('data');
        $this->assertSame('Sunrise Handicrafts', $r['page']['name']);
        $this->assertSame('IN', $r['country']);
        $this->actingAs($this->seller)->postJson('/api/v1/trade/events', ['title' => 'Old fair', 'starts_on' => now()->subDays(10)->toDateString()])->assertCreated();

        // The public sees only what is still to come.
        $this->app['auth']->forgetGuards();
        $list = $this->getJson('/api/v1/trade/events')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('IHGF Delhi Fair', $list[0]['title']);
        $this->assertNull($list[0]['poster']);
        $this->assertCount(1, $this->getJson('/api/v1/trade/events?q=handicraft')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/trade/events?country=DE')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/trade/events?month=' . now()->addDays(30)->format('Y-m'))->json('data'));

        // Going writes it into the calendar; withdrawing takes it out.
        $buyer = $this->person('buyer');
        $this->actingAs($buyer)->postJson("/api/v1/trade/events/{$r['uuid']}/attend")->assertOk()->assertJsonPath('data.attending', true);
        $calendar = Event::where('user_id', $buyer->id)->first();
        $this->assertSame('IHGF Delhi Fair', $calendar->title);
        $this->assertTrue($calendar->all_day);
        $this->assertStringContainsString('Greater Noida', $calendar->location);
        $detail = $this->actingAs($buyer)->getJson("/api/v1/trade/events/{$r['uuid']}")->assertOk()->json('data');
        $this->assertSame(1, $detail['attendees_count']);
        $this->assertTrue($detail['attending']);

        $this->actingAs($buyer)->postJson("/api/v1/trade/events/{$r['uuid']}/attend")->assertOk()->assertJsonPath('data.attending', false);
        $this->assertNull(Event::where('user_id', $buyer->id)->first());

        // Only the poster edits it.
        $this->actingAs($buyer)->putJson("/api/v1/trade/events/{$r['uuid']}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($this->seller)->putJson("/api/v1/trade/events/{$r['uuid']}", ['status' => 'hidden'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertCount(0, $this->getJson('/api/v1/trade/events')->json('data'));
    }

    // --- 9: saved searches ---------------------------------------------------------------------------

    public function test_a_saved_search_speaks_only_when_something_new_matches(): void
    {
        Notification::fake();
        $buyer = $this->person('buyer');
        $s = $this->actingAs($buyer)->postJson('/api/v1/trade/alerts', ['kind' => 'products', 'q' => 'brass lamp', 'country' => 'in'])->assertCreated()->json('data');
        $this->assertSame('“brass lamp” · IN', $s['label']);
        $this->assertStringStartsWith('/search?q=', $s['path']);
        $this->actingAs($buyer)->postJson('/api/v1/trade/alerts', ['kind' => 'products', 'q' => 'brass lamp', 'country' => 'in'])->assertOk();
        $this->actingAs($buyer)->postJson('/api/v1/trade/alerts', ['kind' => 'products'])->assertStatus(422);
        $this->assertCount(1, $this->actingAs($buyer)->getJson('/api/v1/trade/alerts')->json('data'));

        // Nothing new yet: silence.
        $this->artisan('mypa:trade-alerts')->assertSuccessful();
        Notification::assertNothingSent();

        // A matching product arrives.
        $this->travelTo(now()->addMinute());
        $this->product('Brass Table Lamp')->assertCreated();
        $this->product('Cotton Rug')->assertCreated();
        $this->artisan('mypa:trade-alerts')->assertSuccessful();
        Notification::assertSentTo($buyer, SocialNotification::class, fn ($n) => $n->kind === 'business_alert' && str_contains($n->message, '1 new product'));
        $this->assertSame(1, SavedSearch::first()->last_hits);

        // The same product is not reported twice; paused searches say nothing.
        Notification::fake();
        $this->artisan('mypa:trade-alerts')->assertSuccessful();
        Notification::assertNothingSent();
        $this->actingAs($buyer)->putJson("/api/v1/trade/alerts/{$s['uuid']}", ['active' => false])->assertOk();
        $this->travelTo(now()->addMinute());
        $this->product('Brass Wall Lamp')->assertCreated();
        $this->artisan('mypa:trade-alerts')->assertSuccessful();
        Notification::assertNothingSent();

        // Requirements watch the other direction.
        $rs = $this->actingAs($buyer)->postJson('/api/v1/trade/alerts', ['kind' => 'requirements', 'q' => 'diya'])->assertCreated()->json('data');
        $this->travelTo(now()->addMinute());
        $this->actingAs($this->person('other'))->postJson('/api/v1/requirements', ['title' => 'Brass diyas wanted', 'description' => 'Five thousand pieces, gift boxed.', 'keywords' => ['diya']])->assertCreated();
        $this->artisan('mypa:trade-alerts')->assertSuccessful();
        Notification::assertSentTo($buyer, SocialNotification::class, fn ($n) => str_contains($n->message, 'requirement'));
        $this->actingAs($buyer)->deleteJson("/api/v1/trade/alerts/{$rs['uuid']}")->assertOk();
        $this->actingAs($this->person('stranger'))->deleteJson("/api/v1/trade/alerts/{$s['uuid']}")->assertNotFound();
    }

    // --- 9: the sheet import ----------------------------------------------------------------------------

    public function test_products_come_in_from_a_csv_with_bad_rows_named(): void
    {
        $csv = "name,kind,category,summary,description,price_type,price_min,price_max,currency,price_unit,moq,moq_unit,terms,keywords,status\n"
            . "Brass Diya Lamp,product,Handicrafts & Home Décor,Hand-finished,Solid brass,range,2,3,usd,pc,500,pcs,FOB|CIF,brass|diya,active\n"
            . "Export documentation,service,Services,DGFT paperwork,,on_request,,,INR,,,,,export|customs,active\n"
            . ",product,,,,fixed,1,,USD,,,,,,active\n"
            . "Bad terms,product,,,,fixed,1,,USD,,,,XYZ,x,active\n"
            . "\n";
        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $r = $this->actingAs($this->seller)->post('/api/v1/business/products/import', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertSame(2, $r['data']['created']);
        $this->assertCount(2, $r['data']['skipped']);
        $this->assertSame(4, $r['data']['skipped'][0]['row']);
        $this->assertSame('Bad terms', $r['data']['skipped'][1]['name']);

        $lamp = Product::where('name', 'Brass Diya Lamp')->first();
        $this->assertSame(['FOB', 'CIF'], $lamp->terms);
        $this->assertSame('USD', $lamp->currency);
        $this->assertSame('range', $lamp->price_type);
        $this->assertSame('service', Product::where('name', 'Export documentation')->value('kind'));

        $template = $this->actingAs($this->seller)->get('/api/v1/business/products/import/template')->assertOk();
        $this->assertStringContainsString('name,kind,category', $template->streamedContent());
    }

    // --- 9: the plan tier --------------------------------------------------------------------------------

    public function test_the_plan_caps_the_products_and_puts_paid_pages_first(): void
    {
        // Free lists ten. The eleventh is refused with the way up.
        Plan::where('slug', 'free')->update(['limits' => ['max_products' => 2]]);
        $this->product('One')->assertCreated();
        $this->product('Two')->assertCreated();
        $refused = $this->product('Three')->assertStatus(422);
        $this->assertStringContainsString('up to 2 products', $refused->json('message'));
        $this->assertStringContainsString('Gold plan lists 50', $refused->json('message'));
        $this->assertSame(2, $this->actingAs($this->seller)->getJson('/api/v1/subscription')->json('data.usage.products.used'));

        // The import counts against the same cap, all or nothing.
        $csv = "name,price_type,price_min,currency\nA,fixed,1,USD\nB,fixed,1,USD\n";
        $this->actingAs($this->seller)->post('/api/v1/business/products/import', ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)], ['Accept' => 'application/json'])->assertStatus(422);

        // A paid plan lifts it, and puts the page first in the public search.
        $personal = Plan::where('slug', 'personal')->first();
        Subscription::create(['user_id' => $this->seller->id, 'plan_id' => $personal->id, 'status' => 'active', 'started_at' => now(), 'ends_at' => now()->addMonth()]);
        $this->product('Three')->assertCreated();

        $rival = $this->person('rival');
        $this->actingAs($rival)->postJson('/api/v1/business/page', ['name' => 'Aaa Brass Works', 'country' => 'IN', 'keywords' => ['brass']])->assertCreated();
        $this->actingAs($rival)->postJson('/api/v1/business/products', ['name' => 'Brass thing', 'price_type' => 'on_request', 'currency' => 'USD', 'keywords' => ['brass']])->assertCreated();
        $this->app['auth']->forgetGuards();

        $pages = $this->getJson('/api/v1/trade/search?q=brass')->assertOk()->json('data.pages');
        $this->assertSame('Sunrise Handicrafts', $pages[0]['name'], 'The paid page comes first though it sorts later by name.');
        $this->assertTrue($pages[0]['featured']);
        $this->assertFalse($pages[1]['featured']);
    }
}
