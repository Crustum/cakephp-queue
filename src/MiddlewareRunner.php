<?php
declare(strict_types=1);

namespace Crustum\Queue;

use Cake\Queue\Job\Message;
use Crustum\Queue\Contract\MiddlewareInterface;

/**
 * Middleware Runner
 *
 * Processor-agnostic shared core of the job middleware pipeline.
 * Every processor calls this at its inner-execution point with the
 * inner-execution point with the already-resolved job instance — resolve-once
 * is mandatory: the object `middleware()` is read from MUST be the object
 * that executes, otherwise transient container bindings yield siblings.
 */
class MiddlewareRunner
{
    /**
     * Run the middleware stack around the core invocation.
     *
     * @param object $job Job instance (resolved once, shared with `$core`)
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $core Inner execution
     * @param list<\Crustum\Queue\Contract\MiddlewareInterface> $stack Middleware stack
     * @return mixed Whatever the outermost middleware (or `$core`) returns
     */
    public static function run(object $job, Message $message, callable $core, array $stack): mixed
    {
        $pipeline = array_reduce(
            array_reverse($stack),
            static fn(callable $next, MiddlewareInterface $middleware): callable => static fn(object $job, Message $message): mixed => $middleware->handle(
                $job,
                $message,
                $next,
            ),
            $core,
        );

        return $pipeline($job, $message);
    }
}
