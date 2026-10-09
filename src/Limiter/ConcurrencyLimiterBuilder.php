<?php
declare(strict_types=1);

namespace Crustum\Queue\Limiter;

use Closure;
use DateInterval;
use DateTimeInterface;

/**
 * Concurrency Limiter Builder
 *
 * Fluent factory for `ConcurrencyLimiter` (port of
 * `Illuminate\Cache\Limiters\ConcurrencyLimiterBuilder`).
 */
class ConcurrencyLimiterBuilder
{
    /**
     * @var int Slot TTL in seconds
     */
    protected int $releaseAfter = 60;

    /**
     * @var int Seconds to wait for a free slot
     */
    protected int $timeout = 3;

    /**
     * @var int Milliseconds between acquisition attempts
     */
    protected int $sleep = 250;

    /**
     * @var int Maximum concurrent slot holders
     */
    protected int $maxLocks = 1;

    /**
     * @param string $lockConfig Cake lock config name
     * @param string $name Limiter name
     */
    public function __construct(
        protected string $lockConfig,
        protected string $name,
    ) {
    }

    /**
     * Set the maximum concurrent slot holders.
     *
     * @param int $maxLocks Maximum concurrent slot holders
     * @return $this
     */
    public function setMaxLocks(int $maxLocks): static
    {
        $this->maxLocks = $maxLocks;

        return $this;
    }

    /**
     * Set the slot TTL.
     *
     * @param \DateTimeInterface|\DateInterval|int $releaseAfter Slot TTL
     * @return $this
     */
    public function setReleaseAfter(DateTimeInterface|DateInterval|int $releaseAfter): static
    {
        $this->releaseAfter = Time::secondsUntil($releaseAfter);

        return $this;
    }

    /**
     * Set the seconds to wait for a free slot.
     *
     * @param int $timeout Seconds to wait
     * @return $this
     */
    public function setTimeout(int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Set the milliseconds between acquisition attempts.
     *
     * @param int $sleep Milliseconds to wait
     * @return $this
     */
    public function setSleep(int $sleep): static
    {
        $this->sleep = $sleep;

        return $this;
    }

    /**
     * Run the callback inside a slot, or the failure callback on timeout.
     *
     * @param \Closure $callback Work to run inside the slot
     * @param \Closure|null $failure Timeout fallback
     * @return mixed
     * @throws \Crustum\Queue\Limiter\LimiterTimeoutException Without failure callback
     */
    public function then(Closure $callback, ?Closure $failure = null): mixed
    {
        try {
            return $this->createLimiter()->block($this->timeout, $callback, $this->sleep);
        } catch (LimiterTimeoutException $limiterTimeoutException) {
            if ($failure instanceof Closure) {
                return $failure($limiterTimeoutException);
            }

            throw $limiterTimeoutException;
        }
    }

    /**
     * @return \Crustum\Queue\Limiter\ConcurrencyLimiter
     */
    protected function createLimiter(): ConcurrencyLimiter
    {
        return new ConcurrencyLimiter(
            $this->lockConfig,
            $this->name,
            $this->maxLocks,
            $this->releaseAfter,
        );
    }
}
