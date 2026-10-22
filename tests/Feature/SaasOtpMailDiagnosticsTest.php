<?php

namespace Tests\Feature;

use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SaasOtpMailDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        Mail::fake();
    }

    public function test_password_sign_in_does_not_require_working_smtp(): void
    {
        config()->set('mail.default', 'log');
        $user = User::factory()->create([
            'email_verified_at' => null,
            'password' => Hash::make('CustomerPasswordExample123'),
            'saas_manually_approved_at' => now(),
        ]);
        $workspace = SaasWorkspace::create(['name' => 'Approved pilot']);
        $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        $this->post(route('saas.login.submit'), [
            'email' => $user->email,
            'password' => 'CustomerPasswordExample123',
        ])->assertRedirect(route('saas.home'));
        $this->assertAuthenticatedAs($user);
        Mail::assertNothingSent();
    }

    public function test_customer_cannot_activate_any_account_using_public_routes(): void
    {
        $customer = User::factory()->create(['email_verified_at' => null]);
        $workspace = SaasWorkspace::create(['name' => 'Private']);
        $workspace->members()->attach($customer->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($customer)->post(route('bdp.saas.customers.approve', $customer), [])
            ->assertRedirect(route('bdp.login.form'));
        $this->assertNull($customer->fresh()->saas_manually_approved_at);
        $this->assertDatabaseCount('saas_customer_admin_events', 0);
    }

    public function test_signup_without_password_is_rejected_when_mail_is_down(): void
    {
        config()->set('saas.registration_enabled', true);
        config()->set('mail.default', 'log');
        $this->post(route('saas.register.submit'), [
            'name' => 'Example', 'email' => 'new@example.test', 'workspace_name' => 'Example shop',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingSent();
    }
}
