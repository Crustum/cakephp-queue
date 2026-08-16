<?php
declare(strict_types=1);

namespace Crustum\Queue\Sync;

use Cake\Core\ContainerInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Log\Log;
use Cake\Queue\Queue\Processor;
use Cake\Queue\QueueManager;
use Crustum\Queue\ContainerRegistry;
use Enqueue\Consumption\Result;
use Enqueue\Null\NullContext;
use Enqueue\Null\NullMessage;
use Interop\Queue\Processor as InteropProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Runs a job in-process via Cake Queue Processor (DI + Processor.message.* parity).
 */
final class SyncJobRunner
{
    /**
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $config Dispatch configuration
     * @param \Cake\Core\ContainerInterface|null $container Optional container override
     * @return void
     * @throws \Throwable When the job fails, rejects, or would requeue
     */
    public static function run(
        string $jobClass,
        array $data,
        array $config = [],
        ?ContainerInterface $container = null,
    ): void {
        $name = is_string($config['config'] ?? null) ? $config['config'] : 'default';
        $queue = is_string($config['queue'] ?? null)
            ? $config['queue']
            : (QueueManager::getConfig($name)['queue'] ?? 'default');

        $body = [
            'class' => [$jobClass, 'execute'],
            'args' => [$data],
            'data' => $data,
            'requeueOptions' => [
                'config' => $name,
                'priority' => $config['priority'] ?? null,
                'queue' => $queue,
            ],
        ];

        $message = new NullMessage(json_encode($body, JSON_THROW_ON_ERROR));
        $message->setProperty('sync', true);

        $context = new NullContext();
        $container ??= ContainerRegistry::getInstance();
        $logger = self::resolveLogger($name);
        $processor = self::createProcessor($name, $logger, $container);

        $result = $processor->process($message, $context);
        self::assertSuccessful($result, $message);
    }

    /**
     * @param string $configName Queue config name
     * @param \Psr\Log\LoggerInterface $logger Logger
     * @param \Cake\Core\ContainerInterface|null $container DI container
     * @return \Cake\Queue\Queue\Processor
     */
    protected static function createProcessor(
        string $configName,
        LoggerInterface $logger,
        ?ContainerInterface $container,
    ): Processor {
        $queueConfig = QueueManager::getConfig($configName);
        $processorClass = $queueConfig['processor'] ?? Processor::class;
        if (!is_string($processorClass) || !is_a($processorClass, Processor::class, true)) {
            $processorClass = Processor::class;
        }

        /** @var \Cake\Queue\Queue\Processor $processor */
        $processor = new $processorClass($logger, $container);
        self::annotateProcessorEvents($processor);

        $listenerClass = $queueConfig['listener'] ?? null;
        if (is_string($listenerClass) && class_exists($listenerClass)) {
            $listener = new $listenerClass();
            if ($listener instanceof EventListenerInterface) {
                $processor->getEventManager()->on($listener);
            }
        }

        return $processor;
    }

    /**
     * Merge sync => true into Processor.message.* event data.
     *
     * @param \Cake\Queue\Queue\Processor $processor Processor
     * @return void
     */
    protected static function annotateProcessorEvents(Processor $processor): void
    {
        $names = [
            'Processor.message.seen',
            'Processor.message.start',
            'Processor.message.success',
            'Processor.message.reject',
            'Processor.message.failure',
            'Processor.message.exception',
            'Processor.message.invalid',
        ];

        foreach ($names as $name) {
            $processor->getEventManager()->on(
                $name,
                function (EventInterface $event): void {
                    $event->setData('sync', true);
                },
            );
        }
    }

    /**
     * @param string $configName Queue config name
     * @return \Psr\Log\LoggerInterface
     */
    protected static function resolveLogger(string $configName): LoggerInterface
    {
        $queueConfig = QueueManager::getConfig($configName);
        $loggerName = $queueConfig['logger'] ?? null;
        if (is_string($loggerName) && $loggerName !== '') {
            $engine = Log::engine($loggerName);
            if ($engine instanceof LoggerInterface) {
                return $engine;
            }
        }

        return new NullLogger();
    }

    /**
     * @param object|string $result Processor result
     * @param \Enqueue\Null\NullMessage $message Queue message
     * @return void
     * @throws \Throwable
     */
    protected static function assertSuccessful(object|string $result, NullMessage $message): void
    {
        if ($result instanceof Result) {
            $status = $result->getStatus();
        } elseif ($result instanceof Stringable) {
            $status = $result->__toString();
        } elseif (is_string($result)) {
            $status = $result;
        } else {
            $status = '';
        }

        if ($status === InteropProcessor::ACK) {
            return;
        }

        $exception = $message->getProperty('jobException');
        if ($exception instanceof Throwable) {
            throw $exception;
        }

        throw new RuntimeException(sprintf(
            'Sync job did not acknowledge (status: %s). Reject/requeue are not auto-retried in sync mode.',
            $status !== '' ? $status : get_debug_type($result),
        ));
    }
}
