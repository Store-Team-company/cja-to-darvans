<?php

namespace App\Services\Saas;

use App\Models\SaasWorkspace;
use App\Models\SaasWorkspaceBilling;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaasStripeWebhookProcessor
{
    public function __construct(
        private readonly SaasStripeGateway $stripe,
        private readonly SaasPlanCatalog $plans
    ) {
    }

    public function verifiedEvent(string $body, string $signature): array
    {
        $secret = (string) config('saas.stripe_webhook_secret');
        if ($secret === '' || strlen($body) > 1048576) {
            throw new RuntimeException('Stripe webhook unavailable.');
        }

        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $signature) as $part) {
            $pieces = explode('=', trim($part), 2);
            if (count($pieces) !== 2) {
                continue;
            }
            if ($pieces[0] === 't' && ctype_digit($pieces[1])) {
                $timestamp = (int) $pieces[1];
            } elseif ($pieces[0] === 'v1' && preg_match('/^[a-f0-9]{64}$/D', $pieces[1])) {
                $candidates[] = $pieces[1];
            }
        }

        if (!$timestamp || abs(time() - $timestamp) > 300 || !$candidates) {
            throw new RuntimeException('Invalid Stripe webhook signature.');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        $valid = false;
        foreach ($candidates as $candidate) {
            $valid = hash_equals($expected, $candidate) || $valid;
        }
        if (!$valid) {
            throw new RuntimeException('Invalid Stripe webhook signature.');
        }

        $event = json_decode($body, true);
        if (!is_array($event) || !is_string($event['id'] ?? null)
            || !str_starts_with($event['id'], 'evt_')
            || !is_string($event['type'] ?? null)
            || !is_array($event['data']['object'] ?? null)
            || !is_numeric($event['created'] ?? null)) {
            throw new RuntimeException('Invalid Stripe event payload.');
        }

        return $event;
    }

    public function process(array $event): void
    {
        $type = $event['type'];
        $object = $event['data']['object'];
        if (!in_array($type, [
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ], true)) {
            // Do not grant entitlements for arbitrary event types.
            return;
        }

        $subscription = null;
        $workspaceKey = null;
        if ($type === 'checkout.session.completed') {
            if (($object['mode'] ?? '') !== 'subscription'
                || ($object['status'] ?? '') !== 'complete'
                || !is_string($object['subscription'] ?? null)) {
                return;
            }
            $workspaceKey = (string) ($object['client_reference_id'] ?? '');
            if ($workspaceKey === '' || ($object['metadata']['workspace_id'] ?? '') !== $workspaceKey) {
                return;
            }
            $subscription = $this->stripe->subscription($object['subscription']);
            if (($subscription['customer'] ?? '') !== ($object['customer'] ?? null)) {
                return;
            }
        } else {
            if (!is_string($object['id'] ?? null)) {
                return;
            }
            // Read the current Stripe object to avoid status regressions when
            // signed subscription.update events arrive in the wrong order.
            $subscription = $type === 'customer.subscription.deleted'
                ? $object
                : $this->stripe->subscription($object['id']);
            $workspaceKey = (string) ($subscription['metadata']['workspace_id'] ?? '');
        }

        if (!is_array($subscription)
            || !preg_match('/^sub_[A-Za-z0-9_]+$/D', (string) ($subscription['id'] ?? ''))
            || !preg_match('/^cus_[A-Za-z0-9_]+$/D', (string) ($subscription['customer'] ?? ''))
            || ($subscription['metadata']['workspace_id'] ?? '') !== $workspaceKey
            || !is_string($subscription['status'] ?? null)) {
            return;
        }

        $workspace = SaasWorkspace::where('public_id', $workspaceKey)->first();
        if (!$workspace) {
            return;
        }
        $price = (string) ($subscription['items']['data'][0]['price']['id'] ?? '');
        $planCode = $this->plans->codeForPrice($price);
        $periodEnd = $subscription['current_period_end'] ?? null;
        if (!is_numeric($periodEnd) || (int) $periodEnd < 0) {
            $periodEnd = null;
        }

        DB::transaction(function () use ($event, $workspace, $subscription, $price, $planCode, $periodEnd): void {
            // A unique event row and the subscription update commit atomically.
            // Retries will not process the same signed Stripe event twice.
            $inserted = DB::table('saas_stripe_events')->insertOrIgnore([
                'stripe_event_id' => $event['id'],
                'event_type' => $event['type'],
                'processed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (!$inserted) {
                return;
            }

            $bill = SaasWorkspaceBilling::where('workspace_id', $workspace->id)->lockForUpdate()->first();
            if (!$bill) {
                $bill = new SaasWorkspaceBilling();
                $bill->workspace_id = $workspace->id;
            }

            // Reject an attempt to reassign a paid customer to another workspace,
            // or to reassign an existing workspace to a different Stripe account.
            if (($bill->stripe_customer_id && $bill->stripe_customer_id !== $subscription['customer'])
                || ($bill->stripe_subscription_id && $bill->stripe_subscription_id !== $subscription['id'])
                || SaasWorkspaceBilling::where('stripe_customer_id', $subscription['customer'])
                    ->where('workspace_id', '!=', $workspace->id)->exists()
                || SaasWorkspaceBilling::where('stripe_subscription_id', $subscription['id'])
                    ->where('workspace_id', '!=', $workspace->id)->exists()) {
                throw new RuntimeException('Stripe workspace ownership conflict.');
            }

            $created = (int) ($event['created'] ?? 0);
            if ($created < $bill->last_event_created) {
                return;
            }

            $bill->stripe_customer_id = $subscription['customer'];
            $bill->stripe_subscription_id = $subscription['id'];
            $bill->stripe_price_id = $price;
            $bill->plan_code = $planCode;
            $bill->status = $event['type'] === 'customer.subscription.deleted'
                ? 'canceled'
                : $subscription['status'];
            $bill->current_period_end = $periodEnd ? \Illuminate\Support\Carbon::createFromTimestampUTC((int) $periodEnd) : null;
            $bill->cancel_at_period_end = (bool) ($subscription['cancel_at_period_end'] ?? false);
            $bill->last_event_created = max($bill->last_event_created, $created);
            $bill->save();
        });
    }
}
