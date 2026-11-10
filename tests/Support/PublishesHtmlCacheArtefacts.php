<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Tests\Support;

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Closure;

/**
 * Writes page cache fixtures the only way production can: through
 * PublishPublicArtefactAction under the publication guard, with no package
 * restricting reads unless a stamp is given. Exceptions from the write propagate.
 */
final class PublishesHtmlCacheArtefacts
{
    /**
     * @param  Closure(PublicArtefactPermit): mixed  $write
     * @param  PublicReadStampData|null  $builtUnder  the rules the copy was built under; none restricting reads by default
     */
    public static function write(Closure $write, ?PublicReadStampData $builtUnder = null): bool
    {
        return PublishPublicArtefactAction::run(resolve(HtmlCachePublicationGuard::class), null, $builtUnder ?? new PublicReadStampData(null, null), [], static function (PublicArtefactPermit $permit) use ($write): bool {
            $write($permit);

            return true;
        });
    }
}
