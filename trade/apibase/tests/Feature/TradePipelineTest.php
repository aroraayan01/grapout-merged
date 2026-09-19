<?php

namespace Tests\Feature;

use App\Models\Business\Enquiry;
use App\Models\Business\Page;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * From conversation to deal.
 *
 * Ask about an opportunity, be accepted or declined, meet in a GrapOut
 * room, record what happened, and see every conversation by stage.
 */
class TradePipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $rajesh;
    private User $john;
    private Page $xyz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->rajesh = $this->person('rajesh');
        $this->john = $this->person('john');
        $slug = $this->as($this->rajesh)->postJson('/api/v1/business/page', ['name' => 'XYZ Hardware', 'country' => 'IN', 'keywords' => []])->assertCreated()->json('data.slug');
        $this->xyz = Page::where('slug', $slug)->firstOrFail();
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

    public function test_an_enquiry_about_an_opportunity_is_accepted_met_and_recorded(): void
    {
        Notification::fake();
        $offer = $this->as($this->rajesh)->postJson('/api/v1/requirements', ['kind' => 'sell', 'title' => 'Brass hinges on offer', 'description' => 'Forged, polished, 4 inch.', 'keywords' => ['hinge'], 'hs_code' => '830210'])->assertCreated()->json('data');

        // John asks about the offer. It lands with XYZ, at stage "new".
        $e = $this->as($this->john)->postJson('/api/v1/business/enquiries', ['requirement' => $offer['uuid'], 'message' => 'Price for 5,000 hinges CIF Hamburg?'])->assertCreated()->json('data');
        $this->assertSame('new', $e['stage']);
        $this->assertSame('Brass hinges on offer', $e['requirement']['title']);
        Notification::assertSentTo($this->rajesh, SocialNotification::class, fn ($n) => str_contains($n->message, 'Brass hinges on offer'));

        // Accept: John hears, the stage moves, the response counts.
        $this->as($this->rajesh)->putJson("/api/v1/business/enquiries/{$e['uuid']}", ['stage' => 'accepted'])->assertOk()->assertJsonPath('data.stage', 'accepted')->assertJsonPath('data.handled_by', 'Rajesh');
        Notification::assertSentTo($this->john, SocialNotification::class, fn ($n) => str_contains($n->message, 'accepted your enquiry'));
        $this->assertSame('replied', Enquiry::first()->status);

        // A meeting, proposed by the company, in a GrapOut room; John gets the link.
        $when = now()->addDays(2)->setTime(11, 0)->utc()->format('Y-m-d\TH:i:s.v\Z'); // the browser's ISO instant
        $row = $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$e['uuid']}/meeting", ['scheduled_at' => $when])->assertCreated()->json('data');
        $this->assertSame('meeting', $row['stage']);
        $this->assertNotNull($row['meeting']['code']);
        $this->assertStringStartsWith('/meetings/room/', $row['meeting']['join_path']);
        $meeting = Meeting::first();
        $this->assertSame($this->rajesh->id, $meeting->host_id);
        $this->assertStringContainsString('XYZ Hardware × John', $meeting->title);
        Notification::assertSentTo($this->john, SocialNotification::class, fn ($n) => $n->kind === 'business_meeting' && $n->actionPath === '/meetings/room/' . $meeting->code);

        // A quote does not pull it back: still "meeting" until the outcome says "quoted".
        $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$e['uuid']}/quote", ['price' => 1.8, 'currency' => 'USD', 'price_unit' => 'pc'])->assertCreated();
        $this->assertSame('quoted', Enquiry::first()->stage, 'A quote is further along than a meeting.');

        // The outcome: only the company records it; it moves the stage on.
        $this->as($this->john)->postJson("/api/v1/business/enquiries/{$e['uuid']}/outcome", ['outcome' => 'negotiating'])->assertForbidden();
        $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$e['uuid']}/outcome", ['outcome' => 'sample_requested', 'note' => 'Sending 10 pcs by DHL.'])->assertOk()->assertJsonPath('data.stage', 'sampling');
        $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$e['uuid']}/outcome", ['outcome' => 'negotiating'])->assertOk()->assertJsonPath('data.stage', 'negotiating');
        $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$e['uuid']}/outcome", ['outcome' => 'won', 'note' => 'PO for 5,000 pcs.'])->assertOk()->assertJsonPath('data.stage', 'won');
        $this->assertSame('closed', Enquiry::first()->status);
        $this->assertSame('PO for 5,000 pcs.', Enquiry::first()->outcome_note);

        // The pipeline, from both chairs.
        $mine = $this->as($this->rajesh)->getJson('/api/v1/business/pipeline')->assertOk()->json('data');
        $this->assertCount(1, $mine['received']['won']);
        $this->assertSame(1, $mine['counts']['received']['won']);
        $his = $this->as($this->john)->getJson('/api/v1/business/pipeline')->assertOk()->json('data');
        $this->assertCount(1, $his['sent']['won']);
        $this->assertCount(0, $his['received']['won']);
    }

    public function test_declining_closes_it_and_a_chat_or_a_visitor_meeting_behave(): void
    {
        Notification::fake();
        $product = $this->as($this->rajesh)->postJson('/api/v1/business/products', ['name' => 'Door handle', 'price_type' => 'on_request', 'currency' => 'USD', 'keywords' => ['handle']])->json('data');
        $e = $this->as($this->john)->postJson('/api/v1/business/enquiries', ['product' => $product['uuid'], 'message' => 'Do you do bulk?'])->assertCreated()->json('data');

        // Opening the chat is an acceptance.
        $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$e['uuid']}/chat")->assertOk();
        $this->assertSame('accepted', Enquiry::first()->stage);

        // Declining ends it; a later "won" cannot reopen it through advance.
        $this->as($this->rajesh)->putJson("/api/v1/business/enquiries/{$e['uuid']}", ['stage' => 'declined'])->assertOk();
        $this->assertSame('closed', Enquiry::first()->status);
        Notification::assertSentTo($this->john, SocialNotification::class, fn ($n) => str_contains($n->message, 'declined'));
        $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$e['uuid']}/quote", ['price' => 1, 'currency' => 'USD'])->assertCreated();
        $this->assertSame('declined', Enquiry::first()->stage, 'A declined enquiry stays declined.');

        // A meeting cannot be proposed to a visitor without an account.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/trade/enquiries', ['product' => $product['uuid'], 'name' => 'Hans', 'email' => 'hans@x.test', 'message' => 'Price please?', 'form_started_at' => (microtime(true) - 30) * 1000])->assertCreated();
        $guest = Enquiry::whereNotNull('guest_token')->first();
        $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$guest->uuid}/meeting", ['scheduled_at' => now()->addDay()->toDateTimeString()])->assertStatus(422);
        $this->as($this->rajesh)->postJson("/api/v1/business/enquiries/{$e['uuid']}/meeting", ['scheduled_at' => now()->subDay()->toDateTimeString()])->assertStatus(422);
    }
}
