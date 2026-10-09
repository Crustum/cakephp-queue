<?php
declare(strict_types=1);

namespace Crustum\Queue\Middleware;

use Cake\Queue\Job\Message;
use Closure;
use Crustum\Queue\Contract\MiddlewareInterface;
use Interop\Queue\Processor;

/**
 * Skip Middleware
 *
 * Guard port of Laravel `Illuminate\Queue\Middleware\Skip`: when the
 * condition matches, the job is skipped without calling `$next` and the
 * pipeline returns `ACK` (success-with-no-side-effects).
 *
 * Sync-safe: skip returns `ACK`, so `SyncJobRunner::assertSuccessful()`
 * sees success on both paths.
 */
class Skip implements MiddlewareInterface
{
    /**
     * @param \Closure(object, \Cake\Queue\Job\Message): bool|bool $condition Skip when truthy
     */
    public function __construct(
        protected bool|Closure $condition,
    ) {
    }

    /**
     * Skip when the condition is truthy.
     *
     * @param \Closure(object, \Cake\Queue\Job\Message): bool|bool $condition Condition
     * @return self
     */
    public static function when(bool|Closure $condition): self
    {
        return new self($condition);
    }

    /**
     * Skip unless the condition is truthy.
     *
     * @param \Closure(object, \Cake\Queue\Job\Message): bool|bool $condition Condition
     * @return self
     */
    public static function unless(bool|Closure $condition): self
    {
        if ($condition instanceof Closure) {
            return new self(
                static fn(object $job, Message $message): bool => !$condition($job, $message),
            );
        }

        return new self(!$condition);
    }

    /**
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $next Next middleware / core invocation
     * @return mixed
     */
    public function handle(object $job, Message $message, callable $next): mixed
    {
        $shouldSkip = $this->condition instanceof Closure
            ? (bool)($this->condition)($job, $message)
            : $this->condition;

        if ($shouldSkip) {
            return Processor::ACK;
        }

        return $next($job, $message);
    }
}
