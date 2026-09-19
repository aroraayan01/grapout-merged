<?php

namespace Tests\Feature;

use App\Models\Business\Page;
use App\Models\Outreach\Campaign;
use App\Models\Outreach\Contact;
use App\Models\Outreach\Mailbox;
use App\Models\Outreach\Send;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use App\Services\InboxSync;
use App\Services\MailboxDoctor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Hot Leads as a mailing system: lists and cohorts to slice the
 * contacts, sequences that follow up until somebody answers, groups of
 * mailboxes that share a campaign, an inbox that files the replies, and
 * the DNS standing of a mailbox.
 */
class MailingSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();
        $this->owner = $this->person('owner');
        $this->admin = $this->person('boss');
        $this->admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $slug = $this->as($this->owner)->postJson('/api/v1/business/page', ['name' => 'Sunrise Handicrafts', 'country' => 'IN', 'keywords' => ['brass']])->assertCreated()->json('data.slug');
        $this->page = Page::where('slug', $slug)->firstOrFail();
        $this->page->update(['outreach_enabled' => true, 'outreach_bulk_allowed' => true, 'outreach_daily_limit' => 50]);
        $this->as($this->admin)->postJson('/api/v1/admin/business/outreach/mailboxes', ['label' => 'Main', 'from_name' => 'GrapOut', 'from_address' => 'hello@grapout.test', 'mailer' => 'platform', 'is_default' => true, 'daily_limit' => 100])->assertCreated();
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

    private function contact(string $email, array $extra = []): Contact
    {
        $uuid = $this->as($this->owner)->postJson('/api/v1/business/outreach/contacts', ['email' => $email] + $extra)->assertCreated()->json('data.uuid');

        return Contact::where('uuid', $uuid)->firstOrFail();
    }

    public function test_lists_and_cohorts_slice_the_contacts_and_a_sheet_lands_on_a_list(): void
    {
        $list = $this->as($this->owner)->postJson('/api/v1/business/outreach/lists', ['name' => 'Germany buyers'])->assertCreated()->json('data');
        $r = $this->as($this->owner)->post('/api/v1/business/outreach/contacts/import', ['text' => "Müller GmbH, Hans, hans@mueller.test, DE\nSchmidt AG, Eva, eva@schmidt.test, DE\n", 'list' => $list['uuid']], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(2, $r['created']);
        $this->assertSame(2, $this->as($this->owner)->getJson('/api/v1/business/outreach/lists')->assertOk()->json('data.0.contacts'));
        $loose = $this->contact('ali@alnoor.test', ['company_name' => 'Al Noor', 'country' => 'AE']);
        $this->assertCount(2, $this->as($this->owner)->getJson('/api/v1/business/outreach/contacts?list=' . $list['uuid'])->assertOk()->json('data'), 'The list filter narrows the contacts.');

        // On and off a list by hand.
        $this->as($this->owner)->postJson("/api/v1/business/outreach/lists/{$list['uuid']}/contacts", ['contacts' => [$loose->uuid]])->assertOk()->assertJsonPath('data.contacts', 3);
        $this->as($this->owner)->postJson("/api/v1/business/outreach/lists/{$list['uuid']}/contacts", ['contacts' => [$loose->uuid], 'remove' => true])->assertOk()->assertJsonPath('data.contacts', 2);

        // A cohort is a saved slice, always up to date.
        $cohort = $this->as($this->owner)->postJson('/api/v1/business/outreach/cohorts', ['name' => 'German, never written to', 'filters' => ['lists' => [$list['uuid']], 'never_sent' => true]])->assertCreated()->json('data');
        $this->assertSame(2, $cohort['count']);
        $this->assertSame('On 1 list, never written to', $cohort['summary']);
        $this->assertSame(2, $this->as($this->owner)->getJson("/api/v1/business/outreach/cohorts/{$cohort['uuid']}/preview")->assertOk()->json('data.count'));
        Contact::where('email', 'hans@mueller.test')->update(['sent_count' => 1]);
        $this->assertSame(1, $this->as($this->owner)->getJson("/api/v1/business/outreach/cohorts/{$cohort['uuid']}/preview")->assertOk()->json('data.count'), 'Written to once, Hans leaves the cohort.');

        // A campaign to a list, and one to a cohort.
        $byList = $this->as($this->owner)->postJson('/api/v1/business/outreach/campaigns', ['name' => 'To Germany', 'template_kind' => 'invitation', 'list' => $list['uuid']])->assertCreated()->json('data');
        $this->assertSame(2, $byList['total']);
        $this->assertSame('Germany buyers', $byList['list']['name']);
        $byCohort = $this->as($this->owner)->postJson('/api/v1/business/outreach/campaigns', ['name' => 'Fresh Germans', 'template_kind' => 'invitation', 'cohort' => $cohort['uuid']])->assertCreated()->json('data');
        $this->assertSame(1, $byCohort['total']);

        // A cohort the team offers is applied to this page's own list.
        $shared = $this->as($this->admin)->postJson('/api/v1/admin/business/outreach/cohorts', ['name' => 'Never written to', 'filters' => ['never_sent' => true]])->assertCreated()->json('data');
        $mine = collect($this->as($this->owner)->getJson('/api/v1/business/outreach/cohorts')->assertOk()->json('data'))->firstWhere('uuid', $shared['uuid']);
        $this->assertFalse($mine['own']);
        $this->assertSame(2, $mine['count'], 'Eva and Ali have never been written to.');
    }

    public function test_a_sequence_follows_up_and_stops_for_whoever_answers(): void
    {
        $a = $this->contact('a@buyer.test');
        $b = $this->contact('b@buyer.test');
        $seq = $this->as($this->owner)->postJson('/api/v1/business/outreach/sequences', [
            'name' => 'Invite then nudge', 'stop_on_reply' => true,
            'steps' => [['template_kind' => 'invitation'], ['template_kind' => 'catalogue', 'delay_days' => 3, 'only_if_no_reply' => true]],
        ])->assertCreated()->json('data');
        $this->assertCount(2, $seq['steps']);
        $this->assertSame(0, $seq['steps'][0]['delay_days'], 'The first step always goes at once.');

        $campaign = $this->as($this->owner)->postJson('/api/v1/business/outreach/campaigns', ['name' => 'Autumn', 'sequence' => $seq['uuid']])->assertCreated()->json('data');
        $this->assertSame('Invite then nudge', $campaign['sequence']['name']);
        $this->as($this->owner)->postJson("/api/v1/business/outreach/campaigns/{$campaign['uuid']}/launch")->assertOk();
        $c = Campaign::where('uuid', $campaign['uuid'])->firstOrFail();
        $this->assertSame(2, Send::where('campaign_id', $c->id)->where('step_position', 1)->count());
        $this->assertSame('sending', $c->status);

        // Both first emails went out five days ago; A wrote back.
        $box = Mailbox::first();
        Send::where('campaign_id', $c->id)->update(['status' => 'sent', 'sent_at' => now()->subDays(5), 'message_id' => 'x']);
        Send::where('campaign_id', $c->id)->where('contact_id', $a->id)->update(['message_id' => 'first-a@grapout.test']);
        $reply = app(InboxSync::class)->record($box, ['imap_uid' => 7, 'message_id' => 'r1@buyer.test', 'in_reply_to' => 'first-a@grapout.test', 'from_email' => 'A@buyer.test', 'from_name' => 'Buyer A', 'subject' => 'Re: invitation', 'body_text' => 'Please send prices.', 'received_at' => now()->subDay()]);
        $this->assertTrue($reply->is_reply);
        $this->assertSame($this->page->id, $reply->page_id);
        $this->assertSame($a->id, $reply->contact_id);
        $this->assertNotNull($a->fresh()->replied_at);
        $this->assertSame('valid', $a->fresh()->email_status, 'An address that answers is a valid one.');
        $this->assertSame(1, $c->fresh()->replied);
        $this->assertNull(app(InboxSync::class)->record($box, ['imap_uid' => 7, 'message_id' => 'r1@buyer.test', 'in_reply_to' => '', 'from_email' => 'a@buyer.test', 'from_name' => '', 'subject' => 'again', 'body_text' => '', 'received_at' => now()]), 'The same message is not filed twice.');

        // The clock: B, who did not answer, gets the nudge; A does not.
        $this->artisan('trade:outreach-campaigns')->assertSuccessful();
        $this->assertSame(1, Send::where('campaign_id', $c->id)->where('step_position', 2)->count());
        $this->assertSame($b->id, Send::where('campaign_id', $c->id)->where('step_position', 2)->value('contact_id'));
        $this->assertSame('catalogue', Send::where('campaign_id', $c->id)->where('step_position', 2)->value('template'));
        $this->assertSame('sending', $c->fresh()->status, 'Not done while a follow-up is queued.');

        // The inbox, from the page's side and the team's.
        $inbox = $this->as($this->owner)->getJson('/api/v1/business/outreach/inbox')->assertOk()->json('data');
        $this->assertCount(1, $inbox);
        $this->assertSame('Please send prices.', $inbox[0]['body_text']);
        $this->assertSame(1, $inbox[0]['answers']['step']);
        $this->as($this->owner)->putJson("/api/v1/business/outreach/inbox/{$inbox[0]['uuid']}/read")->assertOk();
        $this->assertSame(0, $this->as($this->owner)->getJson('/api/v1/business/outreach/stats')->assertOk()->json('data.inbox_unread'));
        $this->assertCount(1, $this->as($this->admin)->getJson('/api/v1/admin/business/outreach/inbox')->assertOk()->json('data'));

        // B's nudge goes out; nobody is owed anything more: done.
        Send::where('campaign_id', $c->id)->where('step_position', 2)->update(['status' => 'sent', 'sent_at' => now()]);
        $c->fresh()->increment('sent');
        $this->artisan('trade:outreach-campaigns')->assertSuccessful();
        $this->assertSame('done', $c->fresh()->status);
    }

    public function test_a_group_of_mailboxes_shares_a_campaign_and_dns_is_read(): void
    {
        $one = $this->as($this->admin)->postJson('/api/v1/admin/business/outreach/mailboxes', ['label' => 'One', 'from_name' => 'One', 'from_address' => 'one@sender.test', 'mailer' => 'smtp', 'smtp_host' => 'smtp.sender.test', 'smtp_port' => 587, 'smtp_password' => 'pw', 'daily_limit' => 2, 'imap_host' => 'imap.sender.test', 'imap_password' => 'pw'])->assertCreated()->json('data');
        $two = $this->as($this->admin)->postJson('/api/v1/admin/business/outreach/mailboxes', ['label' => 'Two', 'from_name' => 'Two', 'from_address' => 'two@sender.test', 'mailer' => 'smtp', 'smtp_host' => 'smtp.sender.test', 'smtp_port' => 587, 'smtp_password' => 'pw', 'daily_limit' => 3])->assertCreated()->json('data');
        $this->assertTrue($one['receives']);
        $this->assertTrue($one['imap_password_saved']);
        $this->assertFalse($two['receives']);
        $group = $this->as($this->admin)->postJson('/api/v1/admin/business/outreach/groups', ['name' => 'Senders', 'mailboxes' => [$one['uuid'], $two['uuid']]])->assertCreated()->json('data');
        $this->assertSame(5, $group['daily_limit']);
        $this->as($this->admin)->putJson("/api/v1/admin/business/outreach/pages/{$this->page->uuid}", ['outreach_mailbox_group' => $group['uuid']])->assertOk()->assertJsonPath('data.outreach_mailbox_group', $group['uuid']);

        foreach (range(1, 4) as $i) {
            $this->contact("buyer{$i}@x.test");
        }
        $campaign = $this->as($this->owner)->postJson('/api/v1/business/outreach/campaigns', ['name' => 'Spread', 'template_kind' => 'invitation'])->assertCreated()->json('data');
        $this->as($this->owner)->postJson("/api/v1/business/outreach/campaigns/{$campaign['uuid']}/launch")->assertOk();
        $byBox = Send::where('page_id', $this->page->id)->get()->countBy('mailbox_id');
        $oneId = Mailbox::where('uuid', $one['uuid'])->value('id');
        $twoId = Mailbox::where('uuid', $two['uuid'])->value('id');
        $this->assertSame(4, $byBox->sum());
        $this->assertLessThanOrEqual(2, $byBox[$oneId] ?? 0, 'One never sends past its own allowance.');
        $this->assertLessThanOrEqual(3, $byBox[$twoId] ?? 0);
        $this->assertGreaterThan(0, $byBox[$oneId] ?? 0, 'Both mailboxes carry some of the load.');
        $this->assertGreaterThan(0, $byBox[$twoId] ?? 0);

        // DNS, answered by a stand-in so the test asks nobody.
        MailboxDoctor::$resolver = fn (string $host) => match ($host) {
            'sender.test' => [['txt' => 'v=spf1 include:_spf.sender.test ~all']],
            '_dmarc.sender.test' => [['txt' => 'v=DMARC1; p=none']],
            'google._domainkey.sender.test' => [['txt' => 'v=DKIM1; k=rsa; p=MIGf']],
            default => [],
        };
        try {
            $r = $this->as($this->admin)->postJson("/api/v1/admin/business/outreach/mailboxes/{$one['uuid']}/dns")->assertOk()->json('data');
            $this->assertTrue($r['spf']);
            $this->assertTrue($r['dkim']);
            $this->assertSame('google', $r['dkim_selector']);
            $this->assertTrue($r['dmarc']);
            $this->assertSame(100, $r['score']);
            $this->assertSame(100, $r['mailbox']['dns']['score']);
        } finally {
            MailboxDoctor::$resolver = null;
        }

        // A copy to change the address on; secrets come along, standing does not.
        $copy = $this->as($this->admin)->postJson("/api/v1/admin/business/outreach/mailboxes/{$one['uuid']}/replicate")->assertCreated()->json('data');
        $this->assertSame('One (copy)', $copy['label']);
        $this->assertTrue($copy['smtp_password_saved']);
        $this->assertNull($copy['dns']);
    }
}
