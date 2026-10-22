<?php

namespace Tests\Feature;

use Botble\DatapilotConnector\Http\Controllers\DraftInboxController;
use Botble\DatapilotConnector\Models\ConnectorPairing;
use Botble\DatapilotConnector\Models\IncomingDraft;
use Botble\DatapilotConnector\Services\ConnectorSigning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatapilotConnectorInboxSecurityTest extends TestCase
{
    use RefreshDatabase;

    private ConnectorPairing $pairing;

    protected function setUp(): void
    {
        parent::setUp();

        $base = base_path('integrations/botble-connector');
        require_once $base.'/src/Models/ConnectorPairing.php';
        require_once $base.'/src/Models/IncomingDraft.php';
        require_once $base.'/src/Services/ConnectorSigning.php';
        require_once $base.'/src/Http/Controllers/DraftInboxController.php';
        (require $base.'/database/migrations/2026_10_22_000001_create_datapilot_connector_tables.php')->up();

        Route::post('/api/datapilot/v1/drafts', [DraftInboxController::class, 'stage']);
        config()->set('datapilot-connector.inbound_enabled', true);

        $this->pairing = ConnectorPairing::query()->create([
            'workspace_public_id' => (string) Str::uuid(),
            'store_public_id' => (string) Str::uuid(),
            'shared_secret' => bin2hex(random_bytes(32)),
            'status' => 'paired',
            'paired_at' => now(),
        ]);
    }

    private function sample(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'source' => 'cj',
            'source_product_id' => 'CJ123',
            'title' => 'Sample dress',
            'sku' => 'CJ-DRESS-1',
            'description' => 'Staged for review',
            'variants' => [['sku' => 'CJ-DRESS-1-M', 'price' => 28.99]],
        ];
    }

    private function send(array $data, ?string $nonce = null, ?string $time = null, bool $tamper = false, ?string $secretOverride = null)
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $nonce ??= bin2hex(random_bytes(16));
        $time ??= (string) time();
        $secret = $secretOverride ?? $this->pairing->shared_secret;
        $signature = 'sha256='.ConnectorSigning::sign($secret, 'POST', '/api/datapilot/v1/drafts', $time, $nonce, $body);
        if ($tamper) {
            $body = str_replace('Sample dress', 'Tampered dress', $body);
        }

        return $this->call('POST', '/api/datapilot/v1/drafts', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DATAPILOT_STORE' => $this->pairing->store_public_id,
            'HTTP_X_DATAPILOT_TIMESTAMP' => $time,
            'HTTP_X_DATAPILOT_NONCE' => $nonce,
            'HTTP_X_DATAPILOT_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_valid_message_only_stages_encrypted_review_draft(): void
    {
        $this->send($this->sample())->assertStatus(202)
            ->assertJsonPath('message', 'Draft staged for review only.');

        $draft = IncomingDraft::firstOrFail();
        $this->assertSame('pending_review', $draft->status);
        $this->assertSame('Sample dress', $draft->payload['title']);
        $this->assertNotSame('Sample dress', DB::table('datapilot_connector_drafts')->first()->payload);
        $this->assertDatabaseCount('datapilot_connector_nonces', 1);
    }

    public function test_replay_same_nonce_is_rejected(): void
    {
        $payload = $this->sample();
        $nonce = bin2hex(random_bytes(16));
        $this->send($payload, $nonce)->assertStatus(202);
        $this->send($payload, $nonce)->assertStatus(409);
        $this->assertDatabaseCount('datapilot_connector_drafts', 1);
    }

    public function test_same_payload_with_new_nonce_is_idempotent(): void
    {
        $payload = $this->sample();
        $this->send($payload)->assertStatus(202);
        $this->send($payload)->assertStatus(200);
        $this->assertDatabaseCount('datapilot_connector_drafts', 1);
    }

    public function test_bad_signature_old_timestamp_and_invalid_schema_are_rejected(): void
    {
        $this->send($this->sample(), tamper: true)->assertUnauthorized();
        $this->send($this->sample(), time: (string) (time() - 600))->assertUnauthorized();
        $invalid = $this->sample();
        $invalid['source'] = 'not-cj';
        $this->send($invalid)->assertStatus(422);
        $this->assertDatabaseCount('datapilot_connector_drafts', 0);
    }

    public function test_inbound_disabled_and_revoked_pairings_fail_closed(): void
    {
        config()->set('datapilot-connector.inbound_enabled', false);
        $this->send($this->sample())->assertStatus(503);
        config()->set('datapilot-connector.inbound_enabled', true);

        $oldSecret = $this->pairing->shared_secret;
        $this->pairing->status = 'revoked';
        $this->pairing->shared_secret = null;
        $this->pairing->save();
        $this->send($this->sample(), secretOverride: $oldSecret)->assertUnauthorized();
        $this->assertDatabaseCount('datapilot_connector_drafts', 0);
    }

    public function test_idempotency_key_cannot_be_reused_with_different_payload(): void
    {
        $product = $this->sample();
        $this->send($product)->assertStatus(202);
        $product['title'] = 'Changed';
        $this->send($product)->assertStatus(409);
        $this->assertDatabaseCount('datapilot_connector_drafts', 1);
    }
}
