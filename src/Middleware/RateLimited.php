<?php
declare(strict_types=1);

namespace Crustum\Queue\Middleware;

use Cake\Queue\Job\Message;
use Closure;
use Crustum\Queue\Contract\MiddlewareInterface;
use Crustum\Queue\Limiter\Limit;
use Crustum\Queue\Limiter\RateLimiter;
use Crustum\Queue\Limiter\Time;
use Crustum\Queue\Limiter\Unlimited;
use DateInterval;
use DateTimeInterface;
use Interop\Queue\Processor;
use InvalidArgumentException;

/**
 * Rate Limited Middleware
 *
 * Named-limiter execution gate (port of `Illuminate\Queue\Middleware\RateLimited`,
 * Cake naming applied): over budget defers with delay, under budget hits every
 * limit and runs. Unregistered limiter names and `Unlimited` pass through.
 *
 * No serialization pair (`__sleep`/`__wakeup`): jobs travel as
 * `[class, method]` plus args, middleware is built at consumption time, and
 * the limiter re-resolves from the cache config name. Deferral paths are
 * sync-unsafe (see `ReleasesJobsTrait`). A `dontRelease` drop maps to `ACK`
 * (Cake has no separate delete signal at middleware level).
 */
class RateLimited implements MiddlewareInterface
{
    use ReleasesJobsTrait;

    /**
     * @var int|null Explicit deferral in seconds (`null` uses limiter availability)
     */
    protected ?int $releaseAfter = null;

    /**
     * @var bool Defer on exceeded limit (`false` drops instead)
     */
    protected bool $shouldRelease = true;

    /**
     * @var \Crustum\Queue\Limiter\RateLimiter|null Injected limiter (tests, DI)
     */
    protected ?RateLimiter $limiter = null;

    /**
     * @param string $limiterName Named limiter (see `RateLimiter::setLimiter()`)
     * @param string $cacheConfig Cake cache config backing the counters
     */
    public function __construct(
        protected string $limiterName,
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
     * Set the deferral on exceeded limit.
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
     * Drop the job on exceeded limit instead of deferring it.
     *
     * @return $this
     */
    public function disableRelease(): static
    {
        $this->shouldRelease = false;

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
        $limiter = $this->limiter();
        $resolver = $limiter->getLimiter($this->limiterName);
        if (!$resolver instanceof Closure) {
            return $next($job, $message);
        }

        $response = $resolver($job, $message);
        if ($response instanceof Unlimited) {
            return $next($job, $message);
        }

        $limits = is_array($response) ? array_values($response) : [$response];
        foreach ($limits as $limit) {
            if (!$limit instanceof Limit) {
                throw new InvalidArgumentException(sprintf(
                    'Limiter `%s` must resolve to %s, %s, or a list of %s; got `%s`.',
                    $this->limiterName,
                    Limit::class,
                    Unlimited::class,
                    Limit::class,
                    get_debug_type($limit),
                ));
            }
        }

        return $this->handleLimits($job, $message, $next, $limits);
    }

    /**
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $next Next middleware / core invocation
     * @param list<\Crustum\Queue\Limiter\Limit> $limits Resolved limits
     * @return mixed
     */
    protected function handleLimits(object $job, Message $message, callable $next, array $limits): mixed
    {
        $limiter = $this->limiter();

        foreach ($limits as $limit) {
            $key = $this->counterKey($limit);
            if ($limiter->isLimited($key, $limit->getMaxAttempts())) {
                if (!$this->shouldRelease) {
                    return Processor::ACK;
                }

                return $this->release(
                    $job,
                    $message,
                    $this->releaseAfter ?? $limiter->getAvailableIn($key) + 3,
                );
            }
        }

        foreach ($limits as $limit) {
            $limiter->hit($this->counterKey($limit), $limit->getDecaySeconds());
        }

        return $next($job, $message);
    }

    /**
     * @return \Crustum\Queue\Limiter\RateLimiter
     */
    protected function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->cacheConfig);
    }

    /**
     * @param \Crustum\Queue\Limiter\Limit $limit Limit definition
     * @return string
     */
    protected function counterKey(Limit $limit): string
    {
        return md5($this->limiterName . $limit->getKey());
    }
}
