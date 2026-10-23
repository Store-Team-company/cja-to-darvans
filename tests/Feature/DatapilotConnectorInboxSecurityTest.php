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
        require_once $base.'/src/Services/SignedDraftHtmlSanitizer.php';
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
            $body = str_replace(['Sample dress', 'CJ Bedding Set'], ['Tampered dress', 'Tampered bedding'], $body);
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

    private function fortyVariantDraft(): array
    {
        $variants = [];
        for ($i = 1; $i <= 40; $i++) {
            $variants[] = [
                'source_variant_id' => 'CJ-V'.$i,
                'sku' => 'BEDDING-'.$i,
                'price' => '69.99',
                'weight_g' => '1250',
                'attributes' => ['CJ Option' => 'Powder Blue - '.$i.'x240 bedding'],
                'image' => 'https://images.example.test/bedding-'.$i.'.jpg',
            ];
        }
        return [
            'schema_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'source' => 'cj',
            'source_product_id' => 'CJ-BEDDING-40',
            'title' => 'CJ Bedding Set',
            'sku' => 'CJ-BEDDING-PARENT',
            'description' => 'Complete supplier variation case',
            'price' => '69.99',
            'short_description_html' => '<p>Premium bedding.</p>',
            'long_description_html' => '<p>Fine cotton.</p><script>malicious()</script>',
            'seo_title' => 'Premium bedding',
            'seo_description' => 'Comfortable bedding set',
            'category_name' => 'Home Textiles > Bedding Sets',
            'weight_g' => '1250',
            'images' => ['https://images.example.test/bedding.jpg'],
            'variant_count' => 40,
            'variants' => $variants,
        ];
    }

    public function test_v2_all_forty_variations_are_staged_atomically_and_html_is_sanitized(): void
    {
        config()->set('datapilot-connector.variant_drafts_enabled', true);
        $this->send($this->fortyVariantDraft())->assertStatus(202)
            ->assertJsonPath('message', 'Draft staged for review only.');
        $draft = IncomingDraft::query()->sole();
        $this->assertSame(40, $draft->payload['variant_count']);
        $this->assertCount(40, $draft->payload['variants']);
        $this->assertSame('BEDDING-40', $draft->payload['variants'][39]['sku']);
        $this->assertSame('69.99', $draft->payload['variants'][0]['price']);
        $this->assertStringNotContainsString('<script', $draft->payload['long_description_html']);
        $this->assertDatabaseCount('datapilot_connector_drafts', 1);
        $this->assertNotSame('CJ Bedding Set', DB::table('datapilot_connector_drafts')->first()->payload);
    }

    public function test_v2_is_disabled_by_default_while_v1_still_works(): void
    {
        config()->set('datapilot-connector.variant_drafts_enabled', false);
        $this->send($this->fortyVariantDraft())->assertStatus(503);
        $this->send($this->sample())->assertStatus(202);
        $this->assertDatabaseCount('datapilot_connector_drafts', 1);
    }

    public function test_v2_rejects_partial_or_duplicated_variation_collections(): void
    {
        config()->set('datapilot-connector.variant_drafts_enabled', true);
        $p = $this->fortyVariantDraft();
        array_pop($p['variants']);
        $this->send($p)->assertStatus(422);
        $p = $this->fortyVariantDraft();
        $p['variants'][1]['sku'] = $p['variants'][0]['sku'];
        $this->send($p)->assertStatus(422);
        $p = $this->fortyVariantDraft();
        $p['variants'][1]['attributes'] = $p['variants'][0]['attributes'];
        $this->send($p)->assertStatus(422);
        $p = $this->fortyVariantDraft();
        unset($p['variants'][5]['source_variant_id']);
        $this->send($p)->assertStatus(422);
        $this->assertDatabaseCount('datapilot_connector_drafts', 0);
    }

    public function test_v2_rejects_unreviewed_prices_and_unsigned_changes(): void
    {
        config()->set('datapilot-connector.variant_drafts_enabled', true);
        $p = $this->fortyVariantDraft();
        $p['variants'][4]['price'] = '13.50';
        $this->send($p)->assertStatus(422);
        $this->send($this->fortyVariantDraft(), tamper: true)->assertUnauthorized();
        $this->assertDatabaseCount('datapilot_connector_drafts', 0);
    }

    public function test_v2_duplicate_delivery_with_new_nonce_does_not_create_duplicate(): void
    {
        config()->set('datapilot-connector.variant_drafts_enabled', true);
        $p = $this->fortyVariantDraft();
        $this->send($p)->assertStatus(202);
        $this->send($p)->assertStatus(200);
        $this->assertDatabaseCount('datapilot_connector_drafts', 1);
        $this->assertDatabaseCount('datapilot_connector_nonces', 2);
    }

    public function test_v2_hundred_one_variants_are_rejected_instead_of_truncated(): void
    {
        config()->set('datapilot-connector.variant_drafts_enabled', true);
        $p = $this->fortyVariantDraft();
        for ($i = 41; $i <= 101; $i++) {
            $v = $p['variants'][0];
            $v['source_variant_id'] = 'CJ-V'.$i;
            $v['sku'] = 'BEDDING-'.$i;
            $v['attributes']['CJ Option'] = 'Other size '.$i;
            $p['variants'][] = $v;
        }
        $p['variant_count'] = 101;
        $this->send($p)->assertStatus(422);
        $this->assertDatabaseCount('datapilot_connector_drafts', 0);
    }

    public function test_v2_unknown_fields_and_malformed_attachments_are_rejected(): void
    {
        config()->set('datapilot-connector.variant_drafts_enabled', true);
        $p = $this->fortyVariantDraft();
        $p['publish'] = true;
        $this->send($p)->assertStatus(422);
        $p = $this->fortyVariantDraft();
        $p['images'][] = 'file:///etc/passwd';
        $this->send($p)->assertStatus(422);
        $p = $this->fortyVariantDraft();
        $p['variants'][0]['image'] = 'http://example.test/insecure.jpg';
        $this->send($p)->assertStatus(422);
        $this->assertDatabaseCount('datapilot_connector_drafts', 0);
    }
}
