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
        $this->assertSame('0.2.0', $manifest['version']);

        $routes = (string) file_get_contents($root.'/routes/web.php');
        $this->assertStringContainsString("'middleware' => 'auth'", $routes);
        $this->assertStringContainsString("'permission' => 'datapilot-connector.settings'", $routes);
        $this->assertStringContainsString('SettingsController::class', $routes);
        $this->assertStringContainsString('DraftInboxController::class', $routes);
        $this->assertStringNotContainsString('Route::delete(', $routes);

        $config = (string) file_get_contents($root.'/config/datapilot.php');
        $this->assertStringContainsString("'inbound_enabled' => (bool) env('DATAPILOT_INBOUND_DRAFTS_ENABLED', false)", $config);

        $inbox = (string) file_get_contents($root.'/src/Http/Controllers/DraftInboxController.php');
        $this->assertStringContainsString("status = 'pending_review'", $inbox);
        $this->assertStringNotContainsString('Botble\\Ecommerce\\Models\\Product', $inbox);
        $this->assertStringNotContainsString('->publish(', $inbox);
    }
}
