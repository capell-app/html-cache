<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $status
 * @property int $total_sites
 * @property list<int>|null $site_ids
 * @property bool $enable_global
 * @property int|null $activate_site_id
 * @property int $completed_sites
 * @property int $failed_sites
 * @property array<string, string|null>|null $errors
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class HtmlCacheGenerationRun extends Model
{
    use HasUuids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'status',
        'total_sites',
        'site_ids',
        'enable_global',
        'activate_site_id',
        'completed_sites',
        'failed_sites',
        'errors',
        'started_at',
        'finished_at',
    ];

    /**
     * Whether this run currently targets the given site, either directly
     * (per-site generation) or as part of the intended global rollout.
     */
    public function targetsSite(int $siteId): bool
    {
        return in_array($siteId, $this->site_ids ?? [], true);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'site_ids' => 'array',
            'enable_global' => 'boolean',
            'errors' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
