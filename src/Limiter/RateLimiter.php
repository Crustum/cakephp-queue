<?php
declare(strict_types=1);

namespace Crustum\Queue\Limiter;

use Cake\Cache\Cache;
use Cake\Queue\Job\Message;
use Closure;
use DateInterval;
use DateTimeInterface;

/**
 * Rate Limiter
 *
 * Attempt counter with decay windows over Cake Cache (port of
 * `Illuminate\Cache\RateLimiter`, Cake naming applied).
 *
 * Backend requirement: atomic `increment` — Redis, Memcached or APCu.
 * The File engine is read-modify-write and local-dev only (same rule as
 * the queue `uniqueCache`). Timer TTLs come from the cache config
 * `duration`, so it must cover the longest decay used.
 */
class RateLimiter
{
    /**
     * Named limiter resolvers.
     *
     * @var array<string, \Closure>
     */
    protected array $limiters = [];

    /**
     * @param string $cacheConfig Cake cache config name backing the counters
     */
    public function __construct(
        protected string $cacheConfig = 'default',
    ) {
    }

    /**
     * Register a named limiter resolver.
     *
     * The resolver receives the job and the message and returns a `Limit`,
     * an `Unlimited`, or a list of `Limit` definitions.
     *
     * @param string $name Limiter name
     * @param \Closure $resolver Resolver returning the limit definition
     * @return $this
     */
    public function setLimiter(string $name, Closure $resolver): static
    {
        $this->limiters[$name] = $resolver;

        return $this;
    }

