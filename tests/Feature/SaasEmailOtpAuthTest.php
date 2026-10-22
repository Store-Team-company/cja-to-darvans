<?php

namespace Tests\Feature;

use App\Models\BdpAdminProfile;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SaasEmailOtpAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.registration_enabled', true);
        Mail::fake();
    }

    private function customer(bool $verified = false): array
    {
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
            'password' => Hash::make('ExistingCustomerPassword123'),
        ]);
        $workspace = SaasWorkspace::create(['name' => 'Customer workspace']);
        $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        return [$user, $workspace];
    }

    private function platformAdmin(): void
    {
        BdpAdminProfile::create([
            'name' => 'Data Pilot Admin',
            'username' => 'admin_for_testing',
            'password' => Hash::make('A-Secure-Admin-Password123'),
        ]);
        $this->post(route('bdp.login'), [
            'username' => 'admin_for_testing',
            'password' => 'A-Secure-Admin-Password123',
        ])->assertRedirect(route('bdp.index'));
    }

    public function test_signup_requires_password_and_never_sends_otp_or_logs_in(): void
    {
        $this->post(route('saas.register.submit'), [
            'name' => 'Owner', 'workspace_name' => 'Testing shop',
            'email' => 'test-shop@example.test',
        ])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);

        $this->post(route('saas.register.submit'), [
            'name' => 'Owner', 'workspace_name' => 'Testing shop',
            'email' => 'test-shop@example.test',
            'password' => 'SecurePasswordExample123',
            'password_confirmation' => 'SecurePasswordExample123',
        ])->assertRedirect(route('saas.login'));

        $user = User::where('email', 'test-shop@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('SecurePasswordExample123', $user->password));
        $this->assertNull($user->saas_manually_approved_at);
        $this->assertNull($user->email_verified_at);
        $this->assertGuest();
        Mail::assertNothingSent();
    }

    public function test_otp_endpoints_are_removed_not_only_hidden_in_view(): void
    {
        $this->get('/app/otp')->assertNotFound();
        $this->post('/app/otp/send', ['email' => 'test@example.test'])->assertNotFound();
        $this->post('/app/otp/verify', ['code' => '123456'])->assertNotFound();
        $this->get(route('saas.login'))->assertOk()
            ->assertSee('Sign in with password')
            ->assertDontSee('Continue with email OTP');
        Mail::assertNothingSent();
    }

    public function test_valid_password_still_requires_admin_activation(): void
    {
        [$user] = $this->customer();
        $this->post(route('saas.login.submit'), [
            'email' => $user->email,
            'password' => 'ExistingCustomerPassword123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_only_platform_admin_can_approve_and_reset_customer_password(): void
    {
        [$user] = $this->customer();

        $this->post(route('bdp.saas.customers.approve', $user), [
            'password' => 'ChangedCustomerPassword123',
            'password_confirmation' => 'ChangedCustomerPassword123',
        ])->assertRedirect(route('bdp.login.form'));
        $this->assertNull($user->fresh()->saas_manually_approved_at);

        $this->platformAdmin();
        $this->get(route('bdp.saas.customers'))->assertOk()
            ->assertSee($user->email);
        $this->post(route('bdp.saas.customers.approve', $user), [
            'password' => 'ChangedCustomerPassword123',
            'password_confirmation' => 'ChangedCustomerPassword123',
        ])->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->saas_manually_approved_at);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->saas_password_change_required);
        $this->assertTrue(Hash::check('ChangedCustomerPassword123', $user->password));
        $this->assertDatabaseHas('saas_customer_admin_events', [
            'user_id' => $user->id, 'event' => 'activated_and_password_rotated',
        ]);
    }

    public function test_approved_user_must_change_admin_set_temporary_password(): void
    {
        [$user, $workspace] = $this->customer();
        $user->forceFill([
            'saas_manually_approved_at' => now(),
            'saas_password_change_required' => true,
        ])->save();

        $this->post(route('saas.login.submit'), [
            'email' => strtoupper($user->email),
            'password' => 'ExistingCustomerPassword123',
        ])->assertRedirect(route('saas.password.change.form'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('saas.home'))->assertRedirect(route('saas.password.change.form'));
        $this->get(route('saas.workspace.dashboard', $workspace->public_id))
            ->assertRedirect(route('saas.password.change.form'));

        $this->post(route('saas.password.change'), [
            'current_password' => 'bad-password',
            'password' => 'BrandNewCustomerPassword123',
            'password_confirmation' => 'BrandNewCustomerPassword123',
        ])->assertSessionHasErrors('current_password');

        $this->post(route('saas.password.change'), [
            'current_password' => 'ExistingCustomerPassword123',
            'password' => 'BrandNewCustomerPassword123',
            'password_confirmation' => 'BrandNewCustomerPassword123',
        ])->assertRedirect(route('saas.home'));
        $this->assertFalse($user->fresh()->saas_password_change_required);
        $this->assertTrue(Hash::check('BrandNewCustomerPassword123', $user->fresh()->password));
        $this->assertNull($user->fresh()->email_verified_at);
        $this->get(route('saas.workspace.dashboard', $workspace->public_id))->assertOk();
    }

    public function test_valid_existing_email_verified_customer_can_use_password(): void
    {
        [$user, $workspace] = $this->customer(verified: true);
        $this->post(route('saas.login.submit'), [
            'email' => $user->email,
            'password' => 'ExistingCustomerPassword123',
        ])->assertRedirect(route('saas.home'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('saas.workspace.dashboard', $workspace->public_id))->assertOk();
    }

    public function test_wrong_password_legacy_only_and_suspended_users_remain_denied(): void
    {
        [$user, $workspace] = $this->customer(verified: true);
        $this->post(route('saas.login.submit'), [
            'email' => $user->email, 'password' => 'bad',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $workspace->status = 'suspended';
        $workspace->save();
        $this->post(route('saas.login.submit'), [
            'email' => $user->email, 'password' => 'ExistingCustomerPassword123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $legacy = User::factory()->create(['email_verified_at' => now()]);
        $this->post(route('saas.login.submit'), [
            'email' => $legacy->email, 'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
