<?php

namespace Tests\Feature;

use App\Models\SaasWorkspace;
use App\Models\User;
use App\Services\Saas\SaasEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SaasAdminPlanGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.admin_grants_enabled', true);
        config()->set('saas.billing_enabled', true);
        config()->set('saas.stripe_secret', 'sk_test_fake_synthetic_only');
        config()->set('saas.stripe_webhook_secret', 'whsec_fake_synthetic_only');
        config()->set('saas.plans.starter.stripe_price_id', 'price_starter_fake');
        config()->set('saas.plans.growth.stripe_price_id', 'price_growth_fake');
        config()->set('saas.plans.agency.stripe_price_id', 'price_agency_fake');
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $user = User::factory()->create([
            'email_verified_at' => null, 'saas_manually_approved_at' => now(),
        ]);
        $workspace = SaasWorkspace::create(['name' => 'Test DataPilot merchant']);
        $workspace->forceFill(['status' => 'active'])->save();
        $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        return [$user, $workspace];
    }

    private function admin(): static
    {
        return $this->withSession(['bdp_logged_in' => true]);
    }

    private function grant(SaasWorkspace $workspace, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->admin()->post(route('bdp.saas.workspaces.grant', $workspace->public_id), array_replace([
            'plan' => 'starter', 'days' => 14,
            'reason' => 'Internal staged merchant evaluation',
            'confirmation' => 'GRANT',
        ], $overrides));
    }

    public function test_only_platform_admin_can_grant_or_revoke_not_customer_owner(): void
    {
        [$user, $workspace] = $this->fixture();
        $data = ['plan' => 'starter', 'days' => 14,
            'reason' => 'Private staged merchant evaluation', 'confirmation' => 'GRANT'];

        $this->actingAs($user)->post(route('bdp.saas.workspaces.grant', $workspace->public_id), $data)
            ->assertRedirect(route('bdp.login.form'));
        $this->actingAs($user)->get(route('bdp.saas.workspaces'))
            ->assertRedirect(route('bdp.login.form'));
        $this->assertDatabaseCount('saas_admin_plan_grants', 0);
        $this->assertDatabaseCount('saas_admin_plan_grant_events', 0);

        $this->grant($workspace)->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('saas_admin_plan_grants', 1);
        $this->assertDatabaseHas('saas_admin_plan_grant_events', [
            'workspace_id' => $workspace->id, 'action' => 'granted', 'plan_code' => 'starter',
        ]);
    }

    public function test_admin_grants_are_complimentary_and_expire_without_creating_stripe_subscription(): void
    {
        [$owner, $workspace] = $this->fixture();
        $entitlements = app(SaasEntitlements::class);
        $this->assertFalse($entitlements->active($workspace));
        $this->grant($workspace)->assertRedirect();

        $this->assertTrue($entitlements->active($workspace));
        $this->assertFalse($entitlements->paidActive($workspace));
        $this->assertSame('complimentary', $entitlements->accessSource($workspace));
        $this->assertSame(250, $entitlements->limit($workspace, 'product_limit'));
        $this->assertTrue($entitlements->allows($workspace, 'import'));
        $this->assertDatabaseCount('saas_workspace_billing', 0);
        Http::assertNothingSent();

        $this->actingAs($owner)->get(route('saas.workspace.dashboard', $workspace->public_id))
            ->assertOk()->assertSee('Complimentary access')->assertDontSee('Your subscription is verified through Stripe.');
        $this->actingAs($owner)->get(route('saas.workspace.billing', $workspace->public_id))
            ->assertOk()->assertSee('No payment was charged');

        $this->travel(15)->days();
        $this->assertFalse($entitlements->active($workspace));
        $this->assertSame('none', $entitlements->accessSource($workspace));
        $this->assertFalse($entitlements->allows($workspace, 'import'));
    }

    public function test_grant_can_be_revoked_and_both_actions_have_an_audit_event(): void
    {
        [$user, $workspace] = $this->fixture();
        $this->grant($workspace)->assertRedirect();

        $url = route('bdp.saas.workspaces.revoke', $workspace->public_id);
        $this->post($url, [
            'reason' => 'Internal trial evaluation concluded',
            'confirmation' => 'REVOKE',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertFalse(app(SaasEntitlements::class)->active($workspace));
        $this->assertDatabaseHas('saas_admin_plan_grant_events', [
            'workspace_id' => $workspace->id, 'action' => 'revoked',
        ]);
        $this->assertDatabaseCount('saas_admin_plan_grant_events', 2);
        $this->assertDatabaseCount('saas_workspace_billing', 0);
    }

    public function test_suspended_workspace_or_invalid_grant_data_never_grants_access(): void
    {
        [$user, $workspace] = $this->fixture();
        $workspace->forceFill(['status' => 'suspended'])->save();
        $this->grant($workspace)->assertStatus(422);
        $workspace->forceFill(['status' => 'active'])->save();
        foreach ([
            ['days' => 91], ['days' => 0], ['plan' => 'unlimited'],
            ['reason' => 'short'], ['confirmation' => 'not-approved'],
        ] as $invalid) {
            $this->grant($workspace, $invalid)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('saas_admin_plan_grants', 0);
        $this->assertDatabaseCount('saas_admin_plan_grant_events', 0);
    }

    public function test_stripe_paid_entitlement_takes_precedence_and_cannot_be_overwritten_by_manual_grant(): void
    {
        [$user, $workspace] = $this->fixture();
        $this->grant($workspace)->assertRedirect();
        $workspace->billing()->create([
            'status' => 'active', 'plan_code' => 'growth',
            'stripe_customer_id' => 'cus_fake_paid_123',
            'stripe_subscription_id' => 'sub_fake_paid_123',
            'stripe_price_id' => 'price_growth_fake',
            'current_period_end' => now()->addMonth(),
        ]);

        $entitlements = app(SaasEntitlements::class);
        $this->assertTrue($entitlements->paidActive($workspace));
        $this->assertSame('stripe', $entitlements->accessSource($workspace));
        $this->assertSame(2000, $entitlements->limit($workspace, 'product_limit'));

        $this->grant($workspace)->assertStatus(422);
        $this->assertDatabaseCount('saas_admin_plan_grants', 1);
        $this->assertDatabaseCount('saas_admin_plan_grant_events', 1);
    }

    public function test_customer_can_subscribe_while_complimentary_access_is_active_without_false_payment(): void
    {
        [$user, $workspace] = $this->fixture();
        $this->grant($workspace)->assertRedirect();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_complimentary_conversion',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_complimentary_conversion',
            'expires_at' => now()->addHours(10)->timestamp,
        ], 200)]);

        $this->actingAs($user)->get(route('saas.workspace.billing', $workspace->public_id))
            ->assertOk()->assertSee('Subscribe with Stripe');

        $this->actingAs($user)->post(
            route('saas.workspace.billing.checkout', $workspace->public_id),
            ['plan' => 'growth']
        )->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_complimentary_conversion');

        $this->assertTrue(app(SaasEntitlements::class)->active($workspace));
        $this->assertFalse(app(SaasEntitlements::class)->paidActive($workspace));
        $this->assertDatabaseHas('saas_workspace_billing', [
            'workspace_id' => $workspace->id, 'status' => 'incomplete',
            'pending_checkout_id' => 'cs_test_complimentary_conversion',
        ]);
        $this->assertDatabaseCount('saas_admin_plan_grants', 1);
        Http::assertSentCount(1);
    }

    public function test_complimentary_grant_never_activates_other_tenants_or_changes_stripe_billing_state(): void
    {
        [$owner, $workspace] = $this->fixture();
        [$otherOwner, $otherWorkspace] = $this->fixture();
        $this->grant($workspace)->assertRedirect();
        $entitlements = app(SaasEntitlements::class);

        $this->assertTrue($entitlements->active($workspace));
        $this->assertFalse($entitlements->active($otherWorkspace));
        $this->actingAs($otherOwner)->get(route('saas.workspace.billing', $workspace->public_id))
            ->assertNotFound();
        $this->assertDatabaseCount('saas_workspace_billing', 0);
        $this->assertDatabaseCount('saas_stripe_events', 0);
    }
}
