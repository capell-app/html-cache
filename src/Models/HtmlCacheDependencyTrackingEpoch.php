<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Models;

use Capell\Core\Concerns\HasCapellTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Records that every cached page was written with this version of dependency
 * tracking or later, so a withdrawal may trust the dependency index.
 *
 * @property int $id
 * @property int $version
 * @property CarbonImmutable $trusted_since
 */
final class HtmlCacheDependencyTrackingEpoch extends Model
{
    use HasCapellTable;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'version',
        'trusted_since',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'trusted_since' => 'immutable_datetime',
        ];
    }
}
