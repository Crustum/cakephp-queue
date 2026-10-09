<?php
declare(strict_types=1);

namespace Crustum\Queue\Middleware;

use Cake\Queue\Job\Message;
use Cake\Queue\QueueManager;
use Interop\Queue\Processor;
use RuntimeException;

/**
 * Releases Jobs
 *
 * Shared deferral helper for middleware: re-push a delayed copy via
 * `QueueManager` (Cake `REQUEUE` is delay-less) and `ACK` the current copy.
 *
 * Sync-unsafe by design: there is no "later" in-process, so deferral inside
 * `SyncJobRunner` throws a descriptive exception instead of silently
 * misbehaving. Guard/skip/fail middleware stays sync-safe.
 */
trait ReleasesJobsTrait
{
    /**
     * Re-push the job with a delay and acknowledge the current copy.
     *
     * @param object $job Job instance (unused, kept for call-site symmetry)
     * @param \Cake\Queue\Job\Message $message Job message
     * @param int $delay Seconds before the copy becomes available
     * @return string Always `Processor::ACK`
     * @throws \RuntimeException In sync mode (no later in-process)
     */
    protected function release(object $job, Message $message, int $delay = 0): string
    {
        if ($message->getOriginalMessage()->getProperty('sync') === true) {
            throw new RuntimeException(
                'Cannot release a job with delay in sync mode: there is no later in-process. ' .
                'Use a guard/skip/fail middleware, or run the worker path.',
            );
        }

        [$class] = $message->getTarget();
        $body = $message->getParsedBody();
        if (array_key_exists('data', $body)) {
            $data = $body['data'];
        } else {
            $data = $body['args'][0] ?? [];
        }

        $requeue = $body['requeueOptions'] ?? [];
        QueueManager::push($class, (array)$data, [
            'config' => $requeue['config'] ?? 'default',
            'queue' => $requeue['queue'] ?? 'default',
            'priority' => $requeue['priority'] ?? null,
            'delay' => $delay,
        ]);

        return Processor::ACK;
    }
}
