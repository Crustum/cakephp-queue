<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Job\ConfigurableInterface;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Interop\Queue\Processor;

/**
 * Configurable dispatchable job for tests.
 */
class ExampleConfigurableJob implements JobInterface, DispatchableInterface, ConfigurableInterface
{
    use DispatchableTrait;

    /**
     * @return array<string, mixed>
     */
    public static function getQueueConfig(): array
    {
        return [
            'queue' => 'corpus-embed',
            'config' => 'corpus_embed',
            'maxAttempts' => 3,
            'retryDelay' => 60,
        ];
    }

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        return Processor::ACK;
    }
}
