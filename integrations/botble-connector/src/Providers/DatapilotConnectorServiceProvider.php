<?php

namespace Botble\DatapilotConnector\Providers;

use Botble\Base\Facades\DashboardMenu;
use Botble\Base\Traits\LoadAndPublishDataTrait;
use Illuminate\Support\ServiceProvider;

class DatapilotConnectorServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/datapilot.php', 'datapilot-connector');
    }

    public function boot(): void
    {
        $this
            ->setNamespace('plugins/datapilot-connector')
            ->loadAndPublishConfigurations(['permissions'])
            ->loadAndPublishViews()
            ->loadMigrations()
            ->loadRoutes(['web']);

        DashboardMenu::default()->beforeRetrieving(function (): void {
            DashboardMenu::make()->registerItem([
                'id' => 'cms-plugins-datapilot-connector',
                'priority' => 90,
                'parent_id' => null,
                'name' => 'Data Pilot Connector',
                'icon' => 'ti ti-plug-connected',
                'url' => route('datapilot.connector.settings'),
                'permissions' => ['datapilot-connector.settings'],
            ]);
        });
    }
}
