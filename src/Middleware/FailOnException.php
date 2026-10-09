<?php
declare(strict_types=1);

namespace Crustum\Queue\Middleware;

use Cake\Queue\Consumption\LimitAttemptsExtension;
use Cake\Queue\Job\Message;
use Closure;
use Crustum\Queue\Contract\MiddlewareInterface;
use Throwable;

/**
 * Fail On Exception Middleware
 *
 * Failure policy (port of `Illuminate\Queue\Middleware\FailOnException`):
 * exceptions matching the callback fail immediately instead of burning the
 * remaining retry budget.
 *
 * Fail-fast reuses the existing sink: the message `attempts` are jumped to
 * the job `static::$maxAttempts`, so `LimitAttemptsExtension` converts the
 * requeue into a reject and emits the regular `failed` event — the row lands
 * in the same `failed_jobs` table. Without a declared `$maxAttempts` the
 * exception rethrows untouched and retries to the worker-level budget.
 *
 * Sync-safe: the bump is inert in-process, the caller sees the original
 * exception via `SyncJobRunner`.
 */
class FailOnException implements MiddlewareInterface
{
    /**
     * @var \Closure(\Throwable, object): bool Truth test for fail-fast
     */
    protected Closure $callback;

    /**
     * @param \Closure(\Throwable, object): bool|list<class-string<\Throwable>> $callback Truth test or exception list
     */
    public function __construct(Closure|array $callback)
    {
        if (is_array($callback)) {
            $exceptions = $callback;
            $callback = static fn(Throwable $throwable): bool => array_any(
                $exceptions,
                static fn(string $exception): bool => $throwable instanceof $exception,
            );
        }

        $this->callback = $callback;
    }

    /**
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $next Next middleware / core invocation
     * @return mixed
     * @throws \Throwable Unmatched exceptions and matched ones (after the fail-fast mark) propagate
     */
    public function handle(object $job, Message $message, callable $next): mixed
    {
        try {
            return $next($job, $message);
        } catch (Throwable $throwable) {
            if (($this->callback)($throwable, $job) === true) {
                static::failFast($message);
            }

            throw $throwable;
        }
    }

    /**
     * Mark the message to fail on this attempt (shared with throttling fail-fast).
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return void
     */
    public static function failFast(Message $message): void
    {
        $maxAttempts = $message->getMaxAttempts();
        if ($maxAttempts !== null) {
            $message->getOriginalMessage()->setProperty(
                LimitAttemptsExtension::ATTEMPTS_PROPERTY,
                $maxAttempts,
            );
        }
    }
}