    /**
     * Get a named limiter resolver (duplicate limit keys get fallback keys).
     *
     * @param string $name Limiter name
     * @return \Closure|null
     */
    public function getLimiter(string $name): ?Closure
    {
        $resolver = $this->limiters[$name] ?? null;
        if ($resolver === null) {
            return null;
        }

        return static function (object $job, Message $message) use ($resolver): mixed {
            $result = $resolver($job, $message);
            if (!is_array($result)) {
                return $result;
            }

            $counts = [];
            foreach ($result as $limit) {
                if ($limit instanceof Limit) {
                    $key = (string)$limit->getKey();
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }

            foreach ($result as $limit) {
                if ($limit instanceof Limit && ($counts[(string)$limit->getKey()] ?? 0) > 1) {
                    $limit->setKey($limit->getFallbackKey());
                }
            }

            return $result;
        };
    }

    /**
     * Check whether a named limiter is registered.
     *
     * @param string $name Limiter name
     * @return bool
     */
    public function hasLimiter(string $name): bool
    {
        return isset($this->limiters[$name]);
    }

    /**
     * Run a callback unless the key is limited.
     *
     * @param string $key Counter key
     * @param int $maxAttempts Maximum attempts before limiting applies
     * @param \Closure $callback Work to run
     * @param \DateTimeInterface|\DateInterval|int $decaySeconds Window length
     * @return mixed `false` when limited, callback result otherwise
     */
    public function attempt(
        string $key,
        int $maxAttempts,
        Closure $callback,
        DateTimeInterface|DateInterval|int $decaySeconds = 60,
    ): mixed {
        if ($this->isLimited($key, $maxAttempts)) {
            return false;
        }

        $result = $callback();
        $result ??= true;

        $this->hit($key, $decaySeconds);

        return $result;
    }

    /**
     * Check whether the key reached the attempt budget within its window.
     *
     * @param string $key Counter key
     * @param int $maxAttempts Maximum attempts before limiting applies
     * @return bool
     */
    public function isLimited(string $key, int $maxAttempts): bool
    {
        $cleaned = $this->cleanKey($key);

        if ((int)Cache::read($cleaned, $this->cacheConfig) >= $maxAttempts) {
            $resetAt = Cache::read($cleaned . ':timer', $this->cacheConfig);
            if (is_numeric($resetAt) && (int)$resetAt > Time::now()) {
                return true;
            }

            $this->resetAttempts($key);
        }

        return false;
    }

    /**
     * Record one hit for the key.
     *
     * @param string $key Counter key
     * @param \DateTimeInterface|\DateInterval|int $decaySeconds Window length
     * @return int Attempt count after the hit
     */
    public function hit(string $key, DateTimeInterface|DateInterval|int $decaySeconds = 60): int
    {
        return $this->increment($key, $decaySeconds);
    }

    /**
     * Increment the counter for the key by the given amount.
     *
     * @param string $key Counter key
     * @param \DateTimeInterface|\DateInterval|int $decaySeconds Window length
     * @param int $amount Increment step (negative decrements)
     * @return int Attempt count after the increment
     */
    public function increment(
        string $key,
        DateTimeInterface|DateInterval|int $decaySeconds = 60,
        int $amount = 1,
    ): int {
        $cleaned = $this->cleanKey($key);
        $decay = Time::secondsUntil($decaySeconds);

        Cache::add($cleaned . ':timer', Time::availableAt($decay), $this->cacheConfig);
        $added = Cache::add($cleaned, 0, $this->cacheConfig);

        $hits = (int)Cache::increment($cleaned, $amount, $this->cacheConfig);
        if (!$added && $hits === $amount) {
            Cache::write($cleaned, $amount, $this->cacheConfig);
        }

        return $hits;
    }

    /**
     * Decrement the counter for the key by the given amount.
     *
     * Read-modify-write (some engines reject negative `increment` offsets),
     * so prefer Redis/Memcached under concurrency.
     *
     * @param string $key Counter key
     * @param \DateTimeInterface|\DateInterval|int $decaySeconds Window length (unused, kept for symmetry)
     * @param int $amount Decrement step
     * @return int Attempt count after the decrement
     */
    public function decrement(
        string $key,
        DateTimeInterface|DateInterval|int $decaySeconds = 60,
        int $amount = 1,
    ): int {
        $cleaned = $this->cleanKey($key);
        $count = max(0, (int)Cache::read($cleaned, $this->cacheConfig) - $amount);
        Cache::write($cleaned, $count, $this->cacheConfig);

        return $count;
    }

    /**
     * Get the attempt count for the key.
     *
     * @param string $key Counter key
     * @return int
     */
    public function getAttempts(string $key): int
    {
        return (int)Cache::read($this->cleanKey($key), $this->cacheConfig);
    }

    /**
     * Reset the attempt count for the key.
     *
     * @param string $key Counter key
     * @return bool
     */
    public function resetAttempts(string $key): bool
    {
        return Cache::delete($this->cleanKey($key), $this->cacheConfig);
    }

    /**
     * Get the attempts left for the key.
     *
     * @param string $key Counter key
     * @param int $maxAttempts Maximum attempts before limiting applies
     * @return int
     */
    public function getRemaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->getAttempts($key));
    }

    /**
     * Clear the hits and the window timer for the key.
     *
     * @param string $key Counter key
     * @return void
     */
    public function clear(string $key): void
    {
        $cleaned = $this->cleanKey($key);

        Cache::delete($cleaned, $this->cacheConfig);
        Cache::delete($cleaned . ':timer', $this->cacheConfig);
    }

    /**
     * Get the seconds until the key is accessible again.
     *
     * @param string $key Counter key
     * @return int
     */
    public function getAvailableIn(string $key): int
    {
        $resetAt = Cache::read($this->cleanKey($key) . ':timer', $this->cacheConfig);
        if (!is_numeric($resetAt)) {
            return 0;
        }

        return max(0, (int)$resetAt - Time::now());
    }

    /**
     * Strip unicode entities from a counter key.
     *
     * @param string $key Counter key
     * @return string
     */
    protected function cleanKey(string $key): string
    {
        return (string)preg_replace('/&([a-z])[a-z]+;/i', '$1', htmlentities($key));
    }
}
