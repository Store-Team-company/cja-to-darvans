<?php

namespace Tests\Feature;

use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BdpCustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $email, string $workspaceName): array
    {
        $user = User::factory()->create([
            'name' => explode('@', $email)[0],
            'email' => $email,
            'email_verified_at' => now(),
        ]);
        $workspace = SaasWorkspace::create(['name' => $workspaceName]);
        $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        return [$user, $workspace];
    }

    public function test_guest_and_saas_customer_cannot_open_or_mutate_platform_customer_management(): void
    {
        [$owner, $workspace] = $this->merchant('owner@example.test', 'Customer Only');
        $index = route('bdp.customers.index');
        $show = route('bdp.customers.show', $workspace->public_id);
        $suspend = route('bdp.customers.suspend', $workspace->public_id);

        $this->get($index)->assertRedirect(route('bdp.login.form'));
        $this->get($show)->assertRedirect(route('bdp.login.form'));
        $this->post($suspend, ['reason' => 'No administrative authorization'])
            ->assertRedirect(route('bdp.login.form'));
        $this->actingAs($owner)->get($index)->assertRedirect(route('bdp.login.form'));
        $this->actingAs($owner)->post($suspend, [
            'reason' => 'Customer role must never grant platform access',
        ])->assertRedirect(route('bdp.login.form'));
        $this->assertSame('pending', $workspace->fresh()->status);
        $this->assertDatabaseCount('bdp_customer_admin_audit', 0);
    }

    public function test_legacy_admin_can_search_customer_directory_and_view_customer_summary(): void
    {
        [$alice, $alpha] = $this->merchant('alice@example.test', 'Alpha Store');
        [$bob, $beta] = $this->merchant('bob@example.test', 'Beta Store');

        $alpha->products()->create(['title' => 'Private item', 'cj_product_id' => 'ALPHA']);
        $alpha->stores()->create(['name' => 'Example Botble', 'base_url' => 'https://shop.example.test']);
        $alpha->billing()->create([
            'stripe_customer_id' => 'cus_private',
            'stripe_subscription_id' => 'sub_private',
            'status' => 'active', 'plan_code' => 'starter',
            'stripe_price_id' => 'price_test_secret',
            'current_period_end' => now()->addMonth(),
        ]);
        $connection = $alpha->cjConnections()->create(['status' => 'connected']);
        $connection->api_key = 'SECRET-CJ-API-KEY-MUST-NOT-LEAK';
        $connection->save();

        $this->withSession(['bdp_logged_in' => true])
            ->get(route('bdp.customers.index', ['q' => 'alice@example.test']))
            ->assertOk()->assertSee('Manage Customers')
            ->assertSee('Alpha Store')->assertDontSee('Beta Store');
        $this->withSession(['bdp_logged_in' => true])
            ->get(route('bdp.customers.show', $alpha->public_id))
            ->assertOk()->assertSee('Example Botble')
            ->assertSee('alice@example.test')
            ->assertSee('Billing summary')
            ->assertDontSee('SECRET-CJ-API-KEY-MUST-NOT-LEAK')
            ->assertDontSee('cus_private')
            ->assertDontSee('sub_private')
            ->assertDontSee('price_test_secret');
    }

    public function test_admin_can_suspend_and_restore_with_reason_and_audit_without_changing_stripe(): void
    {
        [$owner, $workspace] = $this->merchant('merchant@example.test', 'Merchant A');
        $workspace->status = 'active';
        $workspace->save();

        $workspace->billing()->create([
            'stripe_customer_id' => 'cus_keep',
            'stripe_subscription_id' => 'sub_keep',
            'status' => 'active', 'plan_code' => 'starter',
            'stripe_price_id' => 'price_starter',
            'current_period_end' => now()->addMonth(),
        ]);

        $suspend = route('bdp.customers.suspend', $workspace->public_id);
        $restore = route('bdp.customers.restore', $workspace->public_id);

        $this->withSession(['bdp_logged_in' => true])->post($suspend, [
            'reason' => 'Temporary administrative security review',
        ])->assertRedirect(route('bdp.customers.show', $workspace->public_id));
        $this->assertSame('suspended', $workspace->fresh()->status);
        $this->assertDatabaseHas('bdp_customer_admin_audit', [
            'workspace_id' => $workspace->id,
            'action' => 'workspace.suspended',
            'from_status' => 'active',
            'to_status' => 'suspended',
            'reason' => 'Temporary administrative security review',
        ]);
        $this->assertDatabaseHas('saas_workspace_billing', [
            'workspace_id' => $workspace->id, 'status' => 'active',
            'stripe_subscription_id' => 'sub_keep',
        ]);
        $this->actingAs($owner)->get(route('saas.workspace.dashboard', $workspace->public_id))
            ->assertNotFound();

        $this->withSession(['bdp_logged_in' => true])->post($restore)
            ->assertRedirect(route('bdp.customers.show', $workspace->public_id));
        $this->assertSame('active', $workspace->fresh()->status);
        $this->assertDatabaseHas('bdp_customer_admin_audit', [
            'workspace_id' => $workspace->id,
            'action' => 'workspace.restored',
            'from_status' => 'suspended',
            'to_status' => 'active',
        ]);
    }

    public function test_pending_workspace_returns_to_pending_after_restore(): void
    {
        [$owner, $workspace] = $this->merchant('pending@example.test', 'Pending Shop');
        $this->withSession(['bdp_logged_in' => true])->post(
            route('bdp.customers.suspend', $workspace->public_id),
            ['reason' => 'Testing account status']
        )->assertRedirect();
        $this->withSession(['bdp_logged_in' => true])->post(
            route('bdp.customers.restore', $workspace->public_id)
        )->assertRedirect();

        $this->assertSame('pending', $workspace->fresh()->status);
    }

    public function test_invalid_suspension_and_cross_workspace_ids_do_not_modify_records(): void
    {
        [$owner, $workspace] = $this->merchant('owner@example.test', 'Safe Shop');
        $this->withSession(['bdp_logged_in' => true])
            ->post(route('bdp.customers.suspend', $workspace->public_id), [
                'reason' => 'x',
            ])->assertSessionHasErrors('reason');

        $this->withSession(['bdp_logged_in' => true])
            ->get('/admin/customers/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
        $this->assertDatabaseCount('bdp_customer_admin_audit', 0);
        $this->assertSame('pending', $workspace->fresh()->status);
    }

    public function test_admin_directory_is_paginated_and_filtered(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->merchant('account'.$i.'@example.test', 'Demo workspace '.$i);
        }
        $this->withSession(['bdp_logged_in' => true])
            ->get(route('bdp.customers.index'))
            ->assertOk()->assertSee('Next')
            ->assertSee('Demo workspace');
        $this->withSession(['bdp_logged_in' => true])
            ->get(route('bdp.customers.index', ['status' => 'suspended']))
            ->assertOk()->assertSee('No matching customers');
    }
}
