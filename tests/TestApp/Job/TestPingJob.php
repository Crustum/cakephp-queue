<?php
declare(strict_types=1);

namespace TestApp\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Handles;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Interop\Queue\Processor;
use TestApp\Queue\Command\TestPingCommand;

/**
 * Test job for CommandBus tests (independent of Codex jobs).
 */
#[Handles(TestPingCommand::class)]
class TestPingJob implements JobInterface, DispatchableInterface
{
    use DispatchableTrait;

    /**
     * @param \Cake\Queue\Job\Message $message Message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        return Processor::ACK;
    }
}
