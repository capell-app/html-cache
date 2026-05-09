<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Tests;

use Aimeos\Nestedset\NestedSetServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Macros\BlueprintMacros;
use Capell\Core\Models\Media;
use Capell\HtmlCache\Providers\HtmlCacheServiceProvider;
use Capell\Tests\AbstractTestCase;
use Illuminate\Database\Schema\Blueprint;
use Override;

abstract class HtmlCacheTestCase extends AbstractTestCase
{
    protected function getPackageServiceName(): string
    {
        return 'capell-html-cache';
    }

    /**
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            HtmlCacheServiceProvider::class,
        ];
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);

        Blueprint::mixin(new BlueprintMacros);
        (new NestedSetServiceProvider($app))->register();
        config(['activitylog.enabled' => false]);
        config(['media-library.media_model' => Media::class]);

        CapellCore::registerPackage(
            HtmlCacheServiceProvider::$packageName,
            path: realpath(__DIR__ . '/../'),
        );
        CapellCore::forcePackageInstalled(HtmlCacheServiceProvider::$packageName);
    }
}
