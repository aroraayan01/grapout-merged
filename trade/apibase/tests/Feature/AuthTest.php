<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_can_register_and_receives_app_id(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser1',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'mobile' => '+919812345678',
            'timezone' => 'Asia/Kolkata',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['uuid', 'name', 'username', 'app_id', 'roles'], 'token'])
            ->assertJsonPath('email_verification_pending', true);

        $this->assertStringStartsWith('NV-', $response->json('data.app_id'));
        $this->assertContains('user', $response->json('data.roles'));
        $this->assertDatabaseHas('users', ['mobile' => '+919812345678', 'username' => 'testuser1']);
        $this->assertDatabaseHas('user_profiles', []);
        $this->assertDatabaseHas('user_settings', []);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dupe@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dupe',
            'email' => 'dupe@example.com',
            'username' => 'dupeuser',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => 'Password123']);
        $user->settings()->create([]);

        // A device this account has never signed in on is asked for a code
        // as well as the password, so the password alone yields no token.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertStatus(202)->assertJsonPath('otp_required', true);

        $code = \App\Models\MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'login')->value('code');

        $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => $user->email,
            'code' => $code,
        ])->assertOk()->assertJsonStructure(['token', 'device_token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'Password123']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword1',
        ])->assertUnprocessable();
    }

    public function test_suspended_user_cannot_login(): void
    {
        $user = User::factory()->create(['password' => 'Password123', 'status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertUnprocessable();
    }

    public function test_authenticated_user_can_fetch_me(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.uuid', $user->uuid);
    }

    public function test_guest_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }

    public function test_user_can_change_password(): void
    {
        // Not about sign-in codes: this test signs in only to get at what
        // it is really checking, so the second step is switched off.
        \App\Models\AppSetting::set('login_otp_mode', 'off');

        $user = User::factory()->create(['password' => 'OldPassword1']);
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword1',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewPassword1',
        ])->assertOk();
    }

    public function test_a_registration_with_a_company_name_opens_the_company_page_and_needs_no_username(): void
    {
        // The sign-up throttle counts per address across the whole run; this test registers twice.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Priya Patel',
            'company_name' => 'Sunrise Handicrafts Pvt. Ltd.',
            'kind' => 'manufacturer',
            'country' => 'in',
            'email' => 'priya@sunrise.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated();
        $this->assertSame('sunrisehandicraftspv', $response->json('data.username'), 'A handle is derived from the company when none is sent.');

        $user = \App\Models\User::where('email', 'priya@sunrise.test')->firstOrFail();
        $this->assertSame('business', $user->profile->account_type);
        $page = $user->businessPage()->first();
        $this->assertNotNull($page, 'The company page opens with the account.');
        $this->assertSame('Sunrise Handicrafts Pvt. Ltd.', $page->name);
        $this->assertSame('IN', $page->country);
        $this->assertSame('manufacturer', $page->kind);
        $this->assertSame('owner', $page->roleOf($user));
        $this->assertSame('none', $page->verification_status, 'The badge is earned later, from the document folder.');

        // The same company name again is another company: a fresh page, a different handle.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Amit Verma', 'company_name' => 'Sunrise Handicrafts Pvt. Ltd.', 'email' => 'amit@sunrise2.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated()->assertJsonPath('data.username', 'sunrisehandicraftsp1');
    }

    public function test_a_registration_can_join_an_existing_company_instead_of_opening_one(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        \Illuminate\Support\Facades\Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Priya Patel', 'company_name' => 'Sunrise Handicrafts', 'kind' => 'exporter', 'email' => 'priya@sunrise.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated();
        $owner = \App\Models\User::where('email', 'priya@sunrise.test')->firstOrFail();
        $owner->forceFill(['email_verified_at' => now()])->save();
        $page = $owner->businessPage()->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Amit Verma', 'join_page' => $page->slug, 'function' => 'sales', 'email' => 'amit@sunrise.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertCreated()->assertJsonPath('data.username', 'amitverma');

        $amit = \App\Models\User::where('email', 'amit@sunrise.test')->firstOrFail();
        $this->assertNull($amit->businessPage()->first(), 'No page of his own, and not on the team until the owner says so.');
        $this->assertDatabaseHas('company_members', ['page_id' => $page->id, 'user_id' => $amit->id, 'status' => 'requested', 'role' => 'representative', 'function' => 'sales']);
        \Illuminate\Support\Facades\Notification::assertSentTo($owner, \App\Notifications\SocialNotification::class, fn ($n) => $n->kind === 'company_team');
        $this->assertSame(1, \App\Models\Business\Page::count(), 'Joining opens no second page.');

        // While the request waits, no page of his own; the owner sees who is asking; withdrawn, the door opens.
        $amit->forceFill(['email_verified_at' => now()])->save();
        $this->app['auth']->forgetGuards();
        $me = $this->actingAs($amit)->getJson('/api/v1/business/me')->assertOk()->json('data');
        $this->assertSame('Sunrise Handicrafts', $me['join_request']['company']['name']);
        $this->actingAs($amit)->postJson('/api/v1/business/page', ['name' => 'Amit Exports', 'country' => 'IN', 'keywords' => []])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $team = $this->actingAs($owner)->getJson('/api/v1/business/page/team')->assertOk()->json('data');
        $asking = collect($team)->firstWhere('status', 'requested');
        $this->assertSame('amit@sunrise.test', $asking['email'], 'The owner sees the email of who is asking.');
        $this->app['auth']->forgetGuards();
        $this->actingAs($amit)->deleteJson("/api/v1/business/page/team/{$me['join_request']['member_id']}")->assertOk();
        $this->actingAs($amit)->postJson('/api/v1/business/page', ['name' => 'Amit Exports', 'country' => 'IN', 'keywords' => []])->assertCreated();
    }
}
