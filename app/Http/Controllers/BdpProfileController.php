<?php

namespace App\Http\Controllers;

use App\Models\BdpAdminProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class BdpProfileController extends Controller
{
    public function index()
    {
        $profile = $this->profile();

        return view('balenco-data-pilot.profile', compact('profile'));
    }

    public function update(Request $request)
    {
        $profile = $this->profile();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'username' => ['required', 'string', 'max:80', Rule::unique('bdp_admin_profiles', 'username')->ignore($profile->id)],
            'password' => ['nullable', 'confirmed', 'min:8'],
        ], [
            'password.confirmed' => 'Password confirmation does not match.',
            'password.min' => 'Password must be at least 8 characters.',
        ]);

        $profile->name = $validated['name'];
        $profile->email = $validated['email'] ?? null;
        $profile->username = $validated['username'];

        if (!empty($validated['password'])) {
            $profile->password = Hash::make($validated['password']);
        }

        $profile->save();

        $this->syncSettings($profile);

        return back()->with('success', 'Admin profile updated successfully.');
    }

    private function profile(): BdpAdminProfile
    {
        if (!Schema::hasTable('bdp_admin_profiles')) {
            return new BdpAdminProfile([
                'name' => 'Admin',
                'email' => null,
                'username' => 'admin',
            ]);
        }

        return BdpAdminProfile::query()->firstOrCreate(
            ['id' => 1],
            [
                'name' => config('app.name', 'Data Pilot') . ' Admin',
                'email' => null,
                'username' => env('BDP_ADMIN_USERNAME', 'admin'),
                'password' => (string) env('BDP_LOGIN_PASSWORD_HASH', '') ?: (env('BDP_ADMIN_PASSWORD') ? Hash::make(env('BDP_ADMIN_PASSWORD')) : Hash::make(\Illuminate\Support\Str::random(48))),
            ]
        );
    }

    private function syncSettings(BdpAdminProfile $profile): void
    {
        try {
            if (!Schema::hasTable('bdp_settings')) {
                return;
            }

            foreach ([
                'admin_name' => $profile->name,
                'admin_email' => $profile->email,
                'admin_username' => $profile->username,
            ] as $key => $value) {
                DB::table('bdp_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => (string) ($value ?? ''), 'updated_at' => now(), 'created_at' => now()]
                );
            }
        } catch (\Throwable $e) {
            // Keep profile update working even if bdp_settings is unavailable.
        }
    }
}
