<?php

namespace Tests\Feature;

use App\Models\SaasCjConnection;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaasCjConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'CJ-PRIVATE-PILOT-API-KEY-EXAMPLE-NEVER-REAL';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.billing_enabled', false);
        config()->set('saas.registration_enabled', false);
        config()->set('saas.cj_pilot.enabled', false);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $owner = User::factory()->create([
            'email_verified_at' => null,
            'saas_manually_approved_at' => now(),
        ]);
        $workspace = SaasWorkspace::create(['name' => 'CJ internal pilot']);
        $workspace->status = 'active';
        $workspace->save();
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        config()->set('saas.cj_pilot', [
            'enabled' => true,
            'workspace_id' => $workspace->public_id,
        ]);

        return [$owner, $workspace];
    }

    private function endpoint(SaasWorkspace $workspace, string $suffix): string
    {
        return route('saas.workspace.connections.cj.'.$suffix, ['workspace' => $workspace->public_id]);
    }

    private function response(): array
    {
        return [
            'code' => 200, 'success' => true, 'result' => true,
            'data' => [
                'accessToken' => 'cj-internal-access-token-only',
                'refreshToken' => 'cj-internal-refresh-token-only',
                'accessTokenExpiryDate' => now()->addDays(170)->toIso8601String(),
                'refreshTokenExpiryDate' => now()->addDays(180)->toIso8601String(),
            ],
        ];
    }

    private function save(User $owner, SaasWorkspace $workspace): void
    {
        $this->actingAs($owner)->post($this->endpoint($workspace, 'save'), [
            'api_key' => self::SECRET,
        ])->assertRedirect();
    }

    public function test_owner_can_save_encrypt_and_verify_with_real_cj_v2_response(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->save($owner, $workspace);
        $connection = $workspace->cjConnections()->firstOrFail();
        $this->assertSame('pending_verification', $connection->status);
        $this->assertNotSame(self::SECRET, DB::table('saas_cj_connections')->value('api_key'));

        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->response(), 200)]);
        $this->actingAs($owner)->post($this->endpoint($workspace, 'verify'))
            ->assertRedirect()->assertSessionHas('status');
        $connection->refresh();
        $this->assertSame('connected', $connection->status);
        $this->assertNotNull($connection->last_verified_at);
        $this->assertTrue($connection->access_token_expires_at->isFuture());
        $this->assertSame('cj-internal-access-token-only', $connection->access_token);
        $this->assertNotSame($connection->access_token, DB::table('saas_cj_connections')->value('access_token'));
        Http::assertSent(fn ($req) => $req->method() === 'POST'
            && $req->url() === 'https://developers.cjdropshipping.com/api2.0/v1/authentication/getAccessToken'
            && $req['apiKey'] === self::SECRET);
        $this->actingAs($owner)->get(route('saas.workspace.connections', $workspace->public_id))
            ->assertOk()->assertSee('Test connection')
            ->assertSee('Connected')->assertDontSee(self::SECRET)
            ->assertDontSee('cj-internal-access-token-only');
    }

    public function test_expired_access_token_uses_refresh_endpoint_not_new_login(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->save($owner, $workspace);
        $connection = $workspace->cjConnections()->first();
        $connection->forceFill([
            'access_token' => 'expired-access',
            'refresh_token' => 'old-refresh-token',
            'access_token_expires_at' => now()->subMinute(),
            'refresh_token_expires_at' => now()->addDays(10),
            'last_auth_attempt_at' => now()->subMinutes(2),
        ])->save();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->response(), 200)]);
        $this->actingAs($owner)->post($this->endpoint($workspace, 'verify'))->assertRedirect();
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/refreshAccessToken')
            && $req['refreshToken'] === 'old-refresh-token');
        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_invalid_api_key_marks_failed_without_disclosing_secret(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->save($owner, $workspace);
        Http::fake(['developers.cjdropshipping.com/*' => Http::response([
            'code' => 1600005, 'success' => false, 'result' => false,
            'message' => self::SECRET,
            'data' => null,
        ], 200)]);
        $this->actingAs($owner)->post($this->endpoint($workspace, 'verify'))
            ->assertSessionHasErrors('cj');
        $connection = $workspace->cjConnections()->firstOrFail();
        $this->assertSame('failed', $connection->status);
        $this->assertSame('invalid_credentials', $connection->last_error_code);
        $this->assertNull($connection->access_token);
        $this->actingAs($owner)->get(route('saas.workspace.connections', $workspace->public_id))
            ->assertOk()->assertDontSee(self::SECRET);
    }

    public function test_refresh_failure_never_keeps_old_connected_status(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->save($owner, $workspace);
        $conn = $workspace->cjConnections()->first();
        $conn->forceFill([
            'status' => 'connected',
            'access_token' => 'expired',
            'refresh_token' => 'revoked',
            'access_token_expires_at' => now()->subDay(),
            'refresh_token_expires_at' => now()->addDays(10),
        ])->save();
        Http::fake(['developers.cjdropshipping.com/*' => Http::response([
            'code' => 1600003, 'success' => false, 'result' => false, 'data' => null,
        ], 200)]);
        $this->actingAs($owner)->post($this->endpoint($workspace, 'verify'))
            ->assertSessionHasErrors('cj');
        $this->assertSame('failed', $conn->fresh()->status);
        $this->assertNull($conn->fresh()->access_token);
    }

    public function test_rate_limit_and_timeout_are_safe_and_not_connected(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->save($owner, $workspace);
        Http::fake(['developers.cjdropshipping.com/*' => Http::response(['message' => self::SECRET], 429)]);
        $this->actingAs($owner)->post($this->endpoint($workspace, 'verify'))
            ->assertSessionHasErrors('cj');
        $this->assertSame('failed', $workspace->cjConnections()->first()->status);
        $this->travel(70)->seconds();
        Cache::flush();
        Http::fake(fn () => throw new ConnectionException('Unsafe network exception '.self::SECRET));
        $this->actingAs($owner)->post($this->endpoint($workspace, 'verify'))
            ->assertSessionHasErrors('cj');
        $this->assertSame('failed', $workspace->cjConnections()->first()->status);
    }

    public function test_repeated_attempts_are_throttled_without_additional_cj_request(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->save($owner, $workspace);
        Http::fake(['developers.cjdropshipping.com/*' => Http::response($this->response(), 200)]);
        $this->actingAs($owner)->post($this->endpoint($workspace, 'verify'))->assertRedirect();
        $this->assertNotNull($workspace->cjConnections()->firstOrFail()->last_auth_attempt_at);
        $this->actingAs($owner)->post($this->endpoint($workspace, 'verify'))
            ->assertSessionHasErrors('cj');
        Http::assertSentCount(1);
    }

    public function test_key_replacement_clears_tokens_and_disconnect_deletes_secrets(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->save($owner, $workspace);
        $conn = $workspace->cjConnections()->first();
        $conn->forceFill(['status' => 'connected', 'access_token' => 'secret', 'refresh_token' => 'secret2'])->save();
        $this->actingAs($owner)->post($this->endpoint($workspace, 'save'), [
            'api_key' => 'NEW-PRIVATE-API-KEY-FOR-ROTATION',
        ])->assertRedirect();
        $this->assertSame('pending_verification', $conn->fresh()->status);
        $this->assertNull($conn->fresh()->access_token);
        $this->assertNull($conn->fresh()->refresh_token);

        $this->actingAs($owner)->delete($this->endpoint($workspace, 'remove'))->assertRedirect();
        $this->assertSame('disconnected', $conn->fresh()->status);
        $this->assertNull($conn->fresh()->api_key);
    }

    public function test_no_plan_or_other_tenant_or_staff_can_authenticate_via_pilot(): void
    {
        [$owner, $workspace] = $this->fixture();
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $workspace->members()->attach($staff->id, ['role' => 'viewer', 'status' => 'active']);
        $this->actingAs($staff)->post($this->endpoint($workspace, 'save'), [
            'api_key' => self::SECRET,
        ])->assertForbidden();

        $otherOwner = User::factory()->create(['email_verified_at' => now()]);
        $other = SaasWorkspace::create(['name' => 'Other tenant']);
        $other->status = 'active';
        $other->save();
        $other->members()->attach($otherOwner->id, ['role' => 'owner', 'status' => 'active']);
        $this->actingAs($otherOwner)->post($this->endpoint($other, 'save'), [
            'api_key' => self::SECRET,
        ])->assertForbidden();

        config()->set('saas.cj_pilot.enabled', false);
        $this->actingAs($owner)->post($this->endpoint($workspace, 'save'), [
            'api_key' => self::SECRET,
        ])->assertForbidden();
        $this->assertDatabaseCount('saas_cj_connections', 0);
    }

    public function test_connections_page_renders_for_active_manually_approved_owner_without_cj_credentials(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->actingAs($owner)->get(route('saas.workspace.connections', $workspace->public_id))
            ->assertOk()->assertSee('Connections')->assertSee('Save encrypted key');
        $workspace->status = 'pending';
        $workspace->save();
        $this->actingAs($owner)->get(route('saas.workspace.connections', $workspace->public_id))
            ->assertOk()->assertDontSee('Save encrypted key');
    }

    public function test_never_calls_cj_if_request_is_unauthenticated(): void
    {
        [$owner, $workspace] = $this->fixture();
        $this->post($this->endpoint($workspace, 'verify'))->assertRedirect(route('saas.login'));
        Http::assertNothingSent();
    }
}
