<?php
declare(strict_types=1);

namespace Crustum\Queue\Job;

/**
 * Interface DispatchableInterface
 *
 * Defines the contract for jobs that can be dispatched to the queue.
 */
interface DispatchableInterface
{
    /**
     * Dispatch the job to the queue with the given data and optional overrides.
     *
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $overrides Runtime configuration overrides
     * @return void
     */
    public static function dispatch(array $data = [], array $overrides = []): void;

    /**
     * Dispatch the job to the queue with a delay.
     *
     * @param array<string, mixed> $data Job payload
     * @param int $delaySeconds Delay in seconds
     * @param array<string, mixed> $overrides Runtime configuration overrides
     * @return void
     */
    public static function dispatchLater(array $data, int $delaySeconds, array $overrides = []): void;
}
