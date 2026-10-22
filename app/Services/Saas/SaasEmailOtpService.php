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

        // Throttle by *account* in addition to the route's IP throttle.
        // Registration is the first code send for a new user.
        if (SaasEmailOtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('sent_at', '>', now()->subSeconds(60))
            ->exists()) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = DB::transaction(function () use ($user, $code, $purpose): SaasEmailOtpChallenge {
            // Serialize resend and verify operations for this account.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            SaasEmailOtpChallenge::where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return SaasEmailOtpChallenge::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
            ]);
        });

        try {
            Mail::to($user->email)->send(new SaasEmailOtpMail($code, $purpose));
        } catch (Throwable $e) {
            // An SMTP failure must not turn signup into HTTP 500. Accounts can
            // request a new code when mail delivery has been corrected.
            $challenge->delete();
            Log::warning('Data Pilot OTP email delivery failed', [
                'exception' => get_class($e),
            ]);
            return false;
        }

        $challenge->sent_at = now();
        $challenge->save();
        $request->session()->put('saas_otp_public_id', $challenge->public_id);

        return true;
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
            if (!$user || !DB::table('saas_workspace_memberships')
                ->where('user_id', $user->id)->where('status', 'active')->exists()) {
                return null;
            }
            if (!$user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            return $user;
        });
    }
}
