<?php

namespace App\Services;

use App\Enums\FetchOutcome;
use App\Enums\FetchSource;
use App\Models\FetchAttempt;
use App\Models\FetchPacing;
use App\Models\Url;
use App\Services\Helpers\SettingsHelper;
use App\Services\Security\UrlGuard;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Builds the daily status e-mail: fetch attempts of the last 24 hours, runs
 * that should have started, paused stores, the queue, the last backup and
 * FlareSolverr.
 */
class DailyStatusReport
{
    public const string SUBJECT_FAILURE = 'DipAlert: AWARIA';

    public const string SUBJECT_OK = 'DipAlert: OK';

    /**
     * Runs younger than this are shown as "in progress", not judged.
     */
    public const int RUN_GRACE_HOURS = 2;

    public const string RUN_OK = 'ok';

    public const string RUN_NOT_STARTED = 'not_started';

    public const string RUN_NOT_FINISHED = 'not_finished';

    public const string RUN_IN_PROGRESS = 'in_progress';

    protected Carbon $now;

    protected Carbon $from;

    public function __construct(?CarbonInterface $now = null)
    {
        $this->now = Carbon::instance($now ?? now());
        $this->from = $this->now->copy()->subDay();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $attempts = FetchAttempt::query()
            ->where('created_at', '>=', $this->from)
            ->where('created_at', '<=', $this->now)
            ->get(['id', 'url_id', 'store_id', 'outcome', 'source', 'created_at']);

        $problems = $this->problemLinks($attempts);
        $paused = FetchPacing::pausedHosts();
        $runs = $this->runs();
        $flareSolverr = $this->flareSolverr();

        $runFailure = collect($runs)->contains(fn (array $run): bool => in_array($run['status'], [self::RUN_NOT_STARTED, self::RUN_NOT_FINISHED], true));
        $issueCount = $problems->count() + $paused->count();

        $subject = match (true) {
            $runFailure || $flareSolverr['status'] === 'down' => self::SUBJECT_FAILURE,
            $issueCount > 0 => 'DipAlert: uwagi ('.$issueCount.')',
            default => self::SUBJECT_OK,
        };

        return [
            'subject' => $subject,
            'from' => $this->from,
            'to' => $this->now,
            'totals' => $this->totals($attempts),
            'runs' => $runs,
            'problems' => $problems->where('outcome', '!=', FetchOutcome::Skipped)
                ->groupBy(fn (array $problem): string => $problem['outcome']->getLabel())
                ->all(),
            'skippedStores' => $problems->where('outcome', FetchOutcome::Skipped)
                ->groupBy('store')
                ->map(fn (Collection $links): int => $links->count())
                ->all(),
            'fallbackOnly' => $this->fallbackOnly(),
            'paused' => $paused->map(fn (FetchPacing $pacing): array => [
                'host' => $pacing->host,
                'until' => $pacing->paused_until,
            ])->all(),
            'issueCount' => $issueCount,
            'oldestQueuedJob' => $this->oldestQueuedJobAge(),
            'backup' => $this->lastBackup(),
            'flareSolverr' => $flareSolverr,
            'version' => (string) config('app.version', 'development'),
        ];
    }

    /**
     * Number of attempts by outcome and by source.
     *
     * @param  Collection<int, FetchAttempt>  $attempts
     * @return array{all: int, by_outcome: array<string, int>, by_source: array<string, int>}
     */
    protected function totals(Collection $attempts): array
    {
        return [
            'all' => $attempts->count(),
            'by_outcome' => collect(FetchOutcome::cases())
                ->mapWithKeys(fn (FetchOutcome $outcome): array => [$outcome->getLabel() => $attempts->where('outcome', $outcome)->count()])
                ->filter()
                ->all(),
            'by_source' => collect(FetchSource::cases())
                ->mapWithKeys(fn (FetchSource $source): array => [$source->value => $attempts->where('source', $source)->count()])
                ->filter()
                ->all(),
        ];
    }

    /**
     * Links whose last attempt in the window was not "ok".
     *
     * @param  Collection<int, FetchAttempt>  $attempts
     */
    protected function problemLinks(Collection $attempts): Collection
    {
        $last = $attempts->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->keyBy('url_id')
            ->filter(fn (FetchAttempt $attempt): bool => $attempt->outcome !== FetchOutcome::Ok);

        if ($last->isEmpty()) {
            return collect();
        }

        $attemptsWithLinks = FetchAttempt::query()
            ->whereIn('id', $last->pluck('id'))
            ->with(['url.product', 'store'])
            ->get();

        return $attemptsWithLinks
            ->filter(fn (FetchAttempt $attempt): bool => $attempt->url !== null)
            ->map(fn (FetchAttempt $attempt): array => [
                'url_id' => $attempt->url_id,
                'outcome' => $attempt->outcome,
                'product' => (string) ($attempt->url?->product->title ?? '—'),
                'store' => (string) ($attempt->store->name ?? FetchPacing::hostFromUrl((string) $attempt->url?->url)),
                'store_logo' => $attempt->store?->logoFilePath(),
                'url' => (string) $attempt->url?->url,
            ])
            ->values();
    }

