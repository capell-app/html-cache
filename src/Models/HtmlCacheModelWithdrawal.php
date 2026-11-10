<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Models;

use Capell\Core\Concerns\HasCapellTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One withdrawal of a model's public content. The incrementing key orders
 * withdrawals against the sequence captured by each render's publication
 * token; see HtmlCachePublicationGuard::fence().
 *
 * @property int $id
 * @property string $model_key
 * @property CarbonImmutable $withdrawn_at
 */
final class HtmlCacheModelWithdrawal extends Model
{
    use HasCapellTable;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'model_key',
        'withdrawn_at',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'withdrawn_at' => 'immutable_datetime',
        ];
    }
}
