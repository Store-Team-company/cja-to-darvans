<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Models\User;
use App\Services\Saas\SaasAccountAccess;
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

    public function register(Request $request): RedirectResponse
    {
        abort_unless(config('saas.registration_enabled', false), 404);

        // Unique-email validation must use the same normalized value that
        // is saved, including on case-sensitive SQLite/test databases.
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'workspace_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => Hash::make($data['password']),
            ]);

            $workspace = SaasWorkspace::create(['name' => trim($data['workspace_name'])]);
            $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

            return $user;
        });

        // Account access must be approved by the platform administrator.
        // Do not send OTP or verification emails, and do not log in.
        return redirect()->route('saas.login')->with('status',
            'Your account has been created. An administrator must activate it before you can sign in.'
        );
    }

    public function forgotForm(): View
    {
        return view('saas.auth.forgot-password');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'email']])['email'];
        try {
            Password::sendResetLink(['email' => mb_strtolower(trim($email))]);
        } catch (\Throwable $e) {
            // Keep the public response generic if SMTP is temporarily unavailable.
            \Illuminate\Support\Facades\Log::warning('datapilot.auth.password_reset_transport_failed', [
                'exception_type' => get_class($e),
            ]);
        }

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

    public function login(Request $request, SaasAccountAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => mb_strtolower(trim($data['email'])), 'password' => $data['password']])) {
            return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
        }

        $user = $request->user();
        if (! $user || ! $access->allows($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'This customer account is not active. Contact your Data Pilot administrator.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        if ($user->saas_password_change_required) {
            return redirect()->route('saas.password.change.form');
        }

        return redirect()->intended(route('saas.home'));
    }

    public function changePasswordForm(Request $request, SaasAccountAccess $access): View
    {
        abort_unless($access->allows($request->user()), 403);

        return view('saas.auth.change-password');
    }

    public function changePassword(Request $request, SaasAccountAccess $access): RedirectResponse
    {
        abort_unless($access->allows($request->user()), 403);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Choose a new password different from your temporary password.']);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
            'saas_password_change_required' => false,
        ])->save();

        $request->session()->regenerate();

        return redirect()->route('saas.home')->with('status', 'Your password has been changed.');
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
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('saas.login');
    }
}
