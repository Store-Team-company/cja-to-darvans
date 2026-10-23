<?php

namespace Tests\Feature;

use App\Models\SaasBotbleStore;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaasBotblePairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.billing_enabled', true);
        config()->set('saas.plans.starter.stripe_price_id', 'price_test_starter');
    }

    private function tenant(string $email = 'merchant@example.test'): array
    {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => null, 'saas_manually_approved_at' => now()]);
        $workspace = SaasWorkspace::create(['name' => 'Test Shop']);
        $workspace->members()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        $store = $workspace->stores()->create([
            'name' => 'Example Botble', 'base_url' => 'https://botble-shop.example.com',
            'status' => 'pending_verification',
        ]);

        return [$user, $workspace, $store];
    }

    private function subscribe(SaasWorkspace $workspace): void
    {
        $workspace->billing()->create([
            'stripe_customer_id' => 'cus_test_'.$workspace->id,
            'stripe_subscription_id' => 'sub_test_'.$workspace->id,
            'stripe_price_id' => 'price_test_starter',
            'plan_code' => 'starter',
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function issue(User $user, SaasWorkspace $workspace, SaasBotbleStore $store): string
    {
        $this->actingAs($user)->post(route('saas.workspace.connections.botble.pair-code', [
            'workspace' => $workspace->public_id, 'store' => $store->id,
        ]))->assertRedirect()->assertSessionHas('pairing_code');

        return (string) session('pairing_code');
    }

    private function complete(SaasWorkspace $workspace, SaasBotbleStore $store, string $code, string $site = 'https://botble-shop.example.com')
    {
        return $this->postJson(route('saas.botble.pair.complete'), [
            'workspace_id' => $workspace->public_id,
            'store_id' => $store->public_id,
            'site_url' => $site,
            'pairing_code' => $code,
        ]);
    }

    public function test_unpaid_and_non_owner_cannot_issue_codes(): void
    {
        [$owner, $workspace, $store] = $this->tenant();
        $this->actingAs($owner)->post(route('saas.workspace.connections.botble.pair-code', [
            'workspace' => $workspace->public_id, 'store' => $store->id,
        ]))->assertForbidden();

        $this->subscribe($workspace);
        $stranger = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($stranger)->post(route('saas.workspace.connections.botble.pair-code', [
            'workspace' => $workspace->public_id, 'store' => $store->id,
        ]))->assertNotFound();
        $this->assertDatabaseCount('saas_botble_pairing_challenges', 0);
    }

    public function test_valid_code_pairs_only_registered_host_and_stores_secret_encrypted(): void
    {
        [$owner, $workspace, $store] = $this->tenant();
        $this->subscribe($workspace);
        $code = $this->issue($owner, $workspace, $store);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $code);
        $this->assertNotSame($code, DB::table('saas_botble_pairing_challenges')->first()->token_hash);
        $this->complete($workspace, $store, $code, 'https://evil.example.com')
            ->assertStatus(422);
        $this->complete($workspace, $store, $code)
            ->assertOk()->assertJsonPath('status', 'connected')
            ->assertJsonPath('store_id', $store->public_id)
            ->assertJsonPath('workspace_id', $workspace->public_id);
        $this->assertSame('connected', $store->fresh()->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $store->fresh()->shared_secret);
        $this->assertNotSame($store->fresh()->shared_secret, DB::table('saas_botble_stores')->first()->shared_secret);
        $this->assertNotNull(DB::table('saas_botble_pairing_challenges')->first()->used_at);
        $this->complete($workspace, $store, $code)->assertStatus(422);
    }

    public function test_wrong_or_expired_challenge_fails_without_connection(): void
    {
        [$owner, $workspace, $store] = $this->tenant();
        $this->subscribe($workspace);
        $code = $this->issue($owner, $workspace, $store);
        $this->complete($workspace, $store, str_repeat('a', 64))->assertStatus(422);
        $this->assertNull($store->fresh()->shared_secret);
        DB::table('saas_botble_pairing_challenges')->update(['expires_at' => now()->subMinute()]);
        $this->complete($workspace, $store, $code)->assertStatus(422);
        $this->assertSame('pending_verification', $store->fresh()->status);
    }

    public function test_revocation_clears_secret_and_cannot_cross_workspaces(): void
    {
        [$owner, $workspace, $store] = $this->tenant();
        $this->subscribe($workspace);
        $code = $this->issue($owner, $workspace, $store);
        $this->complete($workspace, $store, $code)->assertOk();
        [$other, $otherWorkspace] = $this->tenant('second-owner@example.test');
        $this->subscribe($otherWorkspace);
        $this->actingAs($other)->post(route('saas.workspace.connections.botble.revoke', [
            'workspace' => $otherWorkspace->public_id, 'store' => $store->id,
        ]))->assertNotFound();
        $this->actingAs($owner)->post(route('saas.workspace.connections.botble.revoke', [
            'workspace' => $workspace->public_id, 'store' => $store->id,
        ]))->assertRedirect();
        $this->assertSame('revoked', $store->fresh()->status);
        $this->assertNull($store->fresh()->shared_secret);
    }

    public function test_disabling_billing_blocks_even_previously_issued_code(): void
    {
        [$owner, $workspace, $store] = $this->tenant();
        $this->subscribe($workspace);
        $code = $this->issue($owner, $workspace, $store);
        config()->set('saas.billing_enabled', false);
        $this->complete($workspace, $store, $code)->assertStatus(503);
        $this->assertNull($store->fresh()->shared_secret);
    }

    public function test_connector_signature_rejects_changed_body_old_timestamps_and_bad_nonce(): void
    {
        require_once base_path('integrations/botble-connector/src/Services/ConnectorSigning.php');
        $cls = \Botble\DatapilotConnector\Services\ConnectorSigning::class;
        $secret = bin2hex(random_bytes(32));
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $body = '{"title":"Product"}';
        $signature = 'sha256='.$cls::sign($secret, 'POST', '/api/datapilot/v1/drafts',
            $timestamp, $nonce, $body);

        $this->assertTrue($cls::valid($secret, 'POST', '/api/datapilot/v1/drafts',
            $timestamp, $nonce, $body, $signature));
        $this->assertFalse($cls::valid($secret, 'POST', '/api/datapilot/v1/drafts',
            $timestamp, $nonce, $body.' ', $signature));
        $this->assertFalse($cls::valid($secret, 'POST', '/api/datapilot/v1/drafts',
            (string) (time() - 601), $nonce, $body, $signature));
        $this->assertFalse($cls::valid($secret, 'POST', '/api/datapilot/v1/drafts',
            $timestamp, 'incorrect', $body, $signature));
    }
}
