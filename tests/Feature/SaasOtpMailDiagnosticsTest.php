<?php

namespace Tests\Feature;

use App\Mail\SaasEmailOtpMail;
use App\Models\SaasEmailOtpChallenge;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SaasOtpMailDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'sender@test-mailer.invalid');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => 'smtp.example.org',
            'port' => 587,
            'encryption' => 'tls',
        ]);
        Mail::fake();
    }

    private function customer(bool $suspended = false): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $workspace = SaasWorkspace::create(['name' => 'Test customer workspace']);
        if ($suspended) {
            $workspace->status = 'suspended';
            $workspace->save();
        }
        $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        return $user;
    }

    private function requestCode(User $user)
    {
        return $this->post(route('saas.otp.send'), ['email' => $user->email]);
    }

    public function test_invalid_smtp_port_fails_closed_without_public_account_disclosure(): void
    {
        $user = $this->customer();
        Log::spy();
        config()->set('mail.mailers.smtp.port', '587465');

        $this->requestCode($user)
            ->assertRedirect(route('saas.otp.verify.form'))
            ->assertSessionHas('status', 'If this email belongs to a Data Pilot workspace, we have sent a one-time code. Check your inbox and spam folder.');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('saas_email_otp_challenges', 0);
        $this->assertGuest();

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use ($user) {
            return $message === 'datapilot.auth.otp'
                && ($context['event'] ?? '') === 'mail_configuration_invalid'
                && ($context['account_id'] ?? null) === $user->id
                && ($context['mailer'] ?? '') === 'smtp'
                && !isset($context['email'], $context['code']);
        });
    }

    public function test_non_delivery_mailers_and_log_fallback_cannot_fake_success(): void
    {
        $user = $this->customer();
        foreach (['log', 'array', 'failover'] as $mailer) {
            config()->set('mail.default', $mailer);
            config()->set('mail.mailers.failover.mailers', ['smtp', 'log']);
            $this->requestCode($user)->assertRedirect(route('saas.otp.verify.form'));
            $this->assertDatabaseCount('saas_email_otp_challenges', 0);
        }
        Mail::assertNothingSent();
        $this->assertGuest();
    }

    public function test_failover_with_misconfigured_smtp_is_not_treated_as_deliverable(): void
    {
        $user = $this->customer();
        config()->set('mail.default', 'failover');
        config()->set('mail.mailers.failover.mailers', ['smtp']);
        config()->set('mail.mailers.smtp.port', 587465);

        $this->requestCode($user)
            ->assertRedirect(route('saas.otp.verify.form'))
            ->assertSessionHas('status');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('saas_email_otp_challenges', 0);
        $this->assertGuest();
    }

    public function test_successful_otp_authentication_regenerates_session(): void
    {
        $user = $this->customer();
        $this->requestCode($user)->assertRedirect(route('saas.otp.verify.form'));
        $code = '';
        Mail::assertSent(SaasEmailOtpMail::class, function (SaasEmailOtpMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $code);
        $sessionBefore = session()->getId();

        $this->post(route('saas.otp.verify'), ['code' => $code])
            ->assertRedirect(route('saas.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionBefore, session()->getId());
    }

    public function test_smtp_exception_is_safely_logged_and_does_not_500_or_create_session(): void
    {
        $user = $this->customer();
        Log::spy();
        Mail::swap(new class {
            public function to(): never
            {
                throw new \RuntimeException('Sensitive simulated SMTP password email data');
            }
        });

        $this->requestCode($user)
            ->assertRedirect(route('saas.otp.verify.form'))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('saas_email_otp_challenges', 0);
        $this->assertGuest();
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use ($user) {
            $encoded = json_encode($context);
            return $message === 'datapilot.auth.otp'
                && ($context['event'] ?? '') === 'transport_failed'
                && ($context['exception_type'] ?? '') === \RuntimeException::class
                && ($context['account_id'] ?? null) === $user->id
                && !str_contains($encoded, 'Sensitive')
                && !str_contains($encoded, $user->email);
        });
    }

    public function test_failed_resend_preserves_previously_valid_code_and_session(): void
    {
        $user = $this->customer();
        $this->requestCode($user)->assertRedirect();
        $oldCode = '';
        Mail::assertSent(SaasEmailOtpMail::class, function (SaasEmailOtpMail $mail) use (&$oldCode): bool {
            $oldCode = $mail->code;

            return true;
        });
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $oldCode);
        $oldChallenge = SaasEmailOtpChallenge::firstOrFail();
        $oldChallenge->update(['sent_at' => now()->subSeconds(61)]);

        Mail::swap(new class {
            public function to(): never
            {
                throw new \RuntimeException('Simulated SMTP failure');
            }
        });
        $this->requestCode($user)->assertRedirect(route('saas.otp.verify.form'));
        $this->assertDatabaseCount('saas_email_otp_challenges', 1);
        $this->assertNull($oldChallenge->fresh()->consumed_at);
        $this->post(route('saas.otp.verify'), ['code' => $oldCode])
            ->assertRedirect(route('saas.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_suspended_or_unknown_account_cannot_receive_otp_or_log_in(): void
    {
        $suspended = $this->customer(suspended: true);
        Log::spy();
        $this->requestCode($suspended)->assertRedirect(route('saas.otp.verify.form'));
        $this->post(route('saas.otp.send'), ['email' => 'unknown@outside.test'])
            ->assertRedirect(route('saas.otp.verify.form'));
        Mail::assertNothingSent();
        $this->assertDatabaseCount('saas_email_otp_challenges', 0);
        $this->post(route('saas.otp.verify'), ['code' => '123456'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) =>
            $message === 'datapilot.auth.otp'
            && ($context['event'] ?? '') === 'account_not_eligible'
            && ($context['account_id'] ?? null) === $suspended->id
        )->once();
    }

    public function test_accepted_transport_is_not_claimed_as_confirmed_delivery(): void
    {
        $user = $this->customer();
        Log::spy();
        $this->requestCode($user)->assertRedirect(route('saas.otp.verify.form'));
        Mail::assertSent(SaasEmailOtpMail::class, 1);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) =>
            $message === 'datapilot.auth.otp'
            && ($context['event'] ?? '') === 'transport_accepted_unconfirmed'
            && ($context['account_id'] ?? null) === $user->id
        )->once();
    }
}
