<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Crustum\Queue\Job\SyncSuppressibleInterface;
use Interop\Queue\Processor;

/**
 * Dispatchable job that opts out of sync execution.
 */
class ExampleSyncSuppressJob implements JobInterface, DispatchableInterface, SyncSuppressibleInterface
{
    use DispatchableTrait;

    /**
     * @var list<array<string, mixed>>
     */
    public static array $executed = [];

    /**
     * @inheritDoc
     */
    public static function suppressSync(array $data = []): bool
    {
        return true;
    }

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        self::$executed[] = (array)$message->getArgument();

        return Processor::ACK;
    }
}
