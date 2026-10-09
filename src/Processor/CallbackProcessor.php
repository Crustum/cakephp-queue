<?php
declare(strict_types=1);

namespace Crustum\Queue\Processor;

use Cake\Core\ContainerInterface;
use Cake\Queue\Job\Message;
use Cake\Queue\Queue\Processor as BaseProcessor;
use Closure;
use Crustum\Queue\Contract\CallbackSerializerInterface;
use Crustum\Queue\Contract\HasCallbackSerializer;
use Enqueue\Consumption\Result;
use Interop\Queue\Context;
use Interop\Queue\Message as QueueMessage;
use Interop\Queue\Processor as InteropProcessor;
use Override;
use Throwable;

/**
 * Generic Callback-style queue processor.
 *
 * Rewrite of `Cake\Queue\Queue\Processor` event flow (`seen/start/success/
 * reject/failure/exception/invalid`) with two deliberate differences:
 *
 * - Poison messages REJECT instead of killing the worker: the
 *   `getTarget()`/resolution guard is widened from `RuntimeException|Error`
 *   to `Throwable` (League Container throws `NotFoundException`, neither of
 *   the base catches).
 * - Callback return semantics: the job's return value is data, not a
 *   protocol signal. Any return other than an explicit `REJECT` (or an
 *   explicit requeue `Result`) is success — the message is ACKed and a
 *   `success` event fires. Only a `Throwable` fails the message (requeue +
 *   `exception` event). Upstream jobs return response objects; requiring an
 *   `ACK` string would misclassify every success as a failure.
 *
 * The processor references no job-layer types. Completion callbacks travel
 * in the payload (`then` / `catch` packed-callback lists, see
 * `QueuedCallback`) and run wherever the job runs; a `failed(Throwable, array)`
 * hook is invoked when the job object provides one (duck-typed, never
 * required). Jobs stay plain message handlers: `execute(Message): mixed`
 * runs, throws on failure.
 *
 * Middleware runs at the inner-execution point via `RunsMiddlewareTrait`,
 * like every other processor. Callback packing needs
 * `laravel/serializable-closure` (suggested, not required): without it,
 * packing throws and unpacking skips.
 */
class CallbackProcessor extends BaseProcessor
{
    use RunsMiddlewareTrait;

    /**
     * The method processes messages.
     *
     * @param \Interop\Queue\Message $message Message.
     * @param \Interop\Queue\Context $context Context.
     * @return object|string with __toString method implemented
     */
    #[Override]
    public function process(QueueMessage $message, Context $context): string|object
    {
        $jobMessage = new Message($message, $context, $this->container);

        try {
            $target = $jobMessage->getTarget();
            $jobClass = $target[0];
        } catch (Throwable $throwable) {
            $this->dispatchEvent('Processor.message.seen', ['queueMessage' => $message]);
            $this->logger->debug('Invalid callable for message. Rejecting message from queue.');
            $this->dispatchEvent('Processor.message.invalid', ['message' => $jobMessage]);

            return InteropProcessor::REJECT;
        }

        $this->dispatchEvent('Processor.message.seen', ['queueMessage' => $message]);

        try {
            $job = $this->resolveJob($jobClass);
            $target = [$job, $target[1]];
            assert(is_callable($target));
            $callable = Closure::fromCallable($target);
        } catch (Throwable $throwable) {
            $this->logger->error(sprintf('Unable to build job [%s]. Rejecting message from queue.', $jobClass));
            $this->dispatchEvent('Processor.message.invalid', ['message' => $jobMessage]);

            return InteropProcessor::REJECT;
        }

        $startTime = microtime(true) * 1000;
        $this->dispatchEvent('Processor.message.start', ['message' => $jobMessage]);

        try {
            $response = $this->runWithMiddleware(
                $job,
                $jobMessage,
                static fn(): mixed => $callable($jobMessage),
            );
        } catch (Throwable $throwable) {
            $message->setProperty('jobException', $throwable);

            $this->failJob($job, $jobMessage, $throwable);

            $this->logger->debug(sprintf('Message encountered exception: %s', $throwable->getMessage()));
            $this->dispatchEvent('Processor.message.exception', [
                'message' => $jobMessage,
                'exception' => $throwable,
                'duration' => (int)((microtime(true) * 1000) - $startTime),
            ]);

            return Result::requeue('Exception occurred while processing message');
        }

        if ($response === InteropProcessor::REJECT) {
            $this->logger->debug('Message processed with rejection');
            $this->dispatchEvent('Processor.message.reject', [
                'message' => $jobMessage,
                'duration' => (int)((microtime(true) * 1000) - $startTime),
            ]);

            return InteropProcessor::REJECT;
        }

        if ($response instanceof Result && $response->getStatus() === InteropProcessor::REQUEUE) {
            $this->logger->debug('Message processed with failure, requeuing');
            $this->dispatchEvent('Processor.message.failure', [
                'message' => $jobMessage,
                'duration' => (int)((microtime(true) * 1000) - $startTime),
            ]);

            return InteropProcessor::REQUEUE;
        }

        $this->succeedJob($job, $jobMessage, $response);

        $this->logger->debug('Message processed successfully');
        $this->dispatchEvent('Processor.message.success', [
            'message' => $jobMessage,
            'duration' => (int)((microtime(true) * 1000) - $startTime),
        ]);

        return InteropProcessor::ACK;
    }

