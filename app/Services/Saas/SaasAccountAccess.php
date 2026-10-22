<?php

namespace App\Services\Saas;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaasAccountAccess
{
    public function hasAccessibleWorkspace(User $user): bool
    {
        return DB::table('saas_workspace_memberships as m')
            ->join('saas_workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.user_id', $user->id)
            ->where('m.status', 'active')
            ->where('w.status', '!=', 'suspended')
            ->exists();
    }

    public function isApproved(User $user): bool
    {
        return $user->hasVerifiedEmail() || $user->saas_manually_approved_at !== null;
    }

    public function allows(User $user): bool
    {
        return $this->isApproved($user) && $this->hasAccessibleWorkspace($user);
    }
}
