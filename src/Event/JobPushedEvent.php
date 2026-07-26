<?php
declare(strict_types=1);

namespace Crustum\Queue\Event;

use Cake\Event\Event;
use Cake\I18n\DateTime;

/**
 * Job Pushed Event
 *
 * Fired when a job is pushed to the queue.
 * Contains job information and queue details.
 */
class JobPushedEvent extends Event
{
    /**
     * Constructor
     *
     * @param string $connection The queue connection name
     * @param string $queue The queue name
     * @param array<string, mixed> $payload The job payload
     * @param array<string, mixed> $options Additional options
     */
    public function __construct(string $connection, string $queue, array $payload, array $options = [])
    {
        parent::__construct('Crustum/Queue.Job.pushed', null, [
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'options' => $options,
            'timestamp' => DateTime::now()->getTimestamp(),
        ]);
    }

    /**
     * Get the queue connection name.
     *
     * @return string
     */
    public function getConnection(): string
    {
        return $this->getData('connection');
    }

    /**
     * Get the queue name.
     *
     * @return string
     */
    public function getQueue(): string
    {
        return $this->getData('queue');
    }

    /**
     * Get the job payload.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->getData('payload');
    }

    /**
     * Get additional options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->getData('options');
    }

    /**
     * Get the event timestamp.
     *
     * @return int
     */
    public function getTimestamp(): int
    {
        return $this->getData('timestamp');
    }
}
