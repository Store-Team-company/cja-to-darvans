<?php

namespace App\Services\Saas;

use App\Models\SaasWorkspace;
use App\Models\SaasWorkspaceBilling;

final class SaasEntitlements
{
    public function billing(SaasWorkspace $workspace): ?SaasWorkspaceBilling
    {
        return SaasWorkspaceBilling::where('workspace_id', $workspace->id)->first();
    }

    public function active(SaasWorkspace $workspace): bool
    {
        if (!config('saas.enabled') || !config('saas.billing_enabled') || $workspace->status === 'suspended') {
            return false;
        }

        $bill = $this->billing($workspace);
        if (!$bill || !in_array($bill->status, ['active', 'trialing'], true)
            || !$bill->current_period_end || $bill->current_period_end->isPast()) {
            return false;
        }

        $plan = app(SaasPlanCatalog::class)->plan((string) $bill->plan_code);

        return $plan !== null && $bill->stripe_price_id === $plan['stripe_price_id']
            && str_starts_with((string) $bill->stripe_price_id, 'price_');
    }

    public function limit(SaasWorkspace $workspace, string $metric): int
    {
        if (!$this->active($workspace)) {
            return 0;
        }
        $plan = app(SaasPlanCatalog::class)->plan((string) $this->billing($workspace)->plan_code);

        return max(0, (int) ($plan[$metric] ?? 0));
    }

    public function allows(SaasWorkspace $workspace, string $action): bool
    {
        if (!$this->active($workspace)) {
            return false;
        }

        return match ($action) {
            'import' => $workspace->products()->count() < $this->limit($workspace, 'product_limit'),
            'connect_store' => $workspace->stores()->count() < $this->limit($workspace, 'store_limit'),
            'invite_member' => $workspace->members()->wherePivot('status', 'active')->count() < $this->limit($workspace, 'seat_limit'),
            'connect_cj' => true,
            'manage_connection' => true,
            'publish' => true,
            default => false,
        };
    }
}
