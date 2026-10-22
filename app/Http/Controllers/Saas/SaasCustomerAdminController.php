<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Saas\SaasAccountAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

final class SaasCustomerAdminController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($search) > 255, 422);

        $customers = User::query()
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('saas_workspace_memberships')
                    ->whereColumn('saas_workspace_memberships.user_id', 'users.id');
            })
            ->when($search !== '', fn ($query) => $query->where('email', 'like', '%'.$search.'%'))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('balenco-data-pilot.saas-customers', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    public function approve(Request $request, User $customer, SaasAccountAccess $access): RedirectResponse
    {
        $data = $request->validate([
            // Only enter an initial password when needed. Never email it,
            // render it after submit or store it without hashing.
            'password' => ['nullable', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        DB::transaction(function () use ($customer, $data, $access): void {
            $locked = User::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($access->hasAccessibleWorkspace($locked), 422,
                'Customer must belong to an accessible workspace.');

            $changes = ['saas_manually_approved_at' => $locked->saas_manually_approved_at ?? now()];
            $passwordChanged = isset($data['password']) && $data['password'] !== '';
            if ($passwordChanged) {
                $changes['password'] = Hash::make($data['password']);
                $changes['remember_token'] = Str::random(60);
                $changes['saas_password_change_required'] = true;
            }
            $locked->forceFill($changes)->save();

            DB::table('saas_customer_admin_events')->insert([
                'user_id' => $locked->id,
                'actor' => 'authenticated_bdp_admin',
                'event' => $passwordChanged ? 'activated_and_password_rotated' : 'activated',
                'created_at' => now(),
            ]);
        });

        return back()->with('success',
            'Customer activated. If you assigned a temporary password, deliver it privately and require the customer to change it on login.'
        );
    }
}
