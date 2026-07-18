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
        'completed_sites',
        'failed_sites',
        'errors',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
