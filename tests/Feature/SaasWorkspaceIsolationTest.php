<?php

namespace Tests\Feature;

use App\Models\SaasBotbleStore;
use App\Models\SaasCjConnection;
use App\Models\SaasWorkspace;
use App\Models\User;
use App\Services\Saas\WorkspacePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Tests\TestCase;

class SaasWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
    }

    private function user(string $email, bool $verified = true): User
    {
        return User::factory()->create([
            'email' => $email,
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    private function workspace(User $user, string $role = 'owner'): SaasWorkspace
    {
        $workspace = SaasWorkspace::create(['name' => 'Workspace for '.$user->id]);
        $workspace->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $workspace;
    }

    public function test_saas_is_disabled_for_existing_installations(): void
    {
        config()->set('saas.enabled', false);

        $this->get('/app/login')->assertNotFound();
        $this->get('/app/register')->assertNotFound();
    }

    public function test_public_registration_requires_separate_release_flag(): void
    {
        $this->get('/app/register')->assertNotFound();
        $this->post('/app/register', [
            'name' => 'Someone', 'workspace_name' => 'Merchant',
            'email' => 'user@example.test', 'password' => 'ExampleStrongPass123',
            'password_confirmation' => 'ExampleStrongPass123',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_signup_creates_a_private_workspace_and_requires_email_verification(): void
    {
        config()->set('saas.registration_enabled', true);
        Notification::fake();

        $this->post('/app/register', [
            'name' => 'First Merchant',
            'workspace_name' => 'First Botble Store',
            'email' => 'merchant@example.test',
            'password' => 'SafePasswordExample123',
            'password_confirmation' => 'SafePasswordExample123',
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'merchant@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('saas_workspace_memberships', [
            'user_id' => $user->id, 'role' => 'owner', 'status' => 'active',
        ]);
        Notification::assertSentTo($user, VerifyEmail::class);

        $workspace = SaasWorkspace::firstOrFail();
        $this->get(route('saas.workspace.dashboard', ['workspace' => $workspace->public_id]))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_nonmembers_cannot_read_another_merchants_workspace(): void
    {
        $alice = $this->user('alice@example.test');
        $bob = $this->user('bob@example.test');
        $alpha = $this->workspace($alice);
        $beta = $this->workspace($bob);

        $alpha->products()->create(['title' => 'Alice-only private product', 'cj_product_id' => 'A01']);
        $beta->products()->create(['title' => 'Bob-only product', 'cj_product_id' => 'B01']);
        $beta->products()->create(['title' => 'Another Bob-only product', 'cj_product_id' => 'B02']);
        $alpha->stores()->create(['name' => 'Alice Botble', 'base_url' => 'https://alice.example.test']);

        $this->actingAs($alice)->get(route('saas.workspace.dashboard', ['workspace' => $alpha->public_id]))
            ->assertOk()
            ->assertSee($alpha->name)
            ->assertSee('>1</b>', false)
            ->assertDontSee($beta->name)
            ->assertDontSee('Bob-only product');

        $this->actingAs($alice)->get(route('saas.workspace.dashboard', ['workspace' => $beta->public_id]))
            ->assertNotFound();
        $this->actingAs($bob)->get(route('saas.workspace.dashboard', ['workspace' => $alpha->public_id]))
            ->assertNotFound();
    }

    public function test_workspace_roles_never_grant_platform_or_other_merchants_permissions(): void
    {
        $owner = $this->user('owner@example.test');
        $staff = $this->user('staff@example.test');
        $viewer = $this->user('viewer@example.test');
        $stranger = $this->user('stranger@example.test');

        $workspace = $this->workspace($owner);
        $workspace->members()->attach($staff->id, ['role' => 'importer', 'status' => 'active']);
        $workspace->members()->attach($viewer->id, ['role' => 'viewer', 'status' => 'active']);
        $access = app(WorkspacePermissions::class);

        $this->assertTrue($access->allows($owner, $workspace, 'manage_billing'));
        $this->assertTrue($access->allows($staff, $workspace, 'import'));
        $this->assertFalse($access->allows($staff, $workspace, 'manage_billing'));
        $this->assertFalse($access->allows($staff, $workspace, 'publish'));
        $this->assertFalse($access->allows($viewer, $workspace, 'import'));
        $this->assertFalse($access->allows($stranger, $workspace, 'view'));
        $this->assertFalse($access->allows($owner, $workspace, 'platform_admin'));
    }

    public function test_sensitive_cj_and_botble_credentials_are_encrypted_and_not_serialized(): void
    {
        $owner = $this->user('admin@example.test');
        $workspace = $this->workspace($owner);

        $cj = $workspace->cjConnections()->create(['status' => 'connected']);
        $cj->api_key = 'cj-private-key-must-not-leak';
        $cj->access_token = 'cj-private-access';
        $cj->save();

        $store = $workspace->stores()->create([
            'name' => 'Botble Store',
            'base_url' => 'https://botble.example.test',
        ]);
        $store->shared_secret = 'botble-private-secret';
        $store->save();

        $this->assertNotEquals('cj-private-key-must-not-leak', DB::table('saas_cj_connections')->first()->api_key);
        $this->assertNotEquals('botble-private-secret', DB::table('saas_botble_stores')->first()->shared_secret);
        $this->assertSame('cj-private-key-must-not-leak', $cj->fresh()->api_key);
        $this->assertSame('botble-private-secret', $store->fresh()->shared_secret);
        $this->assertArrayNotHasKey('api_key', $cj->toArray());
        $this->assertArrayNotHasKey('shared_secret', $store->toArray());
    }

    public function test_only_a_valid_signed_email_link_verifies_the_customer(): void
    {
        $user = $this->user('needs-verification@example.test', false);
        $workspace = $this->workspace($user);

        $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $this->actingAs($user)->get($signedUrl)->assertRedirect(route('saas.home'));
        $this->assertNotNull($user->fresh()->email_verified_at);

        $other = $this->user('tampered@example.test', false);
        $tampered = str_replace('hash=', 'hash=forged', $signedUrl);
        $this->actingAs($other)->get($tampered)->assertForbidden();
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_revoked_membership_immediately_loses_workspace_access(): void
    {
        $owner = $this->user('revoked@example.test');
        $workspace = $this->workspace($owner);

        $this->actingAs($owner)->get(route('saas.workspace.dashboard', $workspace->public_id))
            ->assertOk();

        \Illuminate\Support\Facades\DB::table('saas_workspace_memberships')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $owner->id)
            ->update(['status' => 'revoked']);

        $this->actingAs($owner)->get(route('saas.workspace.dashboard', $workspace->public_id))
            ->assertNotFound();
    }

    public function test_unauthed_workspace_url_goes_to_customer_login_not_legacy_admin_login(): void
    {
        $user = $this->user('u@example.test');
        $workspace = $this->workspace($user);

        $this->get(route('saas.workspace.dashboard', ['workspace' => $workspace->public_id]))
            ->assertRedirect(route('saas.login'));
    }
}
