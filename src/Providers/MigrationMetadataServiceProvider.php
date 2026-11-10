<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Providers;

use Capell\Core\Support\Migration\MigrationRootRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class MigrationMetadataServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {

        $this->callAfterResolving(MigrationRootRegistry::class, static function (MigrationRootRegistry $registry): void {
            $registry->register('capell-app/html-cache', __DIR__ . '/../../database/migrations', [
                '2026_05_10_190854_01_create_cached_model_urls_table' => 'cached_model_urls',
                '2026_05_14_000001_create_stale_cached_urls_table' => 'stale_cached_urls',
                '2026_07_18_000001_create_html_cache_generation_runs_table' => 'html_cache_generation_runs',
                '2026_10_12_000001_create_html_cache_request_observations_table' => 'html_cache_request_observations',
                '2026_11_02_000001_create_html_cache_model_withdrawals_table' => 'html_cache_model_withdrawals',
                '2026_11_02_000002_create_html_cache_dependency_tracking_epochs_table' => 'html_cache_dependency_tracking_epochs',
            ]);
        });
    }
}
