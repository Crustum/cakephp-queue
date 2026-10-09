<?php
declare(strict_types=1);

namespace Crustum\Queue\Middleware;

use Cake\Queue\Job\Message;
use Closure;
use Crustum\Queue\Contract\MiddlewareInterface;

/**
 * Release Middleware
 *
 * Conditional deferral (port of `Illuminate\Queue\Middleware\Release`): when
 * the condition matches, re-push a delayed copy and `ACK` the current one
 * without calling `$next`.
 *
 * Sync-unsafe: release in sync mode throws (see `ReleasesJobsTrait`).
 */
class Release implements MiddlewareInterface
{
    use ReleasesJobsTrait;

    /**
     * @param \Closure(object, \Cake\Queue\Job\Message): bool|bool $condition Release when truthy
     * @param int $releaseAfter Seconds before the copy becomes available
     */
    public function __construct(
        protected bool|Closure $condition,
        protected int $releaseAfter = 0,
    ) {
    }

    /**
     * Release when the condition is truthy.
     *
     * @param \Closure(object, \Cake\Queue\Job\Message): bool|bool $condition Condition
     * @param int $releaseAfter Seconds before the copy becomes available
     * @return self
     */
    public static function when(bool|Closure $condition, int $releaseAfter = 0): self
    {
        return new self($condition, $releaseAfter);
    }

    /**
     * Release unless the condition is truthy.
     *
     * @param \Closure(object, \Cake\Queue\Job\Message): bool|bool $condition Condition
     * @param int $releaseAfter Seconds before the copy becomes available
     * @return self
     */
    public static function unless(bool|Closure $condition, int $releaseAfter = 0): self
    {
        if ($condition instanceof Closure) {
            return new self(
                static fn(object $job, Message $message): bool => !$condition($job, $message),
                $releaseAfter,
            );
        }

        return new self(!$condition, $releaseAfter);
    }

    /**
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $next Next middleware / core invocation
     * @return mixed
     */
    public function handle(object $job, Message $message, callable $next): mixed
    {
        $shouldRelease = $this->condition instanceof Closure
            ? (bool)($this->condition)($job, $message)
            : $this->condition;

        if ($shouldRelease) {
            return $this->release($job, $message, $this->releaseAfter);
        }

        return $next($job, $message);
    }
}
