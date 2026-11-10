<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Models\Site;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Carbon\CarbonImmutable;

/**
 * Whether a copy that a web server serves straight from disk, without
 * Laravel, may be written under the current public-read rules.
 *
 * Such a copy is never checked again, so it is written only while the rules
 * can be read and no access transition is scheduled (no valid_until). A page
 * whose rules change at a known moment is served through Laravel, which
 * checks the stamp on every request. The scheduled withdrawal job is cleanup
 * only: no disk copy ever depends on it, on the queue or on a worker's clock.
 * The write-time check is Core's PublishPublicArtefactAction, which every
 * disk copy is written through.
 */
final class PublicReadDiskCopyPolicy
{
    private const string REJECTED = 'rejected';

    public static function rejectionReason(?PublicReadStampData $stamp): ?HtmlCacheEligibilityReason
    {
        if (! $stamp instanceof PublicReadStampData) {
            return HtmlCacheEligibilityReason::CacheWriteDisabled;
        }

        return $stamp->validUntil instanceof CarbonImmutable ? HtmlCacheEligibilityReason::PublicReadTimeBounded : null;
    }

    /**
     * The stamp a render is built under, or null when no disk copy of it may
     * be written.
     */
    public static function buildKey(?int $siteId = null): string
    {
        $stamp = self::stamp($siteId);

        return self::rejectionReason($stamp) instanceof HtmlCacheEligibilityReason || ! $stamp instanceof PublicReadStampData
            ? self::REJECTED
            : $stamp->key();
    }

    /**
     * The stamp for the page being served: valid_until counts only schedules
     * that can affect this site, so another site's embargo never stops this
     * one's disk copies.
     */
    public static function stamp(?int $siteId = null): ?PublicReadStampData
    {
        return resolve(PublicReadEligibilityState::class)->stamp($siteId ?? self::siteId());
    }

    /** The site of the page being served, when it has one. */
    public static function siteId(): ?int
    {
        $site = resolve(FrontendContextReader::class)->site();
        $key = $site instanceof Site ? $site->getKey() : null;

        return is_int($key) ? $key : null;
    }
}
