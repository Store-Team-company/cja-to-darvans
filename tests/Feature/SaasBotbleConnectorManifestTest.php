<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaasBotbleConnectorManifestTest extends TestCase
{
    public function test_botble_connector_has_valid_manifest_and_permission_protected_admin_route(): void
    {
        $root = base_path('integrations/botble-connector');
        $manifest = json_decode(
            (string) file_get_contents($root . '/plugin.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('Botble\\DatapilotConnector\\', $manifest['namespace']);
        $this->assertSame(
            'Botble\\DatapilotConnector\\Providers\\DatapilotConnectorServiceProvider',
            $manifest['provider']
        );
        $this->assertSame('0.1.0', $manifest['version']);

        $routes = (string) file_get_contents($root . '/routes/web.php');
        $this->assertStringContainsString("'middleware' => 'auth'", $routes);
        $this->assertStringContainsString("'permission' => 'datapilot-connector.settings'", $routes);
        $this->assertStringNotContainsString('Route::post(', $routes);
        $this->assertStringNotContainsString('Route::put(', $routes);
        $this->assertStringNotContainsString('Route::delete(', $routes);
    }
}
