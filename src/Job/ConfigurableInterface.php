<?php
declare(strict_types=1);

namespace Crustum\Queue\Job;

/**
 * Configurable Interface
 *
 * Interface for jobs that can define their own queue configuration.
 */
interface ConfigurableInterface
{
    /**
     * Get the queue configuration for the job.
     *
     * @return array<string, mixed> Queue configuration array
     */
    public static function getQueueConfig(): array;
}
