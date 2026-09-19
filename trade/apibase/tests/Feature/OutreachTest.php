<?php

namespace Tests\Feature;

use App\Mail\OutreachMail;
use App\Models\Business\Page;
use App\Models\Outreach\Contact;
use App\Models\Outreach\Send;
use App\Models\User;
use App\Services\AppIdService;
use App\Support\ContactSheet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A company's own email list: read from any sheet, written to only when
 * the team allows, from a mailbox the team keeps, and never to somebody
 * who said no.
 */
class OutreachTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->owner = $this->person('owner');
        $this->admin = $this->person('boss');
        $this->admin->roles()->attach(\App\Models\Role::where('slug', 'super_admin')->first()->id);
        $slug = $this->as($this->owner)->postJson('/api/v1/business/page', ['name' => 'Sunrise Handicrafts', 'country' => 'IN', 'keywords' => ['brass']])->assertCreated()->json('data.slug');
        $this->page = Page::where('slug', $slug)->firstOrFail();
        $this->as($this->owner)->postJson('/api/v1/business/products', ['name' => 'Brass Diya Lamp', 'price_type' => 'fixed', 'price_min' => 2, 'currency' => 'USD', 'keywords' => ['brass'], 'category' => 'Handicrafts & Home Décor'])->assertCreated();
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

    public function test_a_sheet_is_read_whatever_its_columns_are_called_or_whether_it_has_any(): void
    {
        $withHeader = "Firm;Contact Person;E-mail;WhatsApp;Tel;Country\nMüller Import GmbH;Hans Müller;Hans@Mueller.test;+49 170 1234567;+49 40 555;Germany\nAl Noor Trading;;info@alnoor.test;;;AE\nBad Row;Nobody;not-an-email;;;\n";
        $r = ContactSheet::parse($withHeader);
        $this->assertTrue($r['header']);
        $this->assertCount(2, $r['rows']);
        $this->assertSame(['company_name' => 0, 'contact_name' => 1, 'email' => 2, 'mobile' => 3, 'phone' => 4, 'country' => 5, 'notes' => null], $r['mapping']);
        $this->assertSame('hans@mueller.test', $r['rows'][0]['email']);
        $this->assertSame('Müller Import GmbH', $r['rows'][0]['company_name']);
        $this->assertSame('DE', $r['rows'][0]['country']);
        $this->assertNull($r['rows'][1]['contact_name'], 'info@ names nobody.');
        $this->assertSame('not an email address: not-an-email', $r['skipped'][0]['reason']);

        $noHeader = "Rahul Sharma, Sharma Exports Pvt Ltd, rahul.sharma@sharma.test, 9876543210\nglobal.buyers@x.test\n";
        $r = ContactSheet::parse($noHeader);
        $this->assertFalse($r['header']);
        $this->assertSame('Sharma Exports Pvt Ltd', $r['rows'][0]['company_name'], 'The company word decides which name is the company.');
        $this->assertSame('Rahul Sharma', $r['rows'][0]['contact_name']);
        $this->assertSame('9876543210', $r['rows'][0]['mobile']);
        $this->assertSame('Global Buyers', $r['rows'][1]['contact_name'], 'A name is read off the address when the sheet gives none.');
    }

    public function test_the_list_is_the_companys_and_sending_is_the_teams_to_allow(): void
    {
        Mail::fake();
        Queue::fake();

        // The list: import, add, see.
        $r = $this->as($this->owner)->post('/api/v1/business/outreach/contacts/import', ['text' => "Company,Name,Email\nMüller Import,Hans,hans@mueller.test\nAl Noor,Ali,ali@alnoor.test\n"], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(2, $r['created']);
        $this->as($this->owner)->postJson('/api/v1/business/outreach/contacts', ['email' => 'priya.patel@buyer.test', 'company_name' => 'Patel Imports'])->assertCreated()->assertJsonPath('data.contact_name', 'Priya Patel');
        $this->assertCount(3, $this->as($this->owner)->getJson('/api/v1/business/outreach/contacts')->assertOk()->json('data'));
        $this->as($this->person('stranger'))->getJson('/api/v1/business/outreach/contacts')->assertForbidden();

        // Off by default: nothing goes out.
        $status = $this->as($this->owner)->getJson('/api/v1/business/outreach')->assertOk()->json('data');
        $this->assertFalse($status['enabled']);
        $hans = Contact::where('email', 'hans@mueller.test')->firstOrFail();
        $this->as($this->owner)->postJson('/api/v1/business/outreach/send', ['template' => 'invitation', 'contacts' => [$hans->uuid]])->assertStatus(403);

        // The team keeps a mailbox and switches the page on: one at a time, five a day.
        $box = $this->as($this->admin)->postJson('/api/v1/admin/business/outreach/mailboxes', ['label' => 'Outreach', 'from_name' => 'GrapOut Trade', 'from_address' => 'trade@grapout.test', 'mailer' => 'platform', 'is_default' => true])->assertCreated()->json('data');
        $this->as($this->admin)->putJson("/api/v1/admin/business/outreach/pages/{$this->page->uuid}", ['outreach_enabled' => true, 'outreach_daily_limit' => 5, 'outreach_bulk_allowed' => false, 'outreach_mailbox' => $box['uuid']])->assertOk();
        $this->as($this->owner)->postJson('/api/v1/business/outreach/send', ['template' => 'invitation', 'all' => true])->assertStatus(403);
        $this->as($this->owner)->postJson('/api/v1/business/outreach/send', ['template' => 'invitation', 'contacts' => [$hans->uuid], 'note' => 'We met at the Delhi fair.'])->assertOk()->assertJsonPath('data.queued', 1);
        Queue::assertPushed(\App\Jobs\SendOutreachMail::class, 1);

        // The queue does the sending: the email carries the page, the note, the mark and a way out.
        $send = Send::first();
        (new \App\Jobs\SendOutreachMail($send->id))->handle(app(\App\Services\OutreachMailer::class));
        Mail::assertSent(OutreachMail::class, function (OutreachMail $m) {
            return $m->hasTo('hans@mueller.test') && $m->hasFrom('trade@grapout.test')
                && str_contains($m->subjectLine, 'invites you') && str_contains($m->body, 'We met at the Delhi fair.')
                && str_contains($m->body, '/c/' . $this->page->slug) && str_contains($m->body, 'GRAPOUT TRADE') && str_contains($m->body, 'unsubscribe');
        });
        $this->assertSame('sent', $send->fresh()->status);
        $this->assertSame(1, $hans->fresh()->sent_count);

        // Bulk, once allowed; the day's allowance holds.
        $this->as($this->admin)->putJson("/api/v1/admin/business/outreach/pages/{$this->page->uuid}", ['outreach_bulk_allowed' => true])->assertOk();
        $this->as($this->owner)->postJson('/api/v1/business/outreach/send', ['template' => 'catalogue', 'all' => true, 'with_prices' => false])->assertOk()->assertJsonPath('data.queued', 3);
        $this->assertSame(4, Send::count());
        $this->as($this->admin)->putJson("/api/v1/admin/business/outreach/pages/{$this->page->uuid}", ['outreach_daily_limit' => 4])->assertOk();
        $this->as($this->owner)->postJson('/api/v1/business/outreach/send', ['template' => 'invitation', 'contacts' => [$hans->uuid]])->assertStatus(422);

        // A catalogue without prices says so; an unsubscribe is final.
        $cat = Send::where('template', 'catalogue')->where('to_email', 'ali@alnoor.test')->firstOrFail();
        [$subject, $html] = app(\App\Services\OutreachMailer::class)->render($cat);
        $this->assertStringContainsString('catalogue', $subject);
        $this->assertStringContainsString('Price on enquiry', $html);
        $this->assertStringContainsString('prices%3Dhide', $html, 'The catalogue link, tracked, still hides the prices.');
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/trade/outreach/unsubscribe/{$cat->uuid}")->assertOk();
        $this->assertSame('unsubscribed', Contact::where('email', 'ali@alnoor.test')->value('email_status'));
        (new \App\Jobs\SendOutreachMail($cat->id))->handle(app(\App\Services\OutreachMailer::class));
        $this->assertSame('failed', $cat->fresh()->status, 'Queued before, unsubscribed since: it does not go.');
        $this->as($this->owner)->putJson("/api/v1/business/outreach/contacts/" . Contact::where('email', 'ali@alnoor.test')->value('uuid'), ['email_status' => 'valid'])->assertOk()->assertJsonPath('data.email_status', 'unsubscribed');

        // The team sees everything; the page sees its own.
        $this->assertCount(4, $this->as($this->admin)->getJson('/api/v1/admin/business/outreach/sends')->assertOk()->json('data'));
        $this->assertCount(4, $this->as($this->owner)->getJson('/api/v1/business/outreach/sends')->assertOk()->json('data'));
        $this->assertSame(3, $this->as($this->admin)->getJson('/api/v1/admin/business/outreach/pages')->assertOk()->json('data.0.contacts'));
    }

    public function test_templates_campaigns_and_the_figures_that_say_what_was_read(): void
    {
        // Mid-morning, so that the hours travelled below stay within the day and its allowance.
        $this->travelTo(now()->startOfDay()->addHours(10));
        Mail::fake();
        Queue::fake();
        $this->as($this->owner)->post('/api/v1/business/outreach/contacts/import', ['text' => "Company,Name,Email,Country\nMüller Import,Hans,hans@mueller.test,DE\nAl Noor,Ali,ali@alnoor.test,AE\nPatel Imports,Priya,priya@patel.test,DE\n"], ['Accept' => 'application/json'])->assertOk();
        $box = $this->as($this->admin)->postJson('/api/v1/admin/business/outreach/mailboxes', ['label' => 'Outreach', 'from_name' => 'GrapOut Trade', 'from_address' => 'trade@grapout.test', 'mailer' => 'platform', 'is_default' => true, 'daily_limit' => 100])->assertCreated()->json('data');
        $this->as($this->admin)->putJson("/api/v1/admin/business/outreach/pages/{$this->page->uuid}", ['outreach_enabled' => true, 'outreach_daily_limit' => 2, 'outreach_bulk_allowed' => true, 'outreach_mailbox' => $box['uuid']])->assertOk();

        // The team writes a template for everyone; the page writes its own, in placeholders.
        $shared = $this->as($this->admin)->postJson('/api/v1/admin/business/outreach/templates', ['name' => 'Fair follow-up', 'kind' => 'custom', 'subject' => 'Good to meet you, {{contact_name}}', 'body' => "Hello {{contact_name}} of {{company_name}},\n\n{{note}}\n\nWe sell {{sells}}. See {{page_link}}."])->assertCreated()->json('data');
        $this->assertFalse($shared['own']);
        $own = $this->as($this->owner)->postJson('/api/v1/business/outreach/templates', ['name' => 'Diwali offer', 'kind' => 'catalogue', 'subject' => '{{page_name}}: Diwali prices', 'body' => 'Dear {{contact_name}}, our <b>festival</b> prices are in the catalogue.', 'with_prices' => true])->assertCreated()->json('data');
        $list = $this->as($this->owner)->getJson('/api/v1/business/outreach/templates')->assertOk()->json('data');
        $this->assertSame(['Fair follow-up', 'Diwali offer'], array_column($list['templates'], 'name'), "The platform's first, then the page's own.");
        $this->assertArrayHasKey('contact_name', $list['placeholders']);
        $preview = $this->as($this->owner)->getJson("/api/v1/business/outreach/templates/{$own['uuid']}/preview")->assertOk()->json('data');
        $this->assertSame('Sunrise Handicrafts: Diwali prices', $preview['subject']);
        $this->assertMatchesRegularExpression('/Dear (Hans|Ali|Priya),/', $preview['html'], 'Shown to one contact of the list.');
        $this->assertStringContainsString('&lt;b&gt;festival&lt;/b&gt;', $preview['html'], 'What is typed is words, never markup.');
        $this->assertStringContainsString('catalogue.pdf', $preview['html']);
        $this->assertStringNotContainsString('prices=hide', $preview['html']);
        $this->as($this->person('stranger'))->postJson('/api/v1/business/outreach/templates', ['name' => 'x', 'subject' => 'x', 'body' => 'x'])->assertForbidden();
        $this->as($this->owner)->putJson("/api/v1/business/outreach/templates/{$shared['uuid']}", ['name' => 'Mine now'])->assertNotFound();

        // A campaign: the shared template to Germany, a note filled in, launched now within two a day.
        $c = $this->as($this->owner)->postJson('/api/v1/business/outreach/campaigns', ['name' => 'Germany after the fair', 'template' => $shared['uuid'], 'filter_country' => 'de', 'note' => 'Stand 41, Hall B.'])->assertCreated()->json('data');
        $this->assertSame('draft', $c['status']);
        $this->assertSame(2, $c['total'], 'Two of the three are in Germany.');
        $this->as($this->owner)->postJson("/api/v1/business/outreach/campaigns/{$c['uuid']}/launch")->assertOk()->assertJsonPath('data.status', 'sending');
        Queue::assertPushed(\App\Jobs\SendOutreachMail::class, 2);
        $this->assertSame(2, Send::where('campaign_id', \App\Models\Outreach\Campaign::where('uuid', $c['uuid'])->value('id'))->count());

        // The queue sends: subject and body carry the words, the note, the tracked links and the pixel.
        $send = Send::where('to_email', 'hans@mueller.test')->firstOrFail();
        (new \App\Jobs\SendOutreachMail($send->id))->handle(app(\App\Services\OutreachMailer::class));
        Mail::assertSent(OutreachMail::class, function (OutreachMail $m) use ($send) {
            return $m->hasTo('hans@mueller.test') && $m->subjectLine === 'Good to meet you, Hans'
                && str_contains($m->body, 'Hello Hans of Müller Import') && str_contains($m->body, 'Stand 41, Hall B.') && str_contains($m->body, 'Brass Diya')
                && str_contains($m->body, "/trade/outreach/c/{$send->uuid}?u=") && str_contains($m->body, "/trade/outreach/o/{$send->uuid}.gif");
        });
        $this->assertSame('sent', $send->fresh()->status);
        $this->assertSame('Good to meet you, Hans', $send->fresh()->subject);

        // Opened, then clicked: counted once each on the campaign, the click only follows our own links.
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/trade/outreach/o/{$send->uuid}.gif")->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get("/api/v1/trade/outreach/o/{$send->uuid}.gif")->assertOk();
        $this->assertSame(2, $send->fresh()->open_count);
        $front = rtrim(config('mypa.frontend_url'), '/');
        $this->get("/api/v1/trade/outreach/c/{$send->uuid}?u=" . rawurlencode("{$front}/c/{$this->page->slug}"))->assertRedirect("{$front}/c/{$this->page->slug}");
        $this->get("/api/v1/trade/outreach/c/{$send->uuid}?u=" . rawurlencode('https://evil.example/phish'))->assertRedirect($front);
        $campaign = \App\Models\Outreach\Campaign::where('uuid', $c['uuid'])->firstOrFail();
        $this->assertSame([1, 1, 1], [$campaign->sent, $campaign->opened, $campaign->clicked]);
        $this->assertNotNull($send->fresh()->clicked_at);

        // Once the other goes, the campaign is done; the figures add up for the page and for the team.
        $other = Send::where('to_email', 'priya@patel.test')->firstOrFail();
        (new \App\Jobs\SendOutreachMail($other->id))->handle(app(\App\Services\OutreachMailer::class));
        $this->assertSame('done', $campaign->fresh()->status);
        $stats = $this->as($this->owner)->getJson('/api/v1/business/outreach/stats')->assertOk()->json('data');
        $this->assertEquals([3, 2, 1, 1, 50, 1], [$stats['contacts'], $stats['sent'], $stats['opened'], $stats['clicked'], $stats['open_rate'], $stats['campaigns']]);
        $this->assertCount(30, $stats['days']);
        $team = $this->as($this->admin)->getJson('/api/v1/admin/business/outreach/stats')->assertOk()->json('data');
        $this->assertSame(2, $team['sent']);
        $this->assertSame('Sunrise Handicrafts', $team['top_pages'][0]['name']);
        $this->assertCount(1, $this->as($this->admin)->getJson('/api/v1/admin/business/outreach/campaigns')->assertOk()->json('data'));
        $this->assertCount(3, $this->as($this->admin)->getJson('/api/v1/admin/business/outreach/contacts?q=test')->assertOk()->json('data'));
        $this->assertSame('Germany after the fair', $this->as($this->owner)->getJson('/api/v1/business/outreach/sends')->assertOk()->json('data.0.campaign'));

        // A scheduled campaign waits for the clock; the allowance carries the rest to the next run.
        $this->as($this->admin)->putJson("/api/v1/admin/business/outreach/pages/{$this->page->uuid}", ['outreach_daily_limit' => 3])->assertOk();
        $later = $this->as($this->owner)->postJson('/api/v1/business/outreach/campaigns', ['name' => 'Everyone, tonight', 'template_kind' => 'invitation', 'scheduled_at' => now()->addHours(3)->toIso8601String()])->assertCreated()->json('data');
        $this->as($this->owner)->postJson("/api/v1/business/outreach/campaigns/{$later['uuid']}/launch")->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->artisan('trade:outreach-campaigns')->assertSuccessful();
        $this->assertSame('scheduled', \App\Models\Outreach\Campaign::where('uuid', $later['uuid'])->value('status'));
        $this->travel(4)->hours();
        $this->artisan('trade:outreach-campaigns')->assertSuccessful();
        $l = \App\Models\Outreach\Campaign::where('uuid', $later['uuid'])->firstOrFail();
        $this->assertSame('sending', $l->status);
        $this->assertSame(3, $l->total);
        $this->assertSame(1, $l->sends()->count(), 'Two went today already; one fits in the allowance of three.');
        $this->travel(1)->days();
        foreach (Send::where('status', 'queued')->get() as $s) {
            (new \App\Jobs\SendOutreachMail($s->id))->handle(app(\App\Services\OutreachMailer::class));
        }
        $this->artisan('trade:outreach-campaigns')->assertSuccessful();
        $this->assertSame(3, $l->fresh()->sends()->count(), 'The next day, the rest follow.');

        // The team can pause what is on its way; a paused campaign starts again from where it stopped.
        $this->as($this->admin)->postJson("/api/v1/admin/business/outreach/campaigns/{$later['uuid']}/pause")->assertOk();
        $l = $l->fresh();
        $this->assertSame('paused', $l->status);
        $this->assertSame(0, $l->sends()->where('status', 'queued')->count());
        $this->as($this->owner)->deleteJson("/api/v1/business/outreach/templates/{$own['uuid']}")->assertOk();
        $this->as($this->admin)->deleteJson("/api/v1/admin/business/outreach/templates/{$shared['uuid']}")->assertOk();
        $this->assertSame('Germany after the fair', $campaign->fresh()->name, 'The campaign outlives the template it used.');
    }
}
