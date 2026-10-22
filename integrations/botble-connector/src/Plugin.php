<?php

namespace Botble\DatapilotConnector;

use Botble\PluginManagement\Abstracts\PluginOperationAbstract;

class Plugin extends PluginOperationAbstract
{
    public static function activate(): void
    {
        // Activation intentionally does NOT pair an account or expose write APIs.
    }

    public static function deactivate(): void
    {
        // Do not delete eCommerce products or credentials automatically.
    }

    public static function remove(): void
    {
        // Permanent uninstall only: purge signing keys and connector-owned
        // snapshots/mappings. Deactivation never deletes any data.
        foreach ([
            'datapilot_connector_variant_mappings',
            'datapilot_connector_product_mappings',
            'datapilot_connector_drafts',
            'datapilot_connector_nonces',
            'datapilot_connector_pairings',
        ] as $table) {
            \Illuminate\Support\Facades\Schema::dropIfExists($table);
        }
    }
}
