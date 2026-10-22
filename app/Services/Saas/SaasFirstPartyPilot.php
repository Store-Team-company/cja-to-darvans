<?php

namespace App\Services\Saas;

use App\Models\SaasBotbleStore;
use App\Models\SaasWorkspace;

/**
 * Narrow, server-configured permission for the owner's own STAGING Botble store.
 * Does not grant a subscription, CJ API access, publishing, or other SaaS rights.
 */
final class SaasFirstPartyPilot
{
    private const ALLOWED_STORE_URL = 'https://sandbox.daystart.pk';

    private function allowedWorkspace(SaasWorkspace $workspace): bool
    {
        $configuredId = (string) config('saas.botble_pilot.workspace_id', '');
        $configuredUrl = (string) config('saas.botble_pilot.store_url', '');

        return (bool) config('saas.enabled', false)
            && (bool) config('saas.botble_pilot.enabled', false)
            && $workspace->status === 'active'
            && $configuredId !== ''
            && hash_equals($configuredId, (string) $workspace->public_id)
            && $configuredUrl === self::ALLOWED_STORE_URL;
    }

    public function allowsStoreRegistration(SaasWorkspace $workspace, string $url): bool
    {
        return $this->allowedWorkspace($workspace)
            && $url === self::ALLOWED_STORE_URL
            && !$workspace->stores()->where('base_url', self::ALLOWED_STORE_URL)->exists();
    }

    public function allowsPairing(SaasWorkspace $workspace, SaasBotbleStore $store): bool
    {
        $configuredStore = (string) config('saas.botble_pilot.store_id', '');

        return $this->allowedWorkspace($workspace)
            && $configuredStore !== ''
            && hash_equals($configuredStore, (string) $store->public_id)
            && $store->workspace_id === $workspace->id
            && $store->base_url === self::ALLOWED_STORE_URL;
    }
}
