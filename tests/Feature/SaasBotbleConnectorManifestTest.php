<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaasBotbleConnectorManifestTest extends TestCase
{
    public function test_botble_connector_has_manifest_permissions_pairing_and_disabled_draft_inbox(): void
    {
        $root = base_path('integrations/botble-connector');
        $manifest = json_decode((string) file_get_contents($root.'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Botble\\DatapilotConnector\\', $manifest['namespace']);
        $this->assertSame('Botble\\DatapilotConnector\\Providers\\DatapilotConnectorServiceProvider', $manifest['provider']);
        $this->assertSame('0.3.1', $manifest['version']);

        $routes = (string) file_get_contents($root.'/routes/web.php');
        $this->assertStringContainsString("'middleware' => 'auth'", $routes);
        $this->assertStringContainsString("'permission' => 'datapilot-connector.settings'", $routes);
        $this->assertStringContainsString('SettingsController::class', $routes);
        $this->assertStringContainsString('DraftInboxController::class', $routes);
        $this->assertStringContainsString('IncomingProductsController::class', $routes);
        $this->assertStringNotContainsString('Route::delete(', $routes);

        $config = (string) file_get_contents($root.'/config/datapilot.php');
        $this->assertStringContainsString("'inbound_enabled' => (bool) env('DATAPILOT_INBOUND_DRAFTS_ENABLED', false)", $config);
        $this->assertStringContainsString("'catalog_import_enabled' => (bool) env('DATAPILOT_CATALOG_IMPORT_ENABLED', false)", $config);

        $inbox = (string) file_get_contents($root.'/src/Http/Controllers/DraftInboxController.php');
        $this->assertStringContainsString("status = 'pending_review'", $inbox);
        $this->assertStringNotContainsString('Botble\\Ecommerce\\Models\\Product', $inbox);
        $this->assertStringNotContainsString('->publish(', $inbox);
        
        $settingsView = (string) file_get_contents($root.'/resources/views/settings.blade.php');
        $incomingView = (string) file_get_contents($root.'/resources/views/incoming-products.blade.php');
        $this->assertStringContainsString('BaseHelper::getAdminMasterLayoutTemplate()', $settingsView);
        $this->assertStringContainsString('BaseHelper::getAdminMasterLayoutTemplate()', $incomingView);
        $this->assertStringContainsString("route('datapilot.connector.pair')", $settingsView);
        $this->assertStringContainsString("route('datapilot.connector.incoming')", $settingsView);
        $this->assertStringNotContainsString('<!doctype html>', strtolower($settingsView));
        $this->assertStringNotContainsString('<!doctype html>', strtolower($incomingView));

        $pairingController = (string) file_get_contents($root.'/src/Http/Controllers/SettingsController.php');
        $this->assertStringContainsString("withInput(", $pairingController);
        $this->assertStringContainsString("'workspace_id', 'store_id'", $pairingController);
        $this->assertStringContainsString('pairing_code', $pairingController);
    }
}
