<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorConfluence;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Confluence connector package.
 *
 * Merges the Confluence provider block into the host's `connectors.php`
 * config tree (under `providers.confluence`). Publishes both the config
 * fragment + the brand asset for hosts that want to customise either.
 *
 * Auto-registration into the connector registry happens at the base
 * package level via composer's `extra.askmydocs.connectors` discovery
 * — the entry is in this package's composer.json.
 */
class ConfluenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/confluence.php', 'connectors.providers.confluence');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/confluence.php' => config_path('connectors-confluence.php'),
            ], 'connector-confluence-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-confluence-assets');
        }
    }
}
