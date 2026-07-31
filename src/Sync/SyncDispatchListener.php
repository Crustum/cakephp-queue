<?php
declare(strict_types=1);

namespace Crustum\Queue\Sync;

use Cake\Event\EventListenerInterface;
use Crustum\Queue\Event\JobPendingEvent;

/**
 * Decides sync eligibility on Crustum/Queue.Job.pending and signals the trait.
 *
 * Does not execute the job — only throws SyncDispatchHandledException so
 * DispatchableTrait can emit pushed then call SyncJobRunner.
 */
class SyncDispatchListener implements EventListenerInterface
{
    /**
     * @inheritDoc
     */
    public function implementedEvents(): array
    {
        return [
            'Crustum/Queue.Job.pending' => [
                'callable' => 'onJobPending',
                'priority' => 999,
            ],
        ];
    }

    /**
     * @param \Crustum\Queue\Event\JobPendingEvent $event Pending event
     * @return void
     * @throws \Crustum\Queue\Sync\SyncDispatchHandledException When sync should run
     */
    public function onJobPending(JobPendingEvent $event): void
    {
        $payload = $event->getPayload();
        $jobClass = $payload['job'] ?? null;
        if (!is_string($jobClass) || !class_exists($jobClass)) {
            return;
        }

        $body = $payload['body'] ?? [];
        $data = [];
        if (is_array($body) && isset($body['args'][0]) && is_array($body['args'][0])) {
            $data = $body['args'][0];
        }

        $config = $event->getOptions();
        $resolution = SyncModeResolver::resolve($jobClass, $data, $config);
        if ($resolution['sync'] !== true) {
            return;
        }

        $event->setData('sync', true);
        $event->setResult([
            'sync' => true,
            'resolution' => $resolution,
        ]);

        throw new SyncDispatchHandledException($jobClass, $data, $config, $resolution);
    }
}
