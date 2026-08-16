<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Interop\Queue\Processor;

/**
 * Job constructed via DI for sync runner tests.
 */
class ExampleDiSyncJob implements JobInterface, DispatchableInterface
{
    use DispatchableTrait;

    /**
     * @var list<string>
     */
    public static array $tokens = [];

    /**
     * @param string $token Injected dependency
     */
    public function __construct(public string $token)
    {
    }

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        self::$tokens[] = $this->token;

        return Processor::ACK;
    }
}
