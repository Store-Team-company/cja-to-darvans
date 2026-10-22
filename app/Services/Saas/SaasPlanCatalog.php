<?php

namespace App\Services\Saas;

final class SaasPlanCatalog
{
    public function all(): array
    {
        return config('saas.plans', []);
    }

    public function plan(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    public function codeForPrice(string $price): ?string
    {
        if ($price === '') {
            return null;
        }

        foreach ($this->all() as $code => $plan) {
            if (($plan['stripe_price_id'] ?? '') !== '' && $plan['stripe_price_id'] === $price) {
                return $code;
            }
        }

        return null;
    }

    public function checkoutReady(string $code): bool
    {
        $plan = $this->plan($code);

        return config('saas.billing_enabled')
            && is_array($plan)
            && str_starts_with((string) ($plan['stripe_price_id'] ?? ''), 'price_')
            && str_starts_with((string) config('saas.stripe_secret'), 'sk_')
            && (string) config('saas.stripe_webhook_secret') !== '';
    }
}
