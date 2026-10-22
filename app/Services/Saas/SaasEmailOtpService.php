<?php

namespace App\Services\Saas;

use App\Mail\SaasEmailOtpMail;
use App\Models\SaasEmailOtpChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SaasEmailOtpService
{
    public function send(User $user, Request $request, string $purpose = 'login'): bool
    {
        if (!in_array($purpose, ['register', 'login'], true)) {
            return false;
        }

        $mailer = (string) config('mail.default', '');
        if (!$this->isDeliveryCapableMailer($mailer)) {
            $this->diagnostic('mail_configuration_invalid', $user, $purpose, $mailer);

            return false;
        }

        // Reserve the send interval while holding the account lock. Marking
        // sent_at here also closes the parallel-resend race on shared hosting.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = DB::transaction(function () use ($user, $code, $purpose): ?SaasEmailOtpChallenge {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (SaasEmailOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('sent_at', '>', now()->subSeconds(60))
                ->exists()) {
                return null;
            }

            // Do not invalidate a still-valid code until the new transport
            // handoff succeeds. This matters during intermittent SMTP outages.
            return SaasEmailOtpChallenge::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'sent_at' => now(),
            ]);
        });

        if (!$challenge) {
            $this->diagnostic('resend_cooldown', $user, $purpose, $mailer);

            return false;
        }

        $this->diagnostic('challenge_created', $user, $purpose, $mailer);

        try {
            // Synchronous Mailable; neither a queue worker nor a scheduled
            // task is required for this existing AUTH-03 flow.
            Mail::to($user->email)->send(new SaasEmailOtpMail($code, $purpose));
        } catch (Throwable $e) {
            // Do not log exception messages: transport errors can contain
            // recipient addresses, authentication URLs or SMTP credentials.
            $challenge->delete();
            $this->diagnostic('transport_failed', $user, $purpose, $mailer, [
                'exception_type' => get_class($e),
            ], true);

            return false;
        }

        DB::transaction(function () use ($user, $challenge): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            SaasEmailOtpChallenge::where('user_id', $user->id)
                ->whereKeyNot($challenge->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
        });

        $request->session()->put('saas_otp_public_id', $challenge->public_id);

        // This means the configured transport returned without throwing.
        // SMTP acceptance is not proof of mailbox arrival or inbox placement.
        $this->diagnostic('transport_accepted_unconfirmed', $user, $purpose, $mailer);

        return true;
    }

    public function reportIneligible(?User $user): void
    {
        Log::info('datapilot.auth.otp', [
            'event' => 'account_not_eligible',
            'account_id' => $user?->getKey(),
            'reason' => $user ? 'no_accessible_workspace' : 'unknown_account',
        ]);
    }

    private function diagnostic(
        string $event,
        User $user,
        string $purpose,
        string $mailer,
        array $extra = [],
        bool $warning = false
    ): void {
        $context = array_merge([
            'event' => $event,
            'account_id' => $user->getKey(),
            'purpose' => $purpose,
            'mailer' => $mailer,
        ], $extra);

        if ($warning || $event === 'mail_configuration_invalid') {
            Log::warning('datapilot.auth.otp', $context);
        } else {
            Log::info('datapilot.auth.otp', $context);
        }
    }

    private function isDeliveryCapableMailer(string $mailer): bool
    {
        if (!filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($mailer === 'smtp') {
            $smtp = (array) config('mail.mailers.smtp', []);
            if (($smtp['transport'] ?? null) !== 'smtp') {
                return false;
            }

            $url = (string) ($smtp['url'] ?? '');
            $host = (string) ($smtp['host'] ?? '');
            $port = $smtp['port'] ?? null;
            if ($url !== '') {
                try {
                    $host = (string) parse_url($url, PHP_URL_HOST);
                    $urlPort = parse_url($url, PHP_URL_PORT);
                    $port = $urlPort ?? $port;
                } catch (Throwable) {
                    return false;
                }
            }

            if ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                || !is_numeric($port) || !ctype_digit((string) $port)
                || (int) $port < 1 || (int) $port > 65535) {
                return false;
            }

            return true;
        }

        if ($mailer === 'failover') {
            $fallbacks = (array) config('mail.mailers.failover.mailers', []);
            // A fallback to log/array silently "succeeds" without delivering
            // an OTP. Reject the whole chain to avoid false authentication
            // success when the real transport fails.
            return $fallbacks !== [] && count($fallbacks) === count(array_filter(
                $fallbacks,
                fn ($name) => is_string($name)
                    && !in_array($name, ['log', 'array', 'failover', 'roundrobin'], true)
                    && $this->isDeliveryCapableMailer($name)
            ));
        }

        return in_array($mailer, ['ses', 'ses-v2', 'mailgun', 'postmark', 'sendmail'], true)
            && config('mail.mailers.'.$mailer.'.transport') === $mailer;
    }

    public function verify(Request $request, string $code): ?User
    {
        $publicId = $request->session()->get('saas_otp_public_id');
        if (!is_string($publicId) || $publicId === '' || !preg_match('/^[a-f0-9-]{36}$/iD', $publicId)) {
            return null;
        }

        return DB::transaction(function () use ($publicId, $code): ?User {
            $otp = SaasEmailOtpChallenge::where('public_id', $publicId)->lockForUpdate()->first();
            if (!$otp || $otp->consumed_at || $otp->expires_at->isPast() || $otp->attempts >= 5) {
                return null;
            }

            $otp->attempts += 1;
            if (!Hash::check($code, $otp->code_hash)) {
                if ($otp->attempts >= 5) {
                    $otp->consumed_at = now();
                }
                $otp->save();

                return null;
            }

            $otp->consumed_at = now();
            $otp->save();

            $user = User::find($otp->user_id);
            if (!$user || !DB::table('saas_workspace_memberships as m')
                ->join('saas_workspaces as w', 'w.id', '=', 'm.workspace_id')
                ->where('m.user_id', $user->id)
                ->where('m.status', 'active')
                ->where('w.status', '!=', 'suspended')
                ->exists()) {
                return null;
            }
            if (!$user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            return $user;
        });
    }
}
