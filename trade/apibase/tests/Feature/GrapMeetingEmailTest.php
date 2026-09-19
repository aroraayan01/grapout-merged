<?php

namespace Tests\Feature;

use App\Mail\OutreachMail;
use App\Models\Grap\Reveal;
use App\Models\User;
use App\Services\AppIdService;
use Database\Factories\Grap\LeadFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Cohorts, and the Book meeting email.
 *
 * It goes from GrapOut's own address, so GrapOut's sending reputation rides
 * on every one. What these guard is that it only ever reaches an address Hot
 * Leads found for the person sending it — never whatever they type.
 */
class GrapMeetingEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config()->set('mail.from.address', 'meetings@grapout.test');
        Mail::fake();

        $this->user = $this->person('scout', granted: true);
    }

    private function person(string $name, bool $granted): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($name), 'username' => $name,
            'email' => "{$name}@grapout.test", 'email_verified_at' => now(),
        ]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);
        $user->forceFill(['grap_leads_granted' => $granted])->save();

        return $user->fresh();
    }

    private function book(string $to, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/api/v1/grap/meeting-email', [
            'to_email' => $to,
            'contact_name' => 'Jane Doe',
            'company_name' => 'Acme',
            'subject' => 'Meeting with Acme',
            'body' => "Hi Jane,\nShall we talk?",
        ]);
    }

    // --- Cohorts -------------------------------------------------------------

    public function test_a_cohort_belongs_to_the_person_who_wrote_it(): void
    {
        $uuid = $this->actingAs($this->user)
            ->postJson('/api/v1/grap/cohorts', ['name' => 'Buyers', 'subject' => 'Hello {{company_name}}', 'body' => 'Hi {{first_name}}'])
            ->assertCreated()
            ->json('data.uuid');

        $this->actingAs($this->user)->getJson('/api/v1/grap/cohorts')->assertJsonCount(1, 'data');

        $other = $this->person('other', granted: true);
        $this->actingAs($other)->getJson('/api/v1/grap/cohorts')->assertJsonCount(0, 'data');
        $this->actingAs($other)
            ->putJson("/api/v1/grap/cohorts/{$uuid}", ['name' => 'Mine now', 'subject' => 'x', 'body' => 'y'])
            ->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/grap/cohorts/{$uuid}")->assertNotFound();
    }

    public function test_two_cohorts_of_one_person_cannot_share_a_name(): void
    {
        $cohort = ['name' => 'Buyers', 'subject' => 'Hello', 'body' => 'Hi'];

        $this->actingAs($this->user)->postJson('/api/v1/grap/cohorts', $cohort)->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/grap/cohorts', $cohort)->assertUnprocessable();
    }

    // --- Book meeting --------------------------------------------------------

    public function test_book_meeting_sends_from_grapout_with_replies_to_the_sender(): void
    {
        $lead = LeadFactory::new()->create(['kind' => 'buyer', 'email' => 'jane@acme.com']);
        Reveal::create(['user_id' => $this->user->id, 'grap_lead_id' => $lead->id, 'channels' => 'both']);

        $this->book('jane@acme.com')->assertOk();

        Mail::assertSent(OutreachMail::class, fn (OutreachMail $mail) => $mail->hasTo('jane@acme.com')
            && $mail->fromAddress === 'meetings@grapout.test'
            && $mail->replyToAddress === 'scout@grapout.test'
            && $mail->subjectLine === 'Meeting with Acme'
            && str_contains($mail->body, 'Hi Jane,<br />'));

        $this->assertDatabaseHas('grap_meeting_emails', [
            'user_id' => $this->user->id, 'to_email' => 'jane@acme.com', 'status' => 'sent',
        ]);
    }

    public function test_a_lead_that_was_not_unlocked_cannot_be_written_to(): void
    {
        LeadFactory::new()->create(['kind' => 'buyer', 'email' => 'jane@acme.com']);

        $this->book('jane@acme.com')->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_an_address_hot_leads_never_found_cannot_be_written_to(): void
    {
        $this->book('anyone@example.com')->assertForbidden();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('grap_meeting_emails', 0);
    }

    public function test_an_address_found_by_a_grap_company_search_can_be_written_to(): void
    {
        // A cached Grap Company answer charges nobody and leaves no receipt;
        // the search cache is the record that the address came from us.
        DB::table('grap_search_cache')->insert([
            'company_name' => 'Acme', 'country' => '', 'target_role' => '', 'max_contacts' => 0,
            'payload' => json_encode(['contacts' => [['email' => 'ravi.kumar@acme.com']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->book('Ravi.Kumar@acme.com')->assertOk();

        Mail::assertSent(OutreachMail::class, fn (OutreachMail $mail) => $mail->hasTo('ravi.kumar@acme.com'));
    }

    public function test_without_hot_leads_nothing_is_sent(): void
    {
        $outsider = $this->person('outsider', granted: false);

        $this->book('jane@acme.com', $outsider)->assertForbidden();

        Mail::assertNothingSent();
    }
}
