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
        // The foundation has no plugin-owned tables or customer data to delete.
    }
}
