<?php

namespace App\Services\Saas;

use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class WorkspacePermissions
{
    private const ABILITIES = [
        'owner' => ['view', 'import', 'edit_draft', 'publish', 'manage_connections', 'manage_team', 'manage_billing'],
        'manager' => ['view', 'import', 'edit_draft', 'publish'],
        'importer' => ['view', 'import', 'edit_draft'],
        'viewer' => ['view'],
    ];

    public function roleFor(User $user, SaasWorkspace $workspace): ?string
    {
        return DB::table('saas_workspace_memberships')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');
    }

    public function allows(User $user, SaasWorkspace $workspace, string $ability): bool
    {
        $role = $this->roleFor($user, $workspace);

        return $role !== null && in_array($ability, self::ABILITIES[$role] ?? [], true);
    }

    public function authorize(User $user, SaasWorkspace $workspace, string $ability): void
    {
        abort_unless($this->allows($user, $workspace, $ability), 403);
    }
}
