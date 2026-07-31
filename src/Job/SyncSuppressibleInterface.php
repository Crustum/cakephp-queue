<?php
declare(strict_types=1);

namespace Crustum\Queue\Job;

/**
 * Jobs that must stay async even when CrustumQueue.sync is enabled.
 */
interface SyncSuppressibleInterface
{
    /**
     * Whether this job should suppress synchronous execution.
     *
     * @param array<string, mixed> $data Job payload
     * @return bool True to always enqueue (skip sync)
     */
    public static function suppressSync(array $data = []): bool;
}
