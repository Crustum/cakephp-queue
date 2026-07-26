<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Interop\Queue\Processor;

/**
 * Minimal dispatchable job for tests.
 */
class ExampleDispatchableJob implements JobInterface, DispatchableInterface
{
    use DispatchableTrait;

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        return Processor::ACK;
    }
}
