<?php
declare(strict_types=1);

namespace Crustum\Queue\Middleware;

use Cake\Log\Log;
use Cake\Queue\Job\Message;
use Cake\Queue\QueueManager;
use Closure;
use Crustum\Queue\Contract\MiddlewareInterface;
use Crustum\Queue\Limiter\RateLimiter;
use Interop\Queue\Processor;
use Throwable;

/**
 * Throttles Exceptions Middleware
 *
 * Cross-message failure counting with decay and backoff (port of
 * `Illuminate\Queue\Middleware\ThrottlesExceptions`, Cake naming applied).
 *
 * Flow: over budget defers; success clears the counter; an exception
 * optionally reports, delete-fasts (`REJECT`, no failed row), fail-fasts
 * (same sink as `FailOnException`), otherwise hits the counter and defers
 * with backoff. Deferral paths are sync-unsafe (see `ReleasesJobsTrait`).
 *
 * Key deviation: `useJobKey()` keys by `QueueManager::getUniqueId()` (Cake
 * messages carry no UUID).
 */
class ThrottlesExceptions implements MiddlewareInterface
{
    use ReleasesJobsTrait;

    /**
     * @var list<\Closure(\Throwable): bool> Delete-fast predicates
     */
    protected array $deleteWhenCallbacks = [];

    /**
     * @var list<\Closure(\Throwable): bool> Fail-fast predicates
     */
    protected array $failWhenCallbacks = [];

    /**
     * @var \Closure(\Throwable, \Crustum\Queue\Limiter\RateLimiter): bool|null Throttle gate
     */
    protected ?Closure $whenCallback = null;

    /**
     * @var \Closure(\Throwable, \Crustum\Queue\Limiter\RateLimiter): bool|null Report gate
     */
    protected ?Closure $reportCallback = null;

    /**
     * @var \Closure(\Throwable): int|int  Backoff in minutes
     */
    protected int|Closure $backoff = 0;

    /**
     * @var string|null Explicit throttle key
     */
    protected ?string $key = null;

    /**
     * @var bool Key by job message identity instead of job class
     */
    protected bool $keyByJob = false;

    /**
     * @var string Throttle key prefix
     */
    protected string $prefix = 'crustum-throttles-exceptions:';

    /**
     * @var \Crustum\Queue\Limiter\RateLimiter|null Injected limiter (tests, DI)
     */
    protected ?RateLimiter $limiter = null;

    /**
     * @param int $maxAttempts Failures before throttling applies
     * @param int $decaySeconds Failure window length
     * @param string $cacheConfig Cake cache config backing the counter
     */
    public function __construct(
        protected int $maxAttempts = 10,
        protected int $decaySeconds = 600,
        protected string $cacheConfig = 'default',
    ) {
    }

    /**
     * Inject the limiter (tests, DI).
     *
     * @param \Crustum\Queue\Limiter\RateLimiter $limiter Limiter
     * @return $this
     */
    public function setLimiter(RateLimiter $limiter): static
    {
        $this->limiter = $limiter;

        return $this;
    }

    /**
     * Only throttle when the predicate matches the exception.
     *
     * @param \Closure(\Throwable, \Crustum\Queue\Limiter\RateLimiter): bool $callback Gate
     * @return $this
     */
    public function setWhen(Closure $callback): static
    {
        $this->whenCallback = $callback;

        return $this;
    }

    /**
     * Delete-fast (drop, no failed row) on matching exceptions.
     *
     * @param \Closure(\Throwable): bool|string $callback Predicate or exception class
     * @return $this
     */
    public function addDeleteWhen(Closure|string $callback): static
    {
        $this->deleteWhenCallbacks[] = is_string($callback)
            ? static fn(Throwable $e): bool => $e instanceof $callback
            : $callback;

        return $this;
    }

