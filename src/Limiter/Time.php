<?php
declare(strict_types=1);

namespace Crustum\Queue\Limiter;

use Cake\I18n\DateTime;
use DateInterval;
use DateTimeInterface;

/**
 * Time
 *
 * Normalizes `int|DateInterval|DateTimeInterface` delays to unix timestamps
 * and second counts (decay windows, TTLs, release delays).
 *
 * Built on `Cake\I18n\DateTime`, so the clock freezes with
 * `DateTime::setTestNow()` in tests — no `sleep()` needed for decay windows.
 */
class Time
{
    /**
     * Current unix timestamp (freezable via `DateTime::setTestNow()`).
     *
     * @return int
     */
    public static function now(): int
    {
        return DateTime::now()->getTimestamp();
    }

    /**
     * Get the unix timestamp a delay elapses at.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay Delay in seconds, interval, or point in time
     * @return int
     */
    public static function availableAt(DateTimeInterface|DateInterval|int $delay = 0): int
    {
        if ($delay instanceof DateTimeInterface) {
            return $delay->getTimestamp();
        }

        if ($delay instanceof DateInterval) {
            return DateTime::now()->add($delay)->getTimestamp();
        }

        return static::now() + $delay;
    }

    /**
     * Get the number of seconds until a delay elapses.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay Delay in seconds, interval, or point in time
     * @return int
     */
    public static function secondsUntil(DateTimeInterface|DateInterval|int $delay): int
    {
        return static::availableAt($delay) - static::now();
    }
}
