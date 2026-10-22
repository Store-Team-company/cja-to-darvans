<?php

namespace Tests\Feature;

use App\Models\SaasProduct;
use App\Models\SaasWorkspace;
use App\Models\SaasWorkspaceBilling;
use App\Models\User;
use App\Services\Saas\SaasEntitlements;
use App\Services\Saas\SaasStripeWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaasPortalBillingTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $email = 'owner@example.test'): array
    {
        $owner = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $workspace = SaasWorkspace::create(['name' => 'Merchant '.explode('@', $email)[0]]);
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        return [$owner, $workspace];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.billing_enabled', true);
        config()->set('saas.stripe_secret', 'sk_test_test_only_not_live');
        config()->set('saas.stripe_webhook_secret', 'whsec_not_live_test_only');
        config()->set('saas.plans.starter.stripe_price_id', 'price_starter_test');
        config()->set('saas.plans.growth.stripe_price_id', 'price_growth_test');
        config()->set('saas.plans.agency.stripe_price_id', 'price_agency_test');
    }

    private function subscription(SaasWorkspace $workspace, array $changes = []): array
    {
        return array_replace_recursive([
            'id' => 'sub_test_one', 'customer' => 'cus_test_one',
            'status' => 'active', 'current_period_end' => now()->addMonth()->timestamp,
            'cancel_at_period_end' => false,
            'metadata' => ['workspace_id' => $workspace->public_id],
            'items' => ['data' => [['price' => ['id' => 'price_starter_test']]]],
        ], $changes);
    }

    private function signedEvent(array $event): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, config('saas.stripe_webhook_secret'));

        return [$body, 't='.$timestamp.',v1='.$signature];
    }

    public function test_public_landing_and_pricing_are_hidden_until_saas_is_explicitly_enabled(): void
    {
        config()->set('saas.enabled', false);
        $this->get('/platform')->assertNotFound();
        $this->get('/pricing')->assertNotFound();
        $this->get('/')->assertRedirect(route('bdp.login.form'));

        config()->set('saas.enabled', true);
        $this->get('/platform')->assertOk()->assertSee('CJ products to your store');
        $this->get('/pricing')->assertOk()->assertSee('Starter')->assertSee('Growth')->assertSee('Agency');
    }

    public function test_workspace_pages_are_tenant_scoped_and_admin_login_remains_private(): void
    {
        [$alice, $alpha] = $this->workspace();
        [$bob, $beta] = $this->workspace('other@example.test');

        $alpha->products()->create(['title' => 'Private Alpha Product', 'cj_product_id' => 'CJ100']);
        $beta->products()->create(['title' => 'Secret Beta Product', 'cj_product_id' => 'CJ200']);

        foreach (['products', 'connections', 'team', 'billing', 'settings'] as $page) {
            $this->actingAs($alice)
                ->get(route('saas.workspace.'.$page, $alpha->public_id))
                ->assertOk();
            $this->actingAs($alice)
                ->get(route('saas.workspace.'.$page, $beta->public_id))
                ->assertNotFound();
        }

        $this->actingAs($alice)
            ->get(route('saas.workspace.products', $alpha->public_id))
            ->assertSee('Private Alpha Product')
            ->assertDontSee('Secret Beta Product');

        $this->get(route('bdp.index'))->assertRedirect(route('bdp.login.form'));
    }

    public function test_only_owner_may_change_workspace_name_or_start_checkout(): void
    {
        [$owner, $workspace] = $this->workspace();
        $importer = User::factory()->create(['email_verified_at' => now()]);
        $workspace->members()->attach($importer->id, ['role' => 'importer', 'status' => 'active']);

        $this->actingAs($importer)
            ->patch(route('saas.workspace.settings.update', $workspace->public_id), ['name' => 'Stolen'])
            ->assertForbidden();
        $this->actingAs($importer)
            ->post(route('saas.workspace.billing.checkout', $workspace->public_id), ['plan' => 'starter'])
            ->assertForbidden();
        $this->assertSame($workspace->name, $workspace->fresh()->name);

        $this->actingAs($owner)
            ->patch(route('saas.workspace.settings.update', $workspace->public_id), ['name' => 'Updated Shop'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Workspace name updated.');
        $this->assertSame('Updated Shop', $workspace->fresh()->name);
    }

    public function test_checkout_is_stripe_hosted_and_does_not_grant_access_until_signed_webhook(): void
    {
        [$owner, $workspace] = $this->workspace();
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ], 200)]);

        $this->actingAs($owner)
            ->post(route('saas.workspace.billing.checkout', $workspace->public_id), ['plan' => 'starter'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'checkout/sessions')
            && str_contains((string) $request->body(), $workspace->public_id)
            && !str_contains((string) $request->body(), 'sk_test_test_only_not_live'));
        $this->assertDatabaseHas('saas_workspace_billing', [
            'workspace_id' => $workspace->id,
            'status' => 'incomplete',
            'pending_checkout_id' => 'cs_test_123',
        ]);
        $this->assertFalse(app(SaasEntitlements::class)->active($workspace));

        $this->actingAs($owner)
            ->get(route('saas.workspace.billing', $workspace->public_id).'?checkout=success')
            ->assertOk();
        $this->assertFalse(app(SaasEntitlements::class)->active($workspace));
    }

    public function test_repeated_checkout_reuses_one_pending_session_without_double_creation(): void
    {
        [$owner, $workspace] = $this->workspace();
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_test_pending_1',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_pending_1',
            'expires_at' => now()->addHours(10)->timestamp,
        ], 200)]);
        $route = route('saas.workspace.billing.checkout', $workspace->public_id);

        // Distinct clicks must not generate additional purchasable sessions.
        $this->actingAs($owner)->post($route, ['plan' => 'starter'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_pending_1');
        $this->actingAs($owner)->post($route, ['plan' => 'growth'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_pending_1');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('saas_workspace_billing', 1);
        $this->assertDatabaseHas('saas_workspace_billing', [
            'workspace_id' => $workspace->id, 'pending_checkout_id' => 'cs_test_pending_1',
        ]);
        $this->assertFalse(app(SaasEntitlements::class)->active($workspace));
    }

    public function test_workspace_owner_can_expire_pending_checkout_and_change_plan(): void
    {
        [$owner, $workspace] = $this->workspace();
        $workspace->billing()->create([
            'pending_checkout_id' => 'cs_test_pending3',
            'pending_checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_pending3',
            'pending_checkout_expires_at' => now()->addHours(5),
            'status' => 'incomplete',
        ]);
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_test_pending3', 'status' => 'expired',
        ], 200)]);

        $url = route('saas.workspace.billing.cancel-checkout', $workspace->public_id);
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $workspace->members()->attach($staff->id, ['role' => 'viewer', 'status' => 'active']);

        $this->actingAs($staff)->post($url)->assertForbidden();
        Http::assertNothingSent();

        $this->actingAs($owner)->post($url)->assertRedirect()->assertSessionHas('status');
        $bill = $workspace->billing()->firstOrFail();
        $this->assertNull($bill->pending_checkout_id);
        $this->assertNull($bill->pending_checkout_url);
        $this->assertFalse(app(SaasEntitlements::class)->active($workspace));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'checkout/sessions/cs_test_pending3/expire'));
    }

    public function test_signed_expired_checkout_event_only_clears_matching_pending_session(): void
    {
        [$owner, $workspace] = $this->workspace();
        $workspace->billing()->create([
            'pending_checkout_id' => 'cs_test_still_open',
            'pending_checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_still_open',
            'pending_checkout_expires_at' => now()->addHours(5),
            'status' => 'incomplete',
        ]);

        foreach (['cs_test_old_checkout', 'cs_test_still_open'] as $i => $id) {
            [$body, $signature] = $this->signedEvent([
                'id' => 'evt_expire_'.$i,
                'type' => 'checkout.session.expired',
                'created' => time(),
                'data' => ['object' => [
                    'id' => $id, 'status' => 'expired',
                    'client_reference_id' => $workspace->public_id,
                    'metadata' => ['workspace_id' => $workspace->public_id],
                ]],
            ]);
            $this->call('POST', '/api/saas/stripe/webhook', [], [], [], [
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ], $body)->assertOk();

            if ($i === 0) {
                $this->assertSame('cs_test_still_open', $workspace->billing()->first()->pending_checkout_id);
            }
        }
        $this->assertNull($workspace->billing()->first()->pending_checkout_id);
        $this->assertDatabaseCount('saas_stripe_events', 2);
    }

    public function test_checkout_is_disabled_without_configured_stripe_pricing(): void
    {
        [$owner, $workspace] = $this->workspace();
        config()->set('saas.plans.starter.stripe_price_id', '');
        Http::fake();

        $this->actingAs($owner)
            ->post(route('saas.workspace.billing.checkout', $workspace->public_id), ['plan' => 'starter'])
            ->assertRedirect()
            ->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_forged_old_or_malformed_webhooks_are_rejected(): void
    {
        $this->postJson('/api/saas/stripe/webhook', ['id' => 'evt_fake'])->assertBadRequest();

        [$body, $signature] = $this->signedEvent([
            'id' => 'evt_old', 'type' => 'checkout.session.completed',
            'created' => time(), 'data' => ['object' => []],
        ]);
        $this->call('POST', '/api/saas/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.(time() - 1000).',v1='.hash_hmac('sha256', (time()-1000).'.'.$body, config('saas.stripe_webhook_secret')),
        ], $body)->assertBadRequest();

        $this->call('POST', '/api/saas/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature.'bad',
        ], $body)->assertBadRequest();

        $this->assertDatabaseCount('saas_workspace_billing', 0);
    }

    public function test_valid_webhooks_activate_one_workspace_and_are_idempotent(): void
    {
        [$owner, $workspace] = $this->workspace();
        [$other, $different] = $this->workspace('second@example.test');

        Http::fake(['api.stripe.com/v1/subscriptions/*' => Http::response($this->subscription($workspace), 200)]);

        $event = [
            'id' => 'evt_checkout_one', 'created' => time(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'mode' => 'subscription', 'status' => 'complete',
                'client_reference_id' => $workspace->public_id,
                'metadata' => ['workspace_id' => $workspace->public_id],
                'customer' => 'cus_test_one', 'subscription' => 'sub_test_one',
            ]],
        ];
        [$body, $signature] = $this->signedEvent($event);

        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/saas/stripe/webhook', [], [], [], [
                'HTTP_STRIPE_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ], $body)->assertOk();
        }

        $this->assertDatabaseCount('saas_stripe_events', 1);
        $this->assertDatabaseHas('saas_workspace_billing', [
            'workspace_id' => $workspace->id,
            'stripe_customer_id' => 'cus_test_one',
            'stripe_subscription_id' => 'sub_test_one',
            'status' => 'active',
            'plan_code' => 'starter',
        ]);
        $this->assertTrue(app(SaasEntitlements::class)->active($workspace));
        $this->assertFalse(app(SaasEntitlements::class)->active($different));
    }

    public function test_current_stripe_item_period_format_grants_and_expires_access(): void
    {
        [$owner, $workspace] = $this->workspace();
        $subscription = $this->subscription($workspace);
        unset($subscription['current_period_end']);
        $subscription['items']['data'][0]['current_period_end'] = now()->addWeeks(4)->timestamp;
        $subscription['items']['data'][0]['quantity'] = 1;
        Http::fake(['api.stripe.com/v1/subscriptions/*' => Http::response($subscription, 200)]);

        [$body, $signature] = $this->signedEvent([
            'id' => 'evt_new_stripe_period', 'created' => time(),
            'type' => 'customer.subscription.updated',
            'data' => ['object' => $subscription],
        ]);
        $this->call('POST', '/api/saas/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $body)->assertOk();

        $this->assertNotNull($workspace->billing()->first()->current_period_end);
        $this->assertTrue(app(SaasEntitlements::class)->active($workspace));

        $workspace->billing()->first()->update([
            'current_period_end' => now()->subMinute(),
        ]);
        $this->assertFalse(app(SaasEntitlements::class)->active($workspace));
    }

    public function test_subscription_events_cannot_assign_other_merchants_customer_or_plan(): void
    {
        [$owner, $workspace] = $this->workspace();
        [$other, $different] = $this->workspace('victim@example.test');

        $different->billing()->create([
            'workspace_id' => $different->id,
            'stripe_customer_id' => 'cus_test_one',
            'stripe_subscription_id' => 'sub_other_test',
            'status' => 'active',
            'stripe_price_id' => 'price_starter_test',
            'plan_code' => 'starter',
            'current_period_end' => now()->addMonth(),
        ]);
        Http::fake(['api.stripe.com/v1/subscriptions/*' => Http::response($this->subscription($workspace), 200)]);

        [$body, $signature] = $this->signedEvent([
            'id' => 'evt_cross_tenant', 'type' => 'customer.subscription.updated',
            'created' => time(), 'data' => ['object' => $this->subscription($workspace)],
        ]);
        $this->call('POST', '/api/saas/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $body)->assertStatus(500);
        $this->assertDatabaseMissing('saas_workspace_billing', ['workspace_id' => $workspace->id]);
        $this->assertDatabaseCount('saas_stripe_events', 0);
    }

    public function test_canceling_or_expiring_subscription_revokes_import_entitlements(): void
    {
        [$owner, $workspace] = $this->workspace();
        $bill = $workspace->billing()->create([
            'workspace_id' => $workspace->id,
            'stripe_customer_id' => 'cus_test_one',
            'stripe_subscription_id' => 'sub_test_one',
            'status' => 'active', 'stripe_price_id' => 'price_starter_test',
            'plan_code' => 'starter', 'current_period_end' => now()->addMonth(),
        ]);
        $this->assertTrue(app(SaasEntitlements::class)->allows($workspace, 'import'));

        [$body, $signature] = $this->signedEvent([
            'id' => 'evt_canceled', 'created' => time(),
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => $this->subscription($workspace, ['status' => 'canceled'])],
        ]);
        $this->call('POST', '/api/saas/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $body)->assertOk();
        $this->assertFalse(app(SaasEntitlements::class)->allows($workspace, 'import'));

        $bill->refresh();
        $bill->status = 'active';
        $bill->current_period_end = now()->subDay();
        $bill->save();
        $this->assertFalse(app(SaasEntitlements::class)->active($workspace));
    }

    public function test_plan_product_limit_is_enforced_server_side(): void
    {
        [$owner, $workspace] = $this->workspace();
        $workspace->billing()->create([
            'workspace_id' => $workspace->id,
            'stripe_customer_id' => 'cus_limit',
            'stripe_subscription_id' => 'sub_limit',
            'status' => 'active', 'stripe_price_id' => 'price_starter_test',
            'plan_code' => 'starter', 'current_period_end' => now()->addMonth(),
        ]);
        config()->set('saas.plans.starter.product_limit', 1);
        $this->assertTrue(app(SaasEntitlements::class)->allows($workspace, 'import'));

        $workspace->products()->create(['title' => 'Existing CJ product', 'cj_product_id' => 'already']);
        $this->assertFalse(app(SaasEntitlements::class)->allows($workspace, 'import'));
        $this->assertTrue(app(SaasEntitlements::class)->allows($workspace, 'publish'));
    }

    public function test_only_paid_workspace_owners_can_store_encrypted_cj_keys(): void
    {
        [$owner, $workspace] = $this->workspace();
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $workspace->members()->attach($staff->id, ['role' => 'importer', 'status' => 'active']);

        $saveRoute = route('saas.workspace.connections.cj.save', $workspace->public_id);
        $this->actingAs($owner)->post($saveRoute, ['api_key' => 'CJSECRETFOREXAMPLE123456'])->assertForbidden();
        $this->actingAs($staff)->post($saveRoute, ['api_key' => 'CJSECRETFOREXAMPLE123456'])->assertForbidden();

        $workspace->billing()->create([
            'stripe_customer_id' => 'cus_cjtest',
            'stripe_subscription_id' => 'sub_cjtest',
            'status' => 'active', 'stripe_price_id' => 'price_starter_test',
            'plan_code' => 'starter', 'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($owner)->post($saveRoute, [
            'api_key' => 'CJSECRETFOREXAMPLE123456',
        ])->assertRedirect()->assertSessionHas('status');

        $connection = $workspace->cjConnections()->firstOrFail();
        $this->assertSame('pending_verification', $connection->status);
        $this->assertSame('CJSECRETFOREXAMPLE123456', $connection->api_key);
        $this->assertNotSame('CJSECRETFOREXAMPLE123456', DB::table('saas_cj_connections')->first()->api_key);

        $this->actingAs($owner)
            ->get(route('saas.workspace.connections', $workspace->public_id))
            ->assertOk()
            ->assertDontSee('CJSECRETFOREXAMPLE123456');

        $this->actingAs($owner)->delete(route('saas.workspace.connections.cj.remove', $workspace->public_id))
            ->assertRedirect();
        $this->assertNull($connection->fresh()->api_key);
    }

    public function test_store_registration_rejects_internal_urls_and_enforces_plan_limits(): void
    {
        // This test exercises multiple invalid URLs in one minute; keep the
        // real throttle for production routes but disable it for this matrix.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$owner, $workspace] = $this->workspace();
        $workspace->billing()->create([
            'stripe_customer_id' => 'cus_store_test',
            'stripe_subscription_id' => 'sub_store_test',
            'status' => 'active', 'stripe_price_id' => 'price_starter_test',
            'plan_code' => 'starter', 'current_period_end' => now()->addMonth(),
        ]);

        $route = route('saas.workspace.connections.botble.add', $workspace->public_id);
        foreach (['http://shop.example.com', 'https://127.0.0.1', 'https://localhost',
            'https://user:pass@shop.example.com', 'https://shop.example.com:8080',
        ] as $unsafe) {
            $this->actingAs($owner)->post($route, [
                'name' => 'My Store', 'base_url' => $unsafe,
            ])->assertSessionHasErrors('base_url');
        }
        $this->assertDatabaseCount('saas_botble_stores', 0);

        $this->actingAs($owner)->post($route, [
            'name' => 'Primary Store', 'base_url' => 'https://shop.example.com/',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('saas_botble_stores', [
            'workspace_id' => $workspace->id, 'name' => 'Primary Store',
            'base_url' => 'https://shop.example.com', 'status' => 'pending_verification',
        ]);

        $this->actingAs($owner)->post($route, [
            'name' => 'Second Store', 'base_url' => 'https://other.example.com',
        ])->assertSessionHasErrors('base_url');

        $this->assertDatabaseCount('saas_botble_stores', 1);
    }

    public function test_one_customer_cannot_remove_another_customers_store(): void
    {
        [$owner, $workspace] = $this->workspace();
        [$other, $different] = $this->workspace('storeowner@example.test');
        $store = $different->stores()->create([
            'name' => 'Private Botble Store',
            'base_url' => 'https://private-shop.example.com',
            'status' => 'pending_verification',
        ]);
        $url = route('saas.workspace.connections.botble.remove', [
            'workspace' => $workspace->public_id, 'store' => $store->id,
        ]);
        $this->actingAs($owner)->delete($url)->assertNotFound();
        $this->assertDatabaseHas('saas_botble_stores', ['id' => $store->id]);
    }

    public function test_signup_normalizes_email_and_rejects_case_variant_duplicates(): void
    {
        config()->set('saas.registration_enabled', true);
        \Illuminate\Support\Facades\Mail::fake();
        $this->post(route('saas.register.submit'), [
            'name' => 'Store Owner', 'workspace_name' => 'Example Store',
            'email' => 'Owner@Example.Test',
            'password' => 'StrongPasswordTest123',
            'password_confirmation' => 'StrongPasswordTest123',
        ])->assertRedirect(route('saas.login'));

        $this->assertDatabaseHas('users', ['email' => 'owner@example.test']);
        $this->post(route('saas.register.submit'), [
            'name' => 'Second Owner', 'workspace_name' => 'Second Store',
            'email' => 'OWNER@EXAMPLE.TEST',
            'password' => 'StrongPasswordTest123',
            'password_confirmation' => 'StrongPasswordTest123',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_reset_replaces_password_without_losing_workspace_membership(): void
    {
        [$owner, $workspace] = $this->workspace();
        $token = \Illuminate\Support\Facades\Password::createToken($owner);
        $this->post(route('saas.password.update'), [
            'token' => $token,
            'email' => strtoupper($owner->email),
            'password' => 'NewExamplePassWORD123',
            'password_confirmation' => 'NewExamplePassWORD123',
        ])->assertRedirect(route('saas.login'));

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(
            'NewExamplePassWORD123', $owner->fresh()->password
        ));
        $this->assertDatabaseHas('saas_workspace_memberships', [
            'workspace_id' => $workspace->id, 'user_id' => $owner->id, 'status' => 'active',
        ]);
    }

    public function test_reset_pages_exist_for_customers(): void
    {
        $this->get(route('saas.password.request'))->assertOk()->assertSee('Forgot your password');
        $this->get('/app/login')->assertOk()->assertSee('Forgot password?');
    }
}
