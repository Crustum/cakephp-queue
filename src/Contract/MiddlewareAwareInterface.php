<?php
declare(strict_types=1);

namespace Crustum\Queue\Contract;

use Cake\Queue\Job\Message;

/**
 * Middleware Aware Interface
 *
 * Jobs implementing this contract declare their execution middleware stack.
 * The `Message` argument allows argument-aware, container-resolved stacks
 * (e.g. key a lock by DTO field, resolve Cache/limiter from the container).
 */
interface MiddlewareAwareInterface
{
    /**
     * List the middleware for this execution.
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return list<\Crustum\Queue\Contract\MiddlewareInterface>
     */
    public function middleware(Message $message): array;
}
