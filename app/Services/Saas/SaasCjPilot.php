<?php

namespace App\Services\Saas;

use App\Models\SaasWorkspace;

/** Explicit opt-in for ONE internal workspace; not a subscription grant. */
final class SaasCjPilot
{
    public function allows(SaasWorkspace $workspace): bool
    {
        $expected = (string) config('saas.cj_pilot.workspace_id', '');

        return config('saas.enabled') === true
            && config('saas.cj_pilot.enabled') === true
            && $workspace->status === 'active'
            && $expected !== ''
            && hash_equals($expected, (string) $workspace->public_id);
    }
}
