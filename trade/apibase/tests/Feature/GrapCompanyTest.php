<?php

namespace Tests\Feature;

use App\Models\Grap\Reveal;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GrapUp\Pipeline\CompanySearch;
use App\Services\GrapUp\Serper;
use App\Services\GrapUp\Verifier\Status;
use App\Services\GrapUp\Verifier\VerificationProvider;
use App\Services\GrapUp\Verifier\Verifier;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Grap Company endpoint, and the gate in front of it.
 *
 * Everything downstream is careful about spending — a free DNS lookup ahead of
 * every paid check, a cache that never pays twice — but none of it can refuse
 * a search the plan does not cover. That decision is the controller's, and it
 * has to happen before the vendor is called, because after is too late: the
 * money is gone whether or not the answer is shown.
 *
 * The pipeline itself is stubbed here. What it finds is tested in
 * GrapUpPipelineTest; what it is allowed to cost is tested here.
 */
class GrapCompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create([
            'name' => 'Scout', 'username' => 'scout',
            'email' => 'scout@grapout.test', 'email_verified_at' => now(),
        ]);
        $this->user->settings()->create([]);
        $this->user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($this->user);

        // The keys are present as far as the controller is concerned; nothing
        // in these tests reaches a vendor.
        config()->set('grapup.serper.key', 'test-key');
        config()->set('grapup.clearout.token', 'test-token');
        $this->swap(Verifier::class, new Verifier(new class implements VerificationProvider
        {
            public function name(): string
            {
                return 'stub';
            }

            public function verify(string $email): string
            {
                return Status::DELIVERABLE;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function circuit(): array
            {
                return ['state' => 'closed', 'consecutive_failures' => 0, 'retry_in_seconds' => null];
            }
        }));
    }

    private function onPlan(?int $reveals, bool $enabled = true): void
    {
        $plan = Plan::create([
            'slug' => 'test-' . uniqid(), 'name' => 'Test', 'monthly_price' => 0, 'annual_price' => 0,
            'limits' => [
                'grap_reveals_per_day' => $reveals,
                'grap_reveals_per_month' => $reveals === null ? null : $reveals * 10,
            ],
            'features' => ['grap_leads' => $enabled],
        ]);

        Subscription::create([
            'user_id' => $this->user->id, 'plan_id' => $plan->id,
            'status' => 'active', 'started_at' => now()->subDay(),
        ]);
    }

    /** Stand in for the pipeline, so nothing here touches the network. */
    private function pipelineReturns(array $result, ?int &$calls = null): void
    {
        $calls = 0;

        $this->swap(CompanySearch::class, new class($result, $calls) extends CompanySearch
        {
            public function __construct(private array $result, private int &$calls) {}

            public function run(
                string $companyName, string $country = '', string $targetRole = '',
                ?\App\Models\User $user = null, bool $refresh = false, ?int $maxContacts = null,
            ): array {
                $this->calls++;

                return $this->result;
            }
        });
    }

    private function found(array $contacts, bool $cached = false): array
    {
        return [
            'company' => ['name' => 'Acme Ltd', 'domain' => 'acme.com', 'email' => 'info@acme.com', 'phone' => null],
            'contacts' => $contacts,
            'corporate_format' => '{f}.{l}@acme.com',
            'verification_unavailable' => false,
            'credits_spent' => 3,
            'cached' => $cached,
        ];
    }

    private function person(string $first, ?string $email, string $status = 'deliverable'): array
    {
        return [
            'first_name' => $first, 'last_name' => 'Smith', 'email' => $email,
            'status' => $status, 'headline' => null, 'linkedin_url' => null, 'corroborated' => true,
        ];
    }

    // --- The gate ------------------------------------------------------------

    public function test_a_search_returns_the_company_and_its_people(): void
    {
        $this->onPlan(10);
        $this->pipelineReturns($this->found([$this->person('Alice', 'alice.smith@acme.com')]));

        $body = $this->actingAs($this->user)
            ->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])
            ->assertOk()->json();

        $this->assertSame('acme.com', $body['data']['company']['domain']);
        $this->assertSame('alice.smith@acme.com', $body['data']['contacts'][0]['email']);
        $this->assertSame('{f}.{l}@acme.com', $body['data']['corporate_format']);
    }

    /**
     * The search limit counts searches, not the rest of the app.
     *
     * Laravel keys an unnamed throttle on the user id alone, so this route's
     * 20/min and the group's 180/min used to share one counter: a few page
     * loads spent the search allowance and the first real search came back
     * "Too Many Attempts".
     */
    public function test_ordinary_browsing_does_not_use_up_the_search_limit(): void
    {
        $this->onPlan(null);
        $this->pipelineReturns($this->found([]));

        for ($i = 0; $i < 25; $i++) {
            $this->actingAs($this->user)->getJson('/api/v1/grap/company/status')->assertOk();
        }

        $this->actingAs($this->user)
            ->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])
            ->assertOk();
    }

    /**
     * Refused before the pipeline runs, not after.
     *
     * A search that finds five people and then discovers the allowance was
     * spent has already paid the vendor for all five. Refusing up front is the
     * only point at which refusing is free.
     */
    public function test_an_exhausted_allowance_refuses_before_anything_is_spent(): void
    {
        $this->onPlan(1);
        $this->pipelineReturns($this->found([$this->person('Alice', 'alice.smith@acme.com')]), $calls);

        $this->actingAs($this->user)->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])->assertOk();
        $this->actingAs($this->user)->postJson('/api/v1/grap/company/search', ['company_name' => 'Globex'])->assertStatus(429);

        $this->assertSame(1, $calls, 'the second search never reached the pipeline');
    }

    public function test_a_plan_without_hot_leads_cannot_search_at_all(): void
    {
        $this->onPlan(10, enabled: false);
        $this->pipelineReturns($this->found([]), $calls);

        $this->actingAs($this->user)
            ->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])
            ->assertForbidden();

        $this->assertSame(0, $calls);
    }

    public function test_it_says_so_rather_than_failing_when_the_keys_are_missing(): void
    {
        $this->onPlan(10);
        config()->set('grapup.serper.key', '');
        $this->pipelineReturns($this->found([]), $calls);

        $this->actingAs($this->user)
            ->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])
            ->assertStatus(503);

        $this->assertSame(0, $calls);
    }

    // --- What gets charged ---------------------------------------------------

    /**
     * Charged for what was delivered, not for what was asked.
     *
     * One search can return five usable addresses or none, and the vendor bill
     * differs by more than an order of magnitude between them. The person's
     * allowance counts contacts, so that is what is recorded.
     */
    public function test_only_people_with_an_address_are_charged_for(): void
    {
        $this->onPlan(null);
        $this->pipelineReturns($this->found([
            $this->person('Alice', 'alice.smith@acme.com'),
            $this->person('Bob', null, 'locked'),
            $this->person('Carol', 'carol.smith@acme.com'),
            $this->person('Dave', null, 'unknown'),
        ]));

        $this->actingAs($this->user)->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])->assertOk();

        $this->assertSame(2, Reveal::where('user_id', $this->user->id)->count(), 'two addresses, two receipts');
        $this->assertDatabaseHas('grap_reveals', [
            'email' => 'alice.smith@acme.com', 'source' => Reveal::SOURCE_COMPANY, 'company_name' => 'Acme Ltd',
        ]);
    }

    /** A cached answer has already been paid for, by whoever asked first. */
    public function test_a_cached_answer_costs_nothing(): void
    {
        $this->onPlan(null);
        $this->pipelineReturns($this->found([$this->person('Alice', 'alice.smith@acme.com')], cached: true));

        $this->actingAs($this->user)->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])->assertOk();

        $this->assertSame(0, Reveal::where('user_id', $this->user->id)->count());
    }

    /**
     * The same address twice is one contact, not two.
     *
     * Searching a company, then searching it again with a department filter,
     * returns some of the same people — and charging again for an address
     * already unlocked is the exact behaviour the old site had and this does
     * not.
     */
    public function test_the_same_address_found_twice_is_charged_once(): void
    {
        $this->onPlan(null);
        $this->pipelineReturns($this->found([$this->person('Alice', 'alice.smith@acme.com')]));

        $this->actingAs($this->user)->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])->assertOk();
        $this->actingAs($this->user)->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd', 'target_role' => 'sales'])->assertOk();

        $this->assertSame(1, Reveal::where('user_id', $this->user->id)->count());
    }

    /**
     * The point of putting both tabs on one allowance: unlocking a buyer and
     * finding a contact at a company draw down the same number, so "how many
     * have I left today" has one answer.
     */
    public function test_company_contacts_and_directory_unlocks_share_the_allowance(): void
    {
        $this->onPlan(2);
        $this->pipelineReturns($this->found([
            $this->person('Alice', 'alice.smith@acme.com'),
            $this->person('Carol', 'carol.smith@acme.com'),
        ]));

        $this->actingAs($this->user)->postJson('/api/v1/grap/company/search', ['company_name' => 'Acme Ltd'])->assertOk();

        // Two contacts came back, so the day's two are gone — including for
        // the directory tab, which is the whole point.
        $lead = \Database\Factories\Grap\LeadFactory::new()->buyer()->create(['email' => 'x@x.com']);
        $this->actingAs($this->user)
            ->postJson("/api/v1/grap/leads/{$lead->uuid}/reveal")
            ->assertStatus(429);
    }

    // --- Status --------------------------------------------------------------

    public function test_status_says_what_is_left_and_which_departments_exist(): void
    {
        $this->onPlan(5);

        $body = $this->actingAs($this->user)->getJson('/api/v1/grap/company/status')->assertOk()->json('data');

        $this->assertTrue($body['enabled']);
        $this->assertTrue($body['configured']);
        $this->assertSame(5, $body['contacts_left_today']);
        $this->assertContains('procurement', $body['roles']);
    }

    public function test_an_unlimited_plan_reports_null_rather_than_zero(): void
    {
        $this->onPlan(null);

        $this->assertNull(
            $this->actingAs($this->user)->getJson('/api/v1/grap/company/status')->json('data.contacts_left_today'),
        );
    }

    public function test_a_company_name_is_required(): void
    {
        $this->onPlan(10);
        $this->pipelineReturns($this->found([]), $calls);

        $this->actingAs($this->user)->postJson('/api/v1/grap/company/search', ['company_name' => 'A'])->assertStatus(422);
        $this->assertSame(0, $calls);
    }
}
