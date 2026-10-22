<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Models\User;
use App\Services\Saas\SaasEmailOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class SaasAuthController extends Controller
{
    public function showRegister(): View
    {
        abort_unless(config('saas.registration_enabled', false), 404);

        return view('saas.auth.register');
    }

    public function register(Request $request, SaasEmailOtpService $otp): RedirectResponse
    {
        abort_unless(config('saas.registration_enabled', false), 404);

        // Unique-email validation must use the same normalized value that
        // is saved, including on case-sensitive SQLite/test databases.
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'workspace_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            // Password is optional for customers who choose email OTP.
            'password' => ['nullable', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                // Generate an unguessable unknown password when signup uses OTP only.
                'password' => Hash::make($data['password'] ?? Str::random(64)),
            ]);

            $workspace = SaasWorkspace::create(['name' => trim($data['workspace_name'])]);
            $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

            return $user;
        });

        // Do not sign in or mark the new account verified until OTP succeeds.
        // Registered event sends a synchronous verification email and can
        // throw HTTP 500 when the SMTP transport is down on shared hosting.
        $request->session()->forget('saas_otp_public_id');
        $request->session()->put('saas_otp_email', $user->email);
        $sent = $otp->send($user, $request, 'register');

        return redirect()->route('saas.otp.verify.form')->with(
            $sent ? 'status' : 'error',
            $sent ? 'A six-digit verification code has been sent to your email.'
                : 'Workspace created, but the verification email could not be sent. Please retry after email delivery is configured.'
        );
    }

    public function showOtp(): View
    {
        return view('saas.auth.otp', [
            'email' => session('saas_otp_email', ''),
        ]);
    }

    public function sendOtp(Request $request, SaasEmailOtpService $otp): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        // Keep a still-valid challenge when the same user resends within
        // cooldown; only discard the old session on an email switch.
        if ($request->session()->get('saas_otp_email') !== $email) {
            $request->session()->forget('saas_otp_public_id');
        }
        $request->session()->put('saas_otp_email', $email);

        $user = User::where('email', $email)->first();
        $eligible = $user && DB::table('saas_workspace_memberships as m')
            ->join('saas_workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.user_id', $user->id)
            ->where('m.status', 'active')
            ->where('w.status', '!=', 'suspended')
            ->exists();
        if ($eligible) {
            $otp->send($user, $request, 'login');
        } else {
            // Internal-only, non-secret diagnostics. Never distinguish these
            // cases in the public response or include the requested email.
            $otp->reportIneligible($user);
        }

        // Identical response whether the email exists or not.
        return redirect()->route('saas.otp.verify.form')
            ->with('status', 'If this email belongs to a Data Pilot workspace, we have sent a one-time code. Check your inbox and spam folder.');
    }

    public function verifyOtp(Request $request, SaasEmailOtpService $otp): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'regex:/^[0-9]{6}$/D'],
        ]);

        $user = $otp->verify($request, $data['code']);
        if (! $user) {
            return back()->withErrors([
                'code' => 'Incorrect or expired code. You have up to five attempts. Request a new code if necessary.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['saas_otp_public_id', 'saas_otp_email']);

        return redirect()->intended(route('saas.home'));
    }

    public function forgotForm(): View
    {
        return view('saas.auth.forgot-password');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'email']])['email'];
        Password::sendResetLink(['email' => mb_strtolower(trim($email))]);

        // Do not reveal whether a particular email belongs to an account.
        return back()->with('status', 'If this email exists, we sent password reset instructions.');
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('saas.auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
            'password_confirmation' => ['required', 'same:password'],
        ]);
        $status = Password::reset([
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            'password_confirmation' => $data['password_confirmation'],
            'token' => $data['token'],
        ], function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
            event(new \Illuminate\Auth\Events\PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('saas.login')->with('status', 'Password changed. You can sign in now.')
            : back()->withErrors(['email' => 'The reset link is invalid or has expired.']);
    }

    public function showLogin(): View
    {
        return view('saas.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => mb_strtolower(trim($data['email'])), 'password' => $data['password']])) {
            return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('saas.home'));
    }

    public function home(Request $request): RedirectResponse
    {
        $publicId = DB::table('saas_workspace_memberships as m')
            ->join('saas_workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.user_id', $request->user()->id)
            ->where('m.status', 'active')
            ->where('w.status', '!=', 'suspended')
            ->orderBy('w.id')
            ->value('w.public_id');

        abort_unless($publicId, 403, 'You do not belong to an active workspace.');

        return redirect()->route('saas.workspace.dashboard', ['workspace' => $publicId]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->forget(['saas_otp_public_id', 'saas_otp_email']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('saas.login');
    }
}
