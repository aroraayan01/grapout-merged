<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $admin;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->makeWithRole('super_admin');
        $this->admin = $this->makeWithRole('admin');
        $this->user = $this->makeWithRole('user');
    }

    protected function makeWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $slug)->first()->id);

        return $user;
    }

    public function test_regular_user_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/admin/stats')->assertForbidden();
        $this->actingAs($this->user)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_admin_can_view_stats_and_users(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['users', 'tasks']]);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_admin_can_suspend_and_activate_user(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/suspend")
            ->assertOk();

        $this->assertEquals('suspended', $this->user->fresh()->status);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/activate")
            ->assertOk();

        $this->assertEquals('active', $this->user->fresh()->status);
    }

    public function test_an_admin_is_the_top_of_the_platform_and_a_subadmin_is_not(): void
    {
        // Admin and the seeded super admin are the same rank: either may act on the other.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->superAdmin->uuid}/suspend")
            ->assertOk();
        // A sub-admin may not touch an admin.
        $sub = $this->makeWithRole('subadmin');
        $this->app['auth']->forgetGuards();
        $this->actingAs($sub)
            ->postJson("/api/v1/admin/users/{$this->admin->uuid}/suspend")
            ->assertForbidden();
    }

    public function test_an_admin_creates_admins_and_subadmins_but_a_subadmin_creates_neither_admin(): void
    {
        $payload = [
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
            'password' => 'Password123',
            'role' => 'admin',
        ];

        $sub = $this->makeWithRole('subadmin');
        $this->actingAs($sub)->postJson('/api/v1/admin/users', $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', $payload)->assertCreated()->assertJsonPath('data.roles.0', 'admin');
    }

    public function test_admin_can_create_subadmin(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', [
            'name' => 'Sub',
            'email' => 'sub@example.com',
            'password' => 'Password123',
            'role' => 'subadmin',
        ])->assertCreated()->assertJsonPath('data.roles.0', 'subadmin');
    }

    public function test_super_admin_can_regenerate_app_id(): void
    {
        app(AppIdService::class)->generateFor($this->user);
        $old = $this->user->appId->app_id;

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/app-id/regenerate")
            ->assertOk();

        $this->assertNotEquals($old, $response->json('data.app_id'));
        $this->assertEquals($old, $response->json('data.previous'));
    }

    public function test_suspended_user_is_locked_out_of_api(): void
    {
        $token = $this->user->createToken('web')->plainTextToken;
        $this->user->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_an_admin_runs_no_company_page_but_can_sign_in_as_a_member(): void
    {
        $this->admin->forceFill(['email_verified_at' => now()])->save();
        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->user->settings()->create([]);
        $this->user->profile()->create(['timezone' => 'Asia/Kolkata']);

        // No page of their own: the control room is theirs, not a shop.
        $this->actingAs($this->admin)->postJson('/api/v1/business/page', ['name' => 'Admin Exports', 'country' => 'IN', 'keywords' => []])->assertStatus(422);

        // Login as: a token for the member, marked as borrowed.
        $r = $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->user->uuid}/impersonate")->assertOk()->json('data');
        $this->assertSame($this->user->uuid, $r['user']['uuid']);
        $this->assertSame('account', $r['impersonation']['level']);
        $token = $r['token'];
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.uuid', $this->user->uuid);
        // ...that cannot touch what keeps the account.
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/change-password', ['current_password' => 'x', 'password' => 'Newpass123', 'password_confirmation' => 'Newpass123'])->assertForbidden();
        // ...and dies on the way out.
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/impersonation/stop')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/me')->assertUnauthorized();

        // Never into another admin, and never by a sub-admin.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->superAdmin->uuid}/impersonate")->assertForbidden();
        $sub = $this->makeWithRole('subadmin');
        $this->app['auth']->forgetGuards();
        $this->actingAs($sub)->postJson("/api/v1/admin/users/{$this->user->uuid}/impersonate")->assertForbidden();
    }
}
