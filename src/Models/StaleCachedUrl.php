<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Models;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property string $url
 * @property string $url_hash
 * @property string $path
 * @property string $stale_key
 * @property int|null $site_id
 * @property int|null $site_domain_id
 * @property int|null $language_id
 * @property string|null $cache_path
 * @property string|null $error_cache_path
 * @property string|null $reason
 * @property string $status
 * @property string|null $claim_token
 * @property int $attempts
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Language|null $language
 * @property-read Site|null $site
 * @property-read SiteDomain|null $siteDomain
 *
 * @method static Builder<static>|StaleCachedUrl newModelQuery()
 * @method static Builder<static>|StaleCachedUrl newQuery()
 * @method static Builder<static>|StaleCachedUrl query()
 */
final class StaleCachedUrl extends Model
{
    use HasFactory;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_PROCESSED = 'processed';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_EXHAUSTED = 'exhausted';

    /** @var list<string> */
    protected $fillable = [
        'url',
        'url_hash',
        'path',
        'stale_key',
        'site_id',
        'site_domain_id',
        'language_id',
        'cache_path',
        'error_cache_path',
        'reason',
        'status',
        'claim_token',
        'attempts',
        'processed_at',
        'failed_at',
        'last_error',
    ];

    public static function staleKey(string $urlHash, ?int $siteId, ?int $siteDomainId, string $path): string
    {
        return hash('sha256', implode('|', [
            $urlHash,
            $siteId === null ? 'site:any' : 'site:' . $siteId,
            $siteDomainId === null ? 'domain:any' : 'domain:' . $siteDomainId,
            $path,
        ]));
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<SiteDomain, $this> */
    public function siteDomain(): BelongsTo
    {
        return $this->belongsTo(SiteDomain::class);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
