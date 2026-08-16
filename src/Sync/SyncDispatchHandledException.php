<?php
declare(strict_types=1);

namespace Crustum\Queue\Sync;

use RuntimeException;

/**
 * Internal control-flow signal: skip QueueManager::push and run SyncJobRunner.
 *
 * Caught and absorbed entirely inside DispatchableTrait::dispatch().
 * Application code must never catch or depend on this type.
 */
final class SyncDispatchHandledException extends RuntimeException
{
    /**
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $config Queue / dispatch configuration
     * @param array<string, mixed> $resolution Resolver debug (why sync)
     */
    public function __construct(
        public readonly string $jobClass,
        public readonly array $data,
        public readonly array $config,
        public readonly array $resolution = [],
    ) {
        parent::__construct('Queue sync dispatch handled; skip enqueue.');
    }
}
