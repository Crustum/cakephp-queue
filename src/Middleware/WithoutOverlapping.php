<?php
declare(strict_types=1);

namespace Crustum\Queue\Middleware;

use Cake\Lock\AcquiredLock;
use Cake\Lock\Lock;
use Cake\Queue\Job\Message;
use Crustum\Queue\Contract\MiddlewareInterface;
use Crustum\Queue\Limiter\Time;
use DateInterval;
use DateTimeInterface;
use Interop\Queue\Processor;

/**
 * Without Overlapping Middleware
 *
 * Execution-time mutex (port of `Illuminate\Queue\Middleware\WithoutOverlapping`,
 * Cake naming applied): first holder runs, contention re-pushes with delay.
 *
 * Locking runs on Cake Lock (`Lock::acquire()`, owner-verified release) —
 * configure a non-file backend (Redis, Memcached) for distributed workers;
 * the File engine is single-server and has no TTL expiry. Lock keys reuse the
 * `QueueManager::getUniqueId()` spirit: `md5` of the job class (xxh128 from
 * the original is not guaranteed across PHP builds).
 */
class WithoutOverlapping implements MiddlewareInterface
{
    use ReleasesJobsTrait;

    /**
     * @var string Overlap key (empty scopes per job class unless shared)
     */
    protected string $key;

    /**
     * @var int|null Seconds to defer on contention (`null` drops instead)
     */
    protected ?int $releaseAfter;

    /**
     * @var int Lock TTL in seconds (`0` uses the engine default)
     */
    protected int $expiresAfter;

    /**
     * @var string Lock key prefix
     */
    protected string $prefix = 'crustum-queue-overlap:';

    /**
     * @var bool Share the key across job classes
     */
    protected bool $shared = false;

    /**
     * @var string Cake lock config name
     */
    protected string $lockConfig;

    /**
     * @param string $key Overlap key
     * @param \DateTimeInterface|\DateInterval|int|null $releaseAfter Deferral on contention (`null` drops)
     * @param \DateTimeInterface|\DateInterval|int $expiresAfter Lock TTL
     * @param string $lockConfig Cake lock config name
     */
    public function __construct(
        string $key = '',
        DateTimeInterface|DateInterval|int|null $releaseAfter = 0,
        DateTimeInterface|DateInterval|int $expiresAfter = 0,
        string $lockConfig = 'default',
    ) {
        $this->key = $key;
        $this->releaseAfter = $releaseAfter === null ? null : Time::secondsUntil($releaseAfter);
        $this->expiresAfter = Time::secondsUntil($expiresAfter);
        $this->lockConfig = $lockConfig;
    }

    /**
     * Set the deferral on contention.
     *
     * @param \DateTimeInterface|\DateInterval|int $releaseAfter Deferral
     * @return $this
     */
    public function setReleaseAfter(DateTimeInterface|DateInterval|int $releaseAfter): static
    {
        $this->releaseAfter = Time::secondsUntil($releaseAfter);

        return $this;
    }

    /**
     * Drop the job on contention instead of deferring it.
     *
     * @return $this
     */
    public function disableRelease(): static
    {
        $this->releaseAfter = null;

        return $this;
    }

    /**
     * Set the lock TTL.
     *
     * @param \DateTimeInterface|\DateInterval|int $expiresAfter Lock TTL
     * @return $this
     */
    public function setExpireAfter(DateTimeInterface|DateInterval|int $expiresAfter): static
    {
        $this->expiresAfter = Time::secondsUntil($expiresAfter);

        return $this;
    }

    /**
     * Set the lock key prefix.
     *
     * @param string $prefix Prefix
     * @return $this
     */
    public function setPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Share the key across job classes.
     *
     * @param bool $shared Share the key
     * @return $this
     */
    public function setShared(bool $shared = true): static
    {
        $this->shared = $shared;

        return $this;
    }

    /**
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $next Next middleware / core invocation
     * @return mixed
     */
    public function handle(object $job, Message $message, callable $next): mixed
    {
        $lock = Lock::acquire(
            $this->getLockKey($job),
            $this->expiresAfter > 0 ? $this->expiresAfter : null,
            $this->lockConfig,
        );

        if ($lock instanceof AcquiredLock) {
            try {
                return $next($job, $message);
            } finally {
                $lock->release();
            }
        }

        if ($this->releaseAfter !== null) {
            return $this->release($job, $message, $this->releaseAfter);
        }

        return Processor::ACK;
    }

    /**
     * Get the lock key for the job.
     *
     * @param object $job Job instance
     * @return string
     */
    public function getLockKey(object $job): string
    {
        if ($this->shared) {
            return $this->prefix . $this->key;
        }

        return $this->prefix . md5($job::class) . ':' . $this->key;
    }
}
