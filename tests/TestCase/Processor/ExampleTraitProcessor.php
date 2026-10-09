<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Processor;

use Cake\Core\ContainerInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Processor\RunsMiddlewareTrait;

/**
 * Minimal trait consumer exposing protected methods for tests.
 */
class ExampleTraitProcessor
{
    use RunsMiddlewareTrait;

    /**
     * @param \Cake\Queue\Job\Message $message Job message
     * @param \Cake\Core\ContainerInterface|null $container DI container
     * @return object
     */
    public function resolveJob(Message $message, ?ContainerInterface $container): object
    {
        return $this->resolveMiddlewareJob($message, $container);
    }

    /**
     * @param object $job Already-resolved job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param callable(object, \Cake\Queue\Job\Message): mixed $core Inner execution
     * @return mixed
     */
    public function runJob(object $job, Message $message, callable $core): mixed
    {
        return $this->runWithMiddleware($job, $message, $core);
    }
}
