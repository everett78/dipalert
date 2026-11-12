<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Uri;

/**
 * Pacing state for one store host.
 *
 * @property int $id
 * @property string $host
 * @property ?Carbon $last_fetch_at
 * @property ?Carbon $paused_until
 * @property int $pause_level
 */
class FetchPacing extends Model
{
    protected $table = 'fetch_pacing';

    protected $fillable = [
        'host',
        'last_fetch_at',
        'paused_until',
        'pause_level',
    ];

    protected $casts = [
        'last_fetch_at' => 'datetime',
        'paused_until' => 'datetime',
        'pause_level' => 'integer',
    ];

    /**
     * Pacing key for an address: host in lower case, without port and "www.".
     */
    public static function hostFromUrl(string $url): string
    {
        $host = strtolower((string) Uri::of($url)->host());

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public static function forHost(string $host): self
    {
        /** @var self */
        return static::query()->firstOrCreate(['host' => $host]);
    }

    /**
     * Lift the pause and reset the pause level ("Zdejmij przerwę").
     */
    public static function resume(string $host): void
    {
        static::query()
            ->where('host', $host)
            ->update(['paused_until' => null, 'pause_level' => 0]);
    }

    /**
     * Hosts paused right now.
     *
     * @return Collection<int, self>
     */
    public static function pausedHosts(): Collection
    {
        /** @var Collection<int, self> $hosts */
        $hosts = static::query()
            ->where('paused_until', '>', now())
            ->orderBy('paused_until')
            ->get();

        return $hosts;
    }

    /**
     * When the host is paused, the end of the pause; otherwise null.
     */
    public static function pausedUntil(string $host): ?Carbon
    {
        $pausedUntil = static::query()->where('host', $host)->value('paused_until');

        if ($pausedUntil === null) {
            return null;
        }

        $pausedUntil = Carbon::parse($pausedUntil);

        return $pausedUntil->isFuture() ? $pausedUntil : null;
    }

    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }
}