    /**
     * "Działa tylko zapas": offer lines that are visible because the direct
     * link to their store failed on its last read.
     *
     * @return list<array{product: string, store: string}>
     */
    protected function fallbackOnly(): array
    {
        $children = Url::query()
            ->whereNotNull('parent_url_id')
            ->where('last_outcome', FetchOutcome::Ok->value)
            ->with(['product', 'store'])
            ->get();

        $rows = [];

        foreach ($children as $child) {
            $directFailed = Url::query()
                ->where('product_id', $child->product_id)
                ->whereNull('parent_url_id')
                ->where('store_id', $child->store_id)
                ->whereNotNull('last_outcome')
                ->whereNotIn('last_outcome', [FetchOutcome::Ok->value, FetchOutcome::Skipped->value])
                ->exists();

            if ($directFailed) {
                $rows[] = [
                    'product' => (string) ($child->product->title ?? '—'),
                    'store' => (string) ($child->store->name ?? '—'),
                ];
            }
        }

        return $rows;
    }

    /**
     * Each scheduled run start in the window. Runs at least 2 hours old are
     * judged: the batch must exist (started) and have finished_at (finished).
     *
     * @return list<array{name: string, at: Carbon, status: string, total: ?int, failed: ?int}>
     */
    public function runs(): array
    {
        $runs = [];

        foreach ($this->scheduledStarts() as $start) {
            $name = PriceFetcherService::runName($start);
            $batch = DB::table('job_batches')->where('name', $name)->orderByDesc('created_at')->first();
            $judged = $start->lessThanOrEqualTo($this->now->copy()->subHours(self::RUN_GRACE_HOURS));

            $status = match (true) {
                ! $judged => self::RUN_IN_PROGRESS,
                $batch === null => self::RUN_NOT_STARTED,
                $batch->finished_at === null => self::RUN_NOT_FINISHED,
                default => self::RUN_OK,
            };

            $runs[] = [
                'name' => $name,
                'at' => $start,
                'status' => $status,
                'total' => $batch !== null ? (int) $batch->total_jobs : null,
                'failed' => $batch !== null ? (int) $batch->failed_jobs : null,
            ];
        }

        return $runs;
    }

    /**
     * Start times from scrape_schedule in the last 24 hours.
     *
     * @return list<Carbon>
     */
    protected function scheduledStarts(): array
    {
        $expression = (string) SettingsHelper::getSetting('scrape_schedule', '0 6 * * *');
        $timezone = $this->now->getTimezone()->getName();

        try {
            $cron = new CronExpression($expression);
        } catch (Throwable) {
            return [];
        }

        $starts = [];
        $cursor = $this->from->copy();

        // Enough steps for a schedule every minute is not needed; 200 runs a day is plenty.
        for ($i = 0; $i < 200; $i++) {
            $next = Carbon::instance($cron->getNextRunDate($cursor->toDateTimeImmutable(), 0, $i === 0, $timezone))
                ->setTimezone($timezone);

            if ($next->greaterThan($this->now)) {
                break;
            }

            $starts[] = $next;
            $cursor = $next->copy();
        }

        return $starts;
    }

    /**
     * Age of the oldest job that could already run (not reserved, available
     * now), in minutes. Delayed jobs do not count until they are due.
     */
    protected function oldestQueuedJobAge(): ?int
    {
        try {
            if (! Schema::hasTable('jobs')) {
                return null;
            }

            $availableAt = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $this->now->getTimestamp())
                ->min('available_at');
        } catch (Throwable) {
            return null;
        }

        return $availableAt === null
            ? null
            : (int) floor(($this->now->getTimestamp() - (int) $availableAt) / 60);
    }

    /**
     * @return array{at: ?Carbon, size: ?int}
     */
    protected function lastBackup(): array
    {
        $files = File::glob(rtrim((string) config('dipalert.backup_path'), '/').'/dipalert-*.sql.gz') ?: [];

        if ($files === []) {
            return ['at' => null, 'size' => null];
        }

        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return [
            'at' => Carbon::createFromTimestamp((int) filemtime($files[0]))->setTimezone($this->now->getTimezone()),
            'size' => (int) filesize($files[0]),
        ];
    }

    /**
     * FlareSolverr answers GET / within 5 seconds.
     *
     * @return array{status: string, message: ?string}
     */
    protected function flareSolverr(): array
    {
        $base = trim((string) config('dipalert.flaresolverr_url', ''));

        if ($base === '') {
            return ['status' => 'not_configured', 'message' => null];
        }

        try {
            UrlGuard::new()->assertServiceAllowed($base);
            $response = Http::timeout(5)->connectTimeout(5)->get(rtrim($base, '/').'/');
        } catch (Throwable $e) {
            return ['status' => 'down', 'message' => $e->getMessage()];
        }

        return $response->successful()
            ? ['status' => 'up', 'message' => null]
            : ['status' => 'down', 'message' => 'HTTP '.$response->status()];
    }
}
