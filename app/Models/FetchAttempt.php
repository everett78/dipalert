<?php

namespace App\Models;

use App\Enums\FetchOutcome;
use App\Enums\FetchSource;
use App\Services\FailedPageStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One fetch of a link.
 *
 * @property int $id
 * @property int $url_id
 * @property ?int $store_id
 * @property FetchOutcome $outcome
 * @property FetchSource $source
 * @property ?string $batch_id
 * @property ?string $method
 * @property ?int $http_status
 * @property ?string $final_url
 * @property ?string $message
 * @property ?string $page_path
 * @property Carbon $created_at
 * @property ?Url $url
 * @property ?Store $store
 */
class FetchAttempt extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'url_id',
        'store_id',
        'outcome',
        'source',
        'batch_id',
        'method',
        'http_status',
        'final_url',
        'message',
        'page_path',
    ];

    protected $casts = [
        'outcome' => FetchOutcome::class,
        'source' => FetchSource::class,
        'http_status' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Url, $this>
     */
    public function url(): BelongsTo
    {
        return $this->belongsTo(Url::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Attempts that did not end with "ok".
     *
     * @param  Builder<FetchAttempt>  $query
     * @return Builder<FetchAttempt>
     */
    public function scopeProblems(Builder $query): Builder
    {
        return $query->where('outcome', '!=', FetchOutcome::Ok->value);
    }

    /**
     * @return Builder<FetchAttempt>
     */
    public function prunable(): Builder
    {
        /** @var Builder<FetchAttempt> $query */
        $query = static::query()->where(
            'created_at',
            '<',
            now()->subDays((int) config('dipalert.failed_pages_retention_days', 30)),
        );

        return $query;
    }

    protected function pruning(): void
    {
        FailedPageStore::delete($this->page_path);
    }
}
