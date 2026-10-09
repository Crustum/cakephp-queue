<?php
declare(strict_types=1);

namespace Crustum\Queue\Processor;

use Cake\Core\ContainerInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Contract\MiddlewareAwareInterface;
use Crustum\Queue\MiddlewareRunner;

/**
 * Runs Middleware Trait
 *
 * One-line middleware integration for processors. Drop into any processor
 * and call at the inner-execution point — no resolve-shape copy-paste per site.
 *
 * Resolve-once is preserved: pass the already-resolved instance to
 * `runWithMiddleware()` (never resolve twice under transient bindings).
 *
 * Example (processor with its own resolved `$job` in scope):
 *
 * ```php
 * $response = $this->runWithMiddleware(
 *     $job,
 *     $jobMessage,
 *     static fn (): mixed => $callable($jobMessage),
 * );
 * ```
 */
trait RunsMiddlewareTrait
{
    /**
     * Resolve the job instance for the message (container or plain `new`).
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @param \Cake\Core\ContainerInterface|null $container DI container
     * @return object The same instance that must execute (resolve-once)
     */
    protected function resolveMiddlewareJob(Message $message, ?ContainerInterface $container): object
    {
        [$class] = $message->getTarget();

        if ($container instanceof ContainerInterface && $container->has($class)) {
            return $container->get($class);
        }

        return new $class();
    }

    /**
     * Run the middleware stack around the core invocation.
     *
     * @param object $job Already-resolved job instance (shared with `$core`)
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $core Inner execution
     * @return mixed Whatever the outermost middleware (or `$core`) returns
     */
    protected function runWithMiddleware(object $job, Message $message, callable $core): mixed
    {
        $stack = $job instanceof MiddlewareAwareInterface ? $job->middleware($message) : [];

        return MiddlewareRunner::run($job, $message, $core, $stack);
    }
}
