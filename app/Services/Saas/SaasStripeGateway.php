<?php

namespace App\Services\Saas;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SaasStripeGateway
{
    private function request(string $method, string $path, array $data = [], ?string $idempotency = null): array
    {
        $secret = (string) config('saas.stripe_secret');
        if (!str_starts_with($secret, 'sk_')) {
            throw new RuntimeException('Stripe billing is not configured.');
        }

        $request = Http::withToken($secret)->asForm()->timeout(15);
        if ($idempotency !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotency]);
        }

        $url = 'https://api.stripe.com/v1/'.$path;
        $response = $method === 'POST' ? $request->post($url, $data) : $request->get($url);
        if (!$response->successful() || !is_array($response->json()) || isset($response->json()['error'])) {
            // Never include Stripe response bodies, which can include sensitive fields.
            throw new RuntimeException('Stripe billing is temporarily unavailable. Please try again.');
        }

        return $response->json();
    }

    public function checkout(array $values, string $key): array
    {
        return $this->request('POST', 'checkout/sessions', $values, $key);
    }

    public function portal(string $customerId, string $returnUrl): array
    {
        return $this->request('POST', 'billing_portal/sessions', [
            'customer' => $customerId, 'return_url' => $returnUrl,
        ]);
    }

    public function subscription(string $subscriptionId): array
    {
        if (!preg_match('/^sub_[A-Za-z0-9_]+$/D', $subscriptionId)) {
            throw new RuntimeException('Invalid Stripe subscription reference.');
        }

        return $this->request('GET', 'subscriptions/'.rawurlencode($subscriptionId));
    }
}
