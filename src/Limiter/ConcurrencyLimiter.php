<?php
declare(strict_types=1);

namespace Crustum\Queue\Limiter;

use Cake\Lock\AcquiredLock;
use Cake\Lock\Lock;
use Closure;

/**
 * Concurrency Limiter
 *
 * Slot-based concurrency cap over Cake Lock (port of
 * `Illuminate\Cache\Limiters\ConcurrencyLimiter`).
 *
 * Up to `$maxLocks` holders may own a slot (`$name.$i`) at once; slots carry
 * the `$releaseAfter` TTL so crashed holders stop blocking after expiry.
 */
class ConcurrencyLimiter
{
    /**
     * @param string $lockConfig Cake lock config name
     * @param string $name Limiter name (slots are `$name.$i`)
     * @param int $maxLocks Maximum concurrent slot holders
     * @param int $releaseAfter Slot TTL in seconds
     */
    public function __construct(
        protected string $lockConfig,
        protected string $name,
        protected int $maxLocks,
        protected int $releaseAfter,
    ) {
    }

    /**
     * Run the callback inside a slot, waiting up to the timeout.
     *
     * @param int $timeout Seconds to wait for a free slot
     * @param \Closure|null $callback Work to run inside the slot
     * @param int $sleep Milliseconds between acquisition attempts
     * @return mixed `true` without callback, callback result otherwise
     * @throws \Crustum\Queue\Limiter\LimiterTimeoutException When no slot frees up in time
     * @throws \Throwable Rethrows callback exceptions after releasing the slot
     */
    public function block(int $timeout, ?Closure $callback = null, int $sleep = 250): mixed
    {
        $started = Time::now();

        while (!($slot = $this->acquireSlot()) instanceof AcquiredLock) {
            if (Time::now() - $started >= $timeout) {
                throw new LimiterTimeoutException(
                    sprintf('No concurrency slot freed for `%s` within %d seconds.', $this->name, $timeout),
                );
            }

            usleep($sleep * 1000);
        }

        if (!$callback instanceof Closure) {
            return true;
        }

        try {
            return $callback();
        } finally {
            $this->releaseSlot($slot);
        }
    }

    /**
     * Take the first free slot, if any.
     *
     * @return \Cake\Lock\AcquiredLock|null
     */
    protected function acquireSlot(): ?AcquiredLock
    {
        for ($i = 1; $i <= $this->maxLocks; $i++) {
            $lock = Lock::acquire($this->name . $i, $this->releaseAfter, $this->lockConfig);
            if ($lock instanceof AcquiredLock) {
                return $lock;
            }
        }

        return null;
    }

    /**
     * Release a held slot.
     *
     * @param \Cake\Lock\AcquiredLock $slot Held slot
     * @return void
     */
    protected function releaseSlot(AcquiredLock $slot): void
    {
        $slot->release();
    }
}
