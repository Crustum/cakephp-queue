<?php
declare(strict_types=1);

namespace Crustum\Queue\Contract;

use Cake\Queue\Job\Message;

/**
 * Middleware Interface
 *
 * Per-job execution middleware (Laravel `Illuminate\Queue\Middleware\*` port).
 * The pipeline is an onion: call `$next($job, $message)` to run the rest of
 * the chain, or return without calling it to short-circuit (skip-and-ACK).
 */
interface MiddlewareInterface
{
    /**
     * Handle the job execution.
     *
     * @param object $job Job instance (the same instance that executes)
     * @param \Cake\Queue\Job\Message $message Job message (args, target, context)
     * @param callable(object, \Cake\Queue\Job\Message): mixed $next Next middleware / core invocation
     * @return mixed ACK / REJECT / REQUEUE string, or whatever `$next` returns
     */
    public function handle(object $job, Message $message, callable $next): mixed;
}