    /**
     * Resolve a job instance.
     *
     * Jobs are plain handlers built with no constructor arguments. The
     * container is preferred (allows service bindings), with a plain
     * instantiation fallback.
     *
     * @param class-string $jobClass Job class
     * @return object Job instance
     */
    protected function resolveJob(string $jobClass): object
    {
        if ($this->container instanceof ContainerInterface && $this->container->has($jobClass)) {
            $job = $this->container->get($jobClass);
            assert(is_object($job));

            return $job;
        }

        return new $jobClass();
    }

    /**
     * Handle a successfully executed job: run payload "then" callbacks.
     *
     * The callback response prefers a `getResponse()` accessor when the job
     * provides one (duck-typed), otherwise the raw handler return value —
     * except protocol signals (`ACK`/`REJECT` strings, `Result` objects),
     * which carry no data and resolve to `null`.
     *
     * Callback failures are logged and swallowed: the job already succeeded
     * and re-running it (requeue) would repeat its side effects. Callbacks are
     * observers, never arbiters of the job result.
     *
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param mixed $result Handler return value
     * @return void
     */
    protected function succeedJob(object $job, Message $message, mixed $result = null): void
    {
        if (method_exists($job, 'getResponse')) {
            try {
                $response = $job->getResponse();
            } catch (Throwable) {
                $response = null;
            }
        } elseif (
            $result === InteropProcessor::ACK
            || $result === InteropProcessor::REJECT
            || $result instanceof Result
        ) {
            $response = null;
        } else {
            $response = $result;
        }

        $callbacks = $this->payloadCallbacks($message, 'then', $this->callbackSerializer($job));

        foreach ($callbacks as $callback) {
            try {
                $callback($response);
            } catch (Throwable $throwable) {
                $this->logger->error(sprintf(
                    'Job "then" callback failed: %s',
                    $throwable->getMessage(),
                ));
            }
        }
    }

    /**
     * Handle a failed job: invoke the `failed()` hook and "catch" callbacks.
     *
     * The hook is duck-typed (`failed(Throwable, array)` when present, never
     * required). Everything here is guarded: a failing hook or callback must
     * never mask the original exception, and the caller still requeues so
     * retry accounting is preserved.
     *
     * @param object $job Job instance
     * @param \Cake\Queue\Job\Message $message Job message
     * @param \Throwable $throwable The failure
     * @return void
     */
    protected function failJob(object $job, Message $message, Throwable $throwable): void
    {
        $data = $message->getArgument() ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        if (method_exists($job, 'failed')) {
            try {
                $job->failed($throwable, $data);
            } catch (Throwable $hookThrowable) {
                $this->logger->error(sprintf(
                    'Job "failed" hook failed: %s',
                    $hookThrowable->getMessage(),
                ));
            }
        }

        $callbacks = $this->payloadCallbacks($message, 'catch', $this->callbackSerializer($job));

        foreach ($callbacks as $callback) {
            try {
                $callback($throwable);
            } catch (Throwable $callbackThrowable) {
                $this->logger->error(sprintf(
                    'Job "catch" callback failed: %s',
                    $callbackThrowable->getMessage(),
                ));
            }
        }
    }

    /**
     * Pick the callback serializer for the job.
     *
     * @param object $job Job instance (already resolved — zero extra constructions)
     * @return \Crustum\Queue\Contract\CallbackSerializerInterface|null Null uses the default
     */
    protected function callbackSerializer(object $job): ?CallbackSerializerInterface
    {
        return $job instanceof HasCallbackSerializer ? $job::callbackSerializer() : null;
    }

    /**
     * Unpack "then" / "catch" callbacks from the message payload.
     *
     * Unpack failures are skipped per item: a corrupt entry must never break
     * the job lifecycle (and therefore must never skip the terminal event).
     *
     * @param \Cake\Queue\Job\Message $message Job message
     * @param string $key Callback list key
     * @param \Crustum\Queue\Contract\CallbackSerializerInterface|null $serializer Custom serializer
     * @return array<int, callable> Invokable callbacks
     */
    protected function payloadCallbacks(
        Message $message,
        string $key,
        ?CallbackSerializerInterface $serializer = null,
    ): array {
        try {
            $data = $message->getArgument() ?? [];
        } catch (Throwable) {
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        return QueuedCallback::unpackList($data[$key] ?? null, $serializer);
    }
}
