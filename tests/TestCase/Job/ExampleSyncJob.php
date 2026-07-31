<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Interop\Queue\Processor;
use RuntimeException;

/**
 * Dispatchable job that records executions and can fail/reject.
 */
class ExampleSyncJob implements JobInterface, DispatchableInterface
{
    use DispatchableTrait;

    /**
     * @var list<array<string, mixed>>
     */
    public static array $executed = [];

    /**
     * @var string|null Processor::ACK|REJECT|REQUEUE or 'throw'
     */
    public static ?string $behavior = null;

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        self::$executed[] = (array)$message->getArgument();

        return match (self::$behavior) {
            'throw' => throw new RuntimeException('sync job boom'),
            Processor::REJECT => Processor::REJECT,
            Processor::REQUEUE => Processor::REQUEUE,
            default => Processor::ACK,
        };
    }
}
