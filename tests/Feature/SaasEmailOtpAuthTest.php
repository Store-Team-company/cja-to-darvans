<?php

namespace Tests\Feature;

use App\Mail\SaasEmailOtpMail;
use App\Models\SaasEmailOtpChallenge;
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

    private function registeredUser(string $email = 'example@merchant.test', bool $verified = false): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => $verified ? now() : null,
        ]);
        $workspace = SaasWorkspace::create(['name' => 'Example merchant']);
        $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        return $user;
    }

    private function latestCode(): string
    {
        $value = '';
        Mail::assertSent(SaasEmailOtpMail::class, function (SaasEmailOtpMail $mail) use (&$value): bool {
            $value = $mail->code;
            return true;
        });
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $value);

        return $value;
    }

    public function test_signup_without_password_requires_valid_one_time_code_before_auth(): void
    {
        $this->post(route('saas.register.submit'), [
            'name' => 'New Customer',
            'workspace_name' => 'OTP-Only Shop',
            'email' => 'NEW@EXAMPLE.TEST',
        ])->assertRedirect(route('saas.otp.verify.form'));

        $this->assertGuest();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $workspace = SaasWorkspace::firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('nothing', $user->password) === false);
        $this->assertDatabaseHas('saas_workspace_memberships', [
            'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner',
        ]);

        $code = $this->latestCode();
        $challenge = SaasEmailOtpChallenge::firstOrFail();
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertTrue(Hash::check($code, $challenge->code_hash));
        $this->get(route('saas.workspace.dashboard', $workspace->public_id))
            ->assertRedirect(route('saas.login'));

        $this->post(route('saas.otp.verify'), ['code' => $code])
            // Honor the original intended workspace URL after authentication.
            ->assertRedirect(route('saas.workspace.dashboard', $workspace->public_id));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($challenge->fresh()->consumed_at);
    }

    public function test_existing_customer_can_request_otp_without_using_password(): void
    {
        $user = $this->registeredUser(verified: true);
        $this->get(route('saas.login'))->assertOk()
            ->assertSee('Continue with email OTP')
            ->assertSee('Sign in using a password');

        $this->post(route('saas.otp.send'), ['email' => strtoupper($user->email)])
            ->assertRedirect(route('saas.otp.verify.form'));

        $code = $this->latestCode();
        $this->post(route('saas.otp.verify'), ['code' => $code])
            ->assertRedirect(route('saas.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_code_can_only_be_used_once_after_logout(): void
    {
        $user = $this->registeredUser();
        $this->post(route('saas.otp.send'), ['email' => $user->email]);
        $code = $this->latestCode();
        $this->post(route('saas.otp.verify'), ['code' => $code])
            ->assertRedirect(route('saas.home'));
        $this->post(route('saas.logout'))->assertRedirect(route('saas.login'));

        $this->post(route('saas.otp.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_code_expires_and_locks_after_five_wrong_attempts(): void
    {
        $user = $this->registeredUser();
        $this->post(route('saas.otp.send'), ['email' => $user->email]);
        $challenge = SaasEmailOtpChallenge::firstOrFail();
        $good = $this->latestCode();
        $bad = $good === '000000' ? '999999' : '000000';

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        for ($n = 0; $n < 5; $n++) {
            $this->post(route('saas.otp.verify'), ['code' => $bad])
                ->assertSessionHasErrors('code');
        }
        $this->assertSame(5, $challenge->fresh()->attempts);
        $this->assertNotNull($challenge->fresh()->consumed_at);
        $this->post(route('saas.otp.verify'), ['code' => $good])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_expired_code_cannot_authenticate_customer(): void
    {
        $user = $this->registeredUser();
        $this->post(route('saas.otp.send'), ['email' => $user->email]);
        $good = $this->latestCode();
        SaasEmailOtpChallenge::query()->update(['expires_at' => now()->subMinute()]);

        $this->post(route('saas.otp.verify'), ['code' => $good])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_resend_has_account_level_cooldown_and_invalidates_old_code(): void
    {
        $user = $this->registeredUser();
        $this->post(route('saas.otp.send'), ['email' => $user->email]);
        $old = SaasEmailOtpChallenge::firstOrFail();

        $this->post(route('saas.otp.send'), ['email' => $user->email])
            ->assertRedirect(route('saas.otp.verify.form'));
        $this->assertDatabaseCount('saas_email_otp_challenges', 1);

        $old->update(['sent_at' => now()->subMinutes(2)]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->post(route('saas.otp.send'), ['email' => $user->email]);
        $this->assertDatabaseCount('saas_email_otp_challenges', 2);
        $this->assertNotNull($old->fresh()->consumed_at);
        Mail::assertSent(SaasEmailOtpMail::class, 2);
    }

    public function test_unknown_email_has_same_response_and_does_not_create_session(): void
    {
        $this->post(route('saas.otp.send'), ['email' => 'unknown@example.test'])
            ->assertRedirect(route('saas.otp.verify.form'))
            ->assertSessionHas('status');
        Mail::assertNothingSent();
        $this->post(route('saas.otp.verify'), ['code' => '123456'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_smtp_failure_is_recoverable_and_does_not_log_customer_in(): void
    {
        Mail::swap(new class {
            public function to(): never
            {
                throw new \RuntimeException('SMTP unavailable');
            }
        });

        $this->post(route('saas.register.submit'), [
            'name' => 'New Customer', 'workspace_name' => 'Email Down',
            'email' => 'mail-down@example.test',
        ])->assertRedirect(route('saas.otp.verify.form'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['email' => 'mail-down@example.test']);
        $this->assertDatabaseCount('saas_email_otp_challenges', 0);
        $this->assertGuest();
    }

    public function test_otp_login_does_not_grant_a_legacy_unscoped_user_customer_access(): void
    {
        $legacy = User::factory()->create(['email' => 'legacy@example.test']);
        $this->post(route('saas.otp.send'), ['email' => $legacy->email])
            ->assertSessionHas('status');
        Mail::assertNothingSent();
        $this->assertGuest();
    }
}
