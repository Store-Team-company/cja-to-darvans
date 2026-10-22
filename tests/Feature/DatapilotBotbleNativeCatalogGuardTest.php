<?php

namespace Tests\Feature;

use Botble\DatapilotConnector\Http\Controllers\IncomingProductsController;
use Botble\DatapilotConnector\Models\ConnectorPairing;
use Botble\DatapilotConnector\Models\IncomingDraft;
use Botble\DatapilotConnector\Models\ProductMapping;
use Botble\DatapilotConnector\Services\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DatapilotBotbleNativeCatalogGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $root = base_path('integrations/botble-connector');

        foreach ([
            'src/Models/ConnectorPairing.php',
            'src/Models/IncomingDraft.php',
            'src/Models/ProductMapping.php',
            'src/Models/VariantMapping.php',
            'src/Services/CatalogImportService.php',
            'src/Http/Controllers/IncomingProductsController.php',
        ] as $path) {
            require_once $root.'/'.$path;
        }
        (require $root.'/database/migrations/2026_10_22_000001_create_datapilot_connector_tables.php')->up();
        (require $root.'/database/migrations/2026_10_22_000002_create_datapilot_product_mappings.php')->up();
        (require $root.'/database/migrations/2026_10_22_000003_add_datapilot_draft_import_history.php')->up();
        Route::post('/datapilot-native-approve/{draft}', [IncomingProductsController::class, 'approve']);
        config()->set('datapilot-connector.catalog_import_enabled', false);
    }

    private function draft(): IncomingDraft
    {
        $pair = ConnectorPairing::query()->create([
            'workspace_public_id' => (string) Str::uuid(),
            'store_public_id' => (string) Str::uuid(),
            'shared_secret' => bin2hex(random_bytes(32)),
            'paired_at' => now(),
            'status' => 'paired',
        ]);

        $draft = new IncomingDraft();
        $draft->fill([

            'idempotency_key' => (string) Str::uuid(),
            'source_product_id' => 'CJ00001',
            'title' => 'QA Only',
            'sku' => 'QA-1',
            'payload_sha256' => hash('sha256', 'testing'),
            'payload' => [
                'idempotency_key' => (string) Str::uuid(),
                'source' => 'cj',
                'source_product_id' => 'CJ00001',
                'title' => 'QA Only',
                'sku' => 'QA-1',
                'price' => 55.99,
            ],
            'status' => 'pending_review',
        ]);
        $draft->pairing_id = $pair->getKey();
        $draft->save();

        return $draft;
    }

    public function test_catalog_writes_are_disabled_by_default(): void
    {
        $draft = $this->draft();
        $this->post('/datapilot-native-approve/'.$draft->id)
            ->assertRedirect()
            ->assertSessionHasErrors('catalog');
        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertDatabaseCount('datapilot_connector_product_mappings', 0);
    }

    public function test_missing_botble_ecommerce_fails_closed_without_any_native_write(): void
    {
        $draft = $this->draft();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Native Botble eCommerce model missing');
        app(CatalogImportService::class)->approve($draft);
    }

    public function test_mapping_key_is_unique_per_pairing_and_source(): void
    {
        $draft = $this->draft();
        ProductMapping::query()->create([
            'pairing_id' => $draft->pairing_id,
            'source' => 'cj',
            'source_product_id' => 'CJ00001',
            'botble_product_id' => 1234,
        ]);

        $this->assertDatabaseHas('datapilot_connector_product_mappings', [
            'pairing_id' => $draft->pairing_id,
            'source_product_id' => 'CJ00001',
            'botble_product_id' => 1234,
        ]);

        $this->assertSame(
            1,
            ProductMapping::query()
                ->where('pairing_id', $draft->pairing_id)
                ->where('source', 'cj')
                ->where('source_product_id', 'CJ00001')->count()
        );
    }
    public function test_revoked_connection_rejects_catalog_approval(): void
    {
        $draft = $this->draft();
        $pair = ConnectorPairing::query()->findOrFail($draft->pairing_id);
        $pair->status = 'revoked';
        $pair->shared_secret = null;
        $pair->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection revoked');
        app(CatalogImportService::class)->approve($draft);
    }

    public function test_media_payload_is_not_silently_discarded(): void
    {
        $draft = $this->draft();
        $payload = $draft->payload;
        $payload['images'] = ['https://images.example.test/item.jpg'];
        $draft->payload = $payload;
        $draft->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Image import is not enabled');
        app(CatalogImportService::class)->approve($draft);
    }

    public function test_approved_review_failure_is_visible_and_retryable(): void
    {
        $draft = $this->draft();
        config()->set('datapilot-connector.catalog_import_enabled', true);

        $this->from('/datapilot-native-review')
            ->post('/datapilot-native-approve/'.$draft->id)
            ->assertRedirect('/datapilot-native-review')
            ->assertSessionHasErrors('catalog');

        $this->assertSame('failed', $draft->fresh()->status);
        $this->assertSame(1, (int) $draft->fresh()->import_attempts);
        $this->assertStringContainsString('Native Botble eCommerce model missing', $draft->fresh()->last_import_error);
        $this->assertDatabaseCount('datapilot_connector_product_mappings', 0);

        $this->from('/datapilot-native-review')
            ->post('/datapilot-native-approve/'.$draft->id)
            ->assertRedirect('/datapilot-native-review')
            ->assertSessionHasErrors('catalog');

        $this->assertSame(2, (int) $draft->fresh()->import_attempts);
    }

}
