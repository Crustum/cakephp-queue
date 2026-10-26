<?php
declare(strict_types=1);

namespace Crustum\Queue\Job;

use Cake\Container\ContainerInterface;
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\ContainerRegistry;
use Crustum\Queue\Contract\MiddlewareAwareInterface;
use Crustum\Queue\MiddlewareRunner;

/**
 * Middleware Job
 *
 * Ergonomic base class for new plain jobs: `final execute()` makes the
 * pipeline wrapper unskippable within the hierarchy, so subclasses only
 * implement `doExecute()` + optionally `middleware()`.
 *
 * DI-safe by construction — inside `execute()`, `$this` IS the
 * container-resolved executing instance, so `middleware()` + `doExecute()`
 * trivially share injected deps with no resolve-once discipline needed.
 *
 * Opt-in by inheritance: jobs implementing `JobInterface` directly bypass it.
 * Processors that resolve + invoke the job themselves still need the
 * `MiddlewareRunner` call at their inner-execution point.
 */
abstract class MiddlewareJob implements JobInterface, DispatchableInterface, MiddlewareAwareInterface
{
    use DispatchableTrait;

    /**
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null
     */
    final public function execute(Message $message): ?string
    {
        $result = MiddlewareRunner::run(
            $this,
            $message,
            fn(object $job, Message $message): ?string => $this->doExecute($message),
            $this->middleware($message),
        );

        return is_string($result) ? $result : null;
    }

    /**
     * Real work; return ACK/REJECT/REQUEUE string or null (= ACK).
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null
     */
    abstract protected function doExecute(Message $message): ?string;

    /**
     * Override to attach middleware; default none.
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @return list<\Crustum\Queue\Contract\MiddlewareInterface>
     */
    public function middleware(Message $message): array
    {
        return [];
    }

    /**
     * App container for resolving middleware services (Cache, limiter, …).
     *
     * @return \Cake\Container\ContainerInterface|null
     */
    protected function container(): ?ContainerInterface
    {
        return ContainerRegistry::getInstance();
    }
}
