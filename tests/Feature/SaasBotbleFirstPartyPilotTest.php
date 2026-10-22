<?php

namespace Tests\Feature;

use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaasBotbleFirstPartyPilotTest extends TestCase
{
    use RefreshDatabase;

    private function setupPilot(): array
    {
        config()->set('saas.enabled', true);
        config()->set('saas.registration_enabled', false);
        config()->set('saas.billing_enabled', false);

        $owner = User::factory()->create(['email_verified_at' => now()]);
        $workspace = SaasWorkspace::query()->create(['name' => 'Internal Data Pilot staging']);
        $workspace->status = 'active';
        $workspace->save();
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        config()->set('saas.botble_pilot', [
            'enabled' => true,
            'workspace_id' => $workspace->public_id,
            'store_id' => '',
            'store_url' => 'https://sandbox.daystart.pk',
        ]);

        return [$owner, $workspace];
    }

    private function addStore(User $owner, SaasWorkspace $workspace, string $url = 'https://sandbox.daystart.pk')
    {
        return $this->actingAs($owner)->post(
            route('saas.workspace.connections.botble.add', ['workspace' => $workspace->public_id]),
            ['name' => 'Our Botble sandbox', 'base_url' => $url]
        );
    }

    private function issue(User $owner, SaasWorkspace $workspace, $store)
    {
        return $this->actingAs($owner)->post(
            route('saas.workspace.connections.botble.pair-code', [
                'workspace' => $workspace->public_id,
                'store' => $store->id,
            ])
        );
    }

    public function test_internal_owner_sees_pilot_registration_and_pairing_controls(): void
    {
        [$owner, $workspace] = $this->setupPilot();
        $url = route('saas.workspace.connections', ['workspace' => $workspace->public_id]);

        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertSee('Register store for pairing')
            ->assertSee('https://sandbox.daystart.pk');

        $this->addStore($owner, $workspace)->assertRedirect();
        $store = $workspace->stores()->firstOrFail();
        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertDontSee('Generate one-time pairing code');

        config()->set('saas.botble_pilot.store_id', $store->public_id);
        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertSee('Generate one-time pairing code');
    }

    public function test_pilot_disabled_by_default_does_not_allow_unpaid_store(): void
    {
        [$owner, $workspace] = $this->setupPilot();
        config()->set('saas.botble_pilot.enabled', false);
        $this->addStore($owner, $workspace)->assertForbidden();
        $this->assertDatabaseCount('saas_botble_stores', 0);
    }

    public function test_only_pinned_workspace_and_exact_staging_url_can_register(): void
    {
        [$owner, $workspace] = $this->setupPilot();
        $this->addStore($owner, $workspace, 'https://example.org')->assertForbidden();
        $this->addStore($owner, $workspace, 'http://sandbox.daystart.pk')->assertForbidden();
        $this->assertDatabaseCount('saas_botble_stores', 0);

        $stranger = User::factory()->create(['email_verified_at' => now()]);
        $other = SaasWorkspace::query()->create(['name' => 'Unauthorized tenant']);
        $other->status = 'active';
        $other->save();
        $other->members()->attach($stranger->id, ['role' => 'owner', 'status' => 'active']);
        $this->addStore($stranger, $other)->assertForbidden();
        $this->assertDatabaseCount('saas_botble_stores', 0);

        $this->addStore($owner, $workspace)->assertRedirect();
        $store = $workspace->stores()->firstOrFail();
        $this->assertSame('https://sandbox.daystart.pk', $store->base_url);
        $this->assertDatabaseCount('saas_workspace_billing', 0);
        $this->addStore($owner, $workspace)->assertForbidden();
        $this->assertDatabaseCount('saas_botble_stores', 1);
    }

    public function test_pairing_requires_store_id_pinned_on_server_even_for_same_workspace(): void
    {
        [$owner, $workspace] = $this->setupPilot();
        $this->addStore($owner, $workspace)->assertRedirect();
        $store = $workspace->stores()->firstOrFail();
        $this->issue($owner, $workspace, $store)->assertForbidden();
        $this->assertDatabaseCount('saas_botble_pairing_challenges', 0);
    }

    public function test_internal_pilot_pairs_with_one_time_code_without_enabling_billing(): void
    {
        [$owner, $workspace] = $this->setupPilot();
        $this->addStore($owner, $workspace)->assertRedirect();
        $store = $workspace->stores()->firstOrFail();
        config()->set('saas.botble_pilot.store_id', $store->public_id);
        $this->issue($owner, $workspace, $store)
            ->assertRedirect()->assertSessionHas('pairing_code');

        $code = (string) session('pairing_code');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $code);
        $this->postJson(route('saas.botble.pair.complete'), [
            'workspace_id' => $workspace->public_id,
            'store_id' => $store->public_id,
            'site_url' => 'https://wrong.example.com',
            'pairing_code' => $code,
        ])->assertStatus(422);

        $this->postJson(route('saas.botble.pair.complete'), [
            'workspace_id' => $workspace->public_id,
            'store_id' => $store->public_id,
            'site_url' => 'https://sandbox.daystart.pk',
            'pairing_code' => $code,
        ])->assertOk()->assertJsonPath('status', 'connected');

        $this->assertSame('connected', $store->fresh()->status);
        $this->assertNotNull($store->fresh()->shared_secret);
        $this->assertNotSame(
            $store->fresh()->shared_secret,
            DB::table('saas_botble_stores')->where('id', $store->id)->value('shared_secret')
        );
        $this->assertDatabaseCount('saas_workspace_billing', 0);
        $this->assertFalse((bool) config('saas.billing_enabled'));

        $this->postJson(route('saas.botble.pair.complete'), [
            'workspace_id' => $workspace->public_id,
            'store_id' => $store->public_id,
            'site_url' => 'https://sandbox.daystart.pk',
            'pairing_code' => $code,
        ])->assertStatus(422);
    }

    public function test_disabled_pilot_cannot_complete_existing_unpaid_challenge(): void
    {
        [$owner, $workspace] = $this->setupPilot();
        $this->addStore($owner, $workspace)->assertRedirect();
        $store = $workspace->stores()->firstOrFail();
        config()->set('saas.botble_pilot.store_id', $store->public_id);
        $this->issue($owner, $workspace, $store)->assertSessionHas('pairing_code');
        $code = (string) session('pairing_code');
        config()->set('saas.botble_pilot.enabled', false);
        $this->postJson(route('saas.botble.pair.complete'), [
            'workspace_id' => $workspace->public_id,
            'store_id' => $store->public_id,
            'site_url' => 'https://sandbox.daystart.pk',
            'pairing_code' => $code,
        ])->assertStatus(503);
        $this->assertSame('pending_verification', $store->fresh()->status);
    }
}