    /**
     * Fail-fast (same sink as `FailOnException`) on matching exceptions.
     *
     * @param \Closure(\Throwable): bool|string $callback Predicate or exception class
     * @return $this
     */
    public function addFailWhen(Closure|string $callback): static
    {
        $this->failWhenCallbacks[] = is_string($callback)
            ? static fn(Throwable $e): bool => $e instanceof $callback
            : $callback;

        return $this;
    }

    /**
     * Log matching exceptions before throttling.
     *
     * @param \Closure(\Throwable, \Crustum\Queue\Limiter\RateLimiter): bool|null $callback Gate, logs everything when null
     * @return $this
     */
    public function setReport(?Closure $callback = null): static
    {
        $this->reportCallback = $callback ?? static fn(): bool => true;

        return $this;
    }

    /**
     * Key the throttle by an explicit value.
     *
     * @param string $key Throttle key
     * @return $this
     */
    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Key the throttle by job message identity instead of job class.
     *
     * @return $this
     */
    public function useJobKey(): static
    {
        $this->keyByJob = true;

        return $this;
    }

    /**
     * Set the throttle key prefix.
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
     * Set the backoff before retry, in minutes.
     *
     * @param \Closure(\Throwable): int|int $backoff Minutes or per-exception resolver
     * @return $this
     */
    public function setBackoff(int|Closure $backoff): static
    {
        $this->backoff = $backoff;

        return $this;
    }

    /**
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $next Next middleware / core invocation
     * @return mixed
     * @throws \Throwable Unmatched exceptions propagate
     */
    public function handle(object $job, Message $message, callable $next): mixed
    {
        $limiter = $this->limiter();
        $throttleKey = $this->getKey($job, $message);

        if ($limiter->isLimited($throttleKey, $this->maxAttempts)) {
            return $this->release($job, $message, $limiter->getAvailableIn($throttleKey) + 3);
        }

        try {
            $result = $next($job, $message);
            $limiter->clear($throttleKey);

            return $result;
        } catch (Throwable $throwable) {
            if ($this->whenCallback instanceof Closure && ($this->whenCallback)($throwable, $limiter) !== true) {
                throw $throwable;
            }

            if ($this->reportCallback instanceof Closure && ($this->reportCallback)($throwable, $limiter) === true) {
                Log::error($throwable->getMessage(), ['exception' => $throwable]);
            }

            if ($this->shouldDelete($throwable)) {
                return Processor::REJECT;
            }

            if ($this->shouldFail($throwable)) {
                FailOnException::failFast($message);

                throw $throwable;
            }

            $limiter->hit($throttleKey, $this->decaySeconds);

            return $this->release($job, $message, $this->getRetryDelay($throwable));
        }
    }

    /**
     * @return \Crustum\Queue\Limiter\RateLimiter
     */
    protected function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->cacheConfig);
    }

    /**
     * @param \Throwable $throwable Caught exception
     * @return bool
     */
    protected function shouldDelete(Throwable $throwable): bool
    {
        return array_any(
            $this->deleteWhenCallbacks,
            static fn(Closure $callback): bool => $callback($throwable),
        );
    }

    /**
     * @param \Throwable $throwable Caught exception
     * @return bool
     */
    protected function shouldFail(Throwable $throwable): bool
    {
        return array_any(
            $this->failWhenCallbacks,
            static fn(Closure $callback): bool => $callback($throwable),
        );
    }

    /**
     * Get the retry delay in seconds for the exception.
     *
     * @param \Throwable $throwable Caught exception
     * @return int
     */
    protected function getRetryDelay(Throwable $throwable): int
    {
        $backoff = $this->backoff instanceof Closure
            ? ($this->backoff)($throwable)
            : $this->backoff;

        return $backoff * 60;
    }

    /**
     * Get the throttle key for the job.
     *
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string
     */
    protected function getKey(object $job, Message $message): string
    {
        if ($this->key !== null) {
            return $this->prefix . $this->key;
        }

        if ($this->keyByJob) {
            [$class, $method] = $message->getTarget();

            return $this->prefix . QueueManager::getUniqueId($class, $method, (array)$message->getArgument());
        }

        return $this->prefix . md5($job::class);
    }
}
