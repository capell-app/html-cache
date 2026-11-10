<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Tests\Support;

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use RuntimeException;

final class PublishesStaticPages
{
    public static function put(StaticErrorPageStore|StaticMaintenancePageStore $store, string $file, string $contents): void
    {
        $stamp = resolve(PublicReadEligibilityState::class)->freshStamp();
        throw_unless(PublishPublicArtefactAction::run($store, null, $stamp, [], static function (PublicArtefactPermit $permit) use ($store, $file, $contents): bool {
            $store->put($permit, $file, $contents);

            return true;
        }), RuntimeException::class, 'The static page fixture could not be published.');
    }
}
