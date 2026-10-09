<?php
declare(strict_types=1);

namespace Crustum\Queue\Processor;

use Cake\Queue\Job\Message;
use Cake\Queue\Queue\Processor;
use Closure;
use Interop\Queue\Processor as InteropProcessor;
use Override;

/**
 * Middleware Processor
 *
 * Thin `Processor` subclass for plain queues. Pipeline wiring lives in
 * `RunsMiddlewareTrait` — this class only resolves once and maps the result.
 *
 * Wiring (one config line, worker + sync pick it up automatically):
 *
 * ```php
 * 'Queue' => ['default' => [
 *     'processor' => \Crustum\Queue\Processor\MiddlewareProcessor::class,
 * ]],
 * ```
 */
class MiddlewareProcessor extends Processor
{
    use RunsMiddlewareTrait;

    /**
     * @param \Cake\Queue\Job\Message $message Job message
     * @return object|string with __toString method implemented
     */
    #[Override]
    public function processMessage(Message $message): string|object
    {
        [, $method] = $message->getTarget();
        $job = $this->resolveMiddlewareJob($message, $this->container);
        $target = [$job, $method];
        assert(is_callable($target));
        $callable = Closure::fromCallable($target);
        $result = $this->runWithMiddleware(
            $job,
            $message,
            static fn(object $job, Message $message): mixed => $callable($message),
        );

        if ($result === null) {
            return InteropProcessor::ACK;
        }

        return $result;
    }
}
