<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Models;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;

/**
 * @property int $id
 * @property string $url
 * @property string $url_hash
 * @property string $path
 * @property int|null $site_id
 * @property int|null $site_domain_id
 * @property int|null $language_id
 * @property string $cacheable_type
 * @property int $cacheable_id
 * @property CarbonImmutable|null $cached_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model|null $cacheable
 * @property-read Language|null $language
 * @property-read Site|null $site
 * @property-read SiteDomain|null $siteDomain
 *
 * @method static Builder<static>|CachedModelUrl newModelQuery()
 * @method static Builder<static>|CachedModelUrl newQuery()
 * @method static Builder<static>|CachedModelUrl query()
 */
final class CachedModelUrl extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'url',
        'url_hash',
        'path',
        'site_id',
        'site_domain_id',
        'language_id',
        'cacheable_type',
        'cacheable_id',
        'cached_at',
        'last_seen_at',
    ];

    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    public function cacheableLabel(): string
    {
        $cacheable = $this->cacheable;

        if (! $cacheable instanceof Model) {
            return class_basename($this->cacheable_type) . ' #' . $this->cacheable_id;
        }

        foreach (['name', 'label', 'slug', 'title'] as $attribute) {
            if (! array_key_exists($attribute, $cacheable->getAttributes())) {
                continue;
            }

            $value = $cacheable->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($this->cacheable_type) . ' #' . $this->cacheable_id;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function cacheable(): MorphTo
    {
        return $this->morphTo();
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
            'cached_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
