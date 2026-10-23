<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasEntitlements;
use App\Services\Saas\SaasPlanCatalog;
use App\Services\Saas\SaasStripeGateway;
use App\Services\Saas\WorkspacePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class SaasBillingController extends Controller
{
    public function show(Request $request, SaasWorkspace $workspace, SaasPlanCatalog $plans, SaasEntitlements $entitlements): View
    {
        return view('saas.billing', [
            'workspace' => $workspace,
            'role' => app(WorkspacePermissions::class)->roleFor($request->user(), $workspace),
            'plans' => $plans->all(),
            'planCatalog' => $plans,
            'bill' => $entitlements->billing($workspace),
            'activeSubscription' => $entitlements->active($workspace),
            'paidSubscription' => $entitlements->paidActive($workspace),
            'adminGrant' => $entitlements->adminGrant($workspace),
        ]);
    }

    public function checkout(Request $request, SaasWorkspace $workspace, SaasPlanCatalog $plans, SaasStripeGateway $stripe, SaasEntitlements $entitlements): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_billing');
        $data = $request->validate(['plan' => ['required', 'string', 'in:starter,growth,agency']]);
        if (!$plans->checkoutReady($data['plan'])) {
            return back()->with('error', 'Subscription checkout is not configured yet.');
        }

        if ($entitlements->paidActive($workspace)) {
            return redirect()->route('saas.workspace.billing', $workspace->public_id)
                ->with('error', 'Use Manage subscription to change your existing plan.');
        }

        $plan = $plans->plan($data['plan']);

        try {
            // Lock workspace row (not just billing row) to serialize the very
            // first checkout before any subscription record exists.
            $url = \Illuminate\Support\Facades\DB::transaction(function () use (
                $workspace, $request, $entitlements, $stripe, $plan
            ): string {
                SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();

                $bill = $workspace->billing()->lockForUpdate()->first();
                if ($bill && $bill->status !== 'incomplete'
                    && in_array($bill->status, ['active', 'trialing'], true)) {
                    throw new RuntimeException('An active subscription already exists. Manage it through the billing portal.');
                }

                if ($bill && $bill->pending_checkout_url
                    && $bill->pending_checkout_expires_at
                    && $bill->pending_checkout_expires_at->isFuture()
                    && $this->stripeRedirect($bill->pending_checkout_url, 'checkout.stripe.com')) {
                    return $bill->pending_checkout_url;
                }

                $args = [
                    'mode' => 'subscription',
                    'client_reference_id' => $workspace->public_id,
                    'line_items' => [['price' => $plan['stripe_price_id'], 'quantity' => 1]],
                    'metadata' => ['workspace_id' => $workspace->public_id],
                    'subscription_data' => ['metadata' => ['workspace_id' => $workspace->public_id]],
                    'success_url' => route('saas.workspace.billing', $workspace->public_id).'?checkout=success',
                    'cancel_url' => route('saas.workspace.billing', $workspace->public_id).'?checkout=cancel',
                ];
                if ($bill && $bill->stripe_customer_id) {
                    $args['customer'] = $bill->stripe_customer_id;
                } else {
                    $args['customer_email'] = $request->user()->email;
                }
                $session = $stripe->checkout($args, 'saas-checkout-'.$workspace->id.'-'.Str::uuid());
                $url = (string) ($session['url'] ?? '');
                $sessionId = (string) ($session['id'] ?? '');
                if (!$this->stripeRedirect($url, 'checkout.stripe.com')
                    || !preg_match('/^cs_(test_|live_)[A-Za-z0-9_]+$/D', $sessionId)) {
                    throw new RuntimeException('Stripe did not return a valid checkout session.');
                }

                $bill ??= new \App\Models\SaasWorkspaceBilling();
                $bill->workspace_id = $workspace->id;
                $bill->pending_checkout_id = $sessionId;
                $bill->pending_checkout_url = $url;
                $expiry = is_numeric($session['expires_at'] ?? null)
                    ? (int) $session['expires_at']
                    : now()->addHours(23)->timestamp;
                $bill->pending_checkout_expires_at = \Illuminate\Support\Carbon::createFromTimestampUTC($expiry);
                $bill->save();

                return $url;
            });
        } catch (RuntimeException) {
            return back()->with('error', 'Unable to start checkout, or an existing subscription needs attention. Try Manage subscription.');
        }

        // Returning from success_url is not proof of payment. Only a signed
        // Stripe webhook can activate the workspace subscription.
        return redirect()->away($url);
    }

    public function abandonCheckout(Request $request, SaasWorkspace $workspace, SaasStripeGateway $stripe): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_billing');
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($workspace, $stripe): void {
                SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                $bill = $workspace->billing()->lockForUpdate()->first();
                if (!$bill || !$bill->pending_checkout_id) {
                    return;
                }
                $result = $stripe->expireCheckout($bill->pending_checkout_id);
                if (($result['status'] ?? '') !== 'expired' || ($result['id'] ?? '') !== $bill->pending_checkout_id) {
                    throw new RuntimeException('Unable to expire the open checkout.');
                }
                $bill->pending_checkout_id = null;
                $bill->pending_checkout_url = null;
                $bill->pending_checkout_expires_at = null;
                $bill->save();
            });
        } catch (RuntimeException) {
            return back()->with('error', 'Could not cancel the pending Stripe checkout. Check whether it has already completed.');
        }

        return back()->with('status', 'Pending checkout canceled. You can select a different plan.');
    }

    public function portal(Request $request, SaasWorkspace $workspace, SaasStripeGateway $stripe, SaasEntitlements $entitlements): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_billing');
        $bill = $entitlements->billing($workspace);
        if (!config('saas.billing_enabled') || !$bill || !$bill->stripe_customer_id) {
            return back()->with('error', 'No Stripe subscription is linked to this workspace yet.');
        }

        try {
            $session = $stripe->portal(
                $bill->stripe_customer_id,
                route('saas.workspace.billing', $workspace->public_id)
            );
        } catch (RuntimeException) {
            return back()->with('error', 'Billing portal is temporarily unavailable.');
        }

        $url = (string) ($session['url'] ?? '');
        if (!$this->stripeRedirect($url, 'billing.stripe.com')) {
            return back()->with('error', 'Stripe did not return a valid billing portal link.');
        }

        return redirect()->away($url);
    }

    private function stripeRedirect(string $url, string $host): bool
    {
        $parsed = parse_url($url);

        return is_array($parsed) && ($parsed['scheme'] ?? '') === 'https'
            && ($parsed['host'] ?? '') === $host
            && !isset($parsed['user']) && !isset($parsed['pass']);
    }
}
