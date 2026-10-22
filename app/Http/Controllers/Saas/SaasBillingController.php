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
        ]);
    }

    public function checkout(Request $request, SaasWorkspace $workspace, SaasPlanCatalog $plans, SaasStripeGateway $stripe, SaasEntitlements $entitlements): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_billing');
        $data = $request->validate(['plan' => ['required', 'string', 'in:starter,growth,agency']]);
        if (!$plans->checkoutReady($data['plan'])) {
            return back()->with('error', 'Subscription checkout is not configured yet.');
        }

        if ($entitlements->active($workspace)) {
            return redirect()->route('saas.workspace.billing', $workspace->public_id)
                ->with('error', 'Use Manage subscription to change your existing plan.');
        }

        $plan = $plans->plan($data['plan']);
        $current = $entitlements->billing($workspace);
        $args = [
            'mode' => 'subscription',
            'client_reference_id' => $workspace->public_id,
            'line_items' => [['price' => $plan['stripe_price_id'], 'quantity' => 1]],
            'metadata' => ['workspace_id' => $workspace->public_id],
            'subscription_data' => ['metadata' => ['workspace_id' => $workspace->public_id]],
            'success_url' => route('saas.workspace.billing', $workspace->public_id).'?checkout=success',
            'cancel_url' => route('saas.workspace.billing', $workspace->public_id).'?checkout=cancel',
        ];
        if ($current && $current->stripe_customer_id) {
            $args['customer'] = $current->stripe_customer_id;
        } else {
            $args['customer_email'] = $request->user()->email;
        }

        try {
            $session = $stripe->checkout($args, 'saas-checkout-'.$workspace->id.'-'.Str::uuid());
        } catch (RuntimeException) {
            return back()->with('error', 'Unable to start Stripe checkout. Please try again.');
        }

        $url = (string) ($session['url'] ?? '');
        if (!$this->stripeRedirect($url, 'checkout.stripe.com')) {
            return back()->with('error', 'Stripe did not return a valid checkout link.');
        }

        // Returning from success_url is not proof of payment. Only a signed
        // Stripe webhook can activate the workspace subscription.
        return redirect()->away($url);
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
