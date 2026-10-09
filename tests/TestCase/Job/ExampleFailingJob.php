<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Contract\MiddlewareAwareInterface;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Interop\Queue\Processor;
use RuntimeException;

/**
 * Failing job for failure-policy middleware tests.
 */
class ExampleFailingJob implements JobInterface, DispatchableInterface, MiddlewareAwareInterface
{
    use DispatchableTrait;

    /**
     * @var int Fail-fast budget read by `Message::getMaxAttempts()`
     */
    public static int $maxAttempts = 1;

    /**
     * @var list<array<string, mixed>>
     */
    public static array $executed = [];

    /**
     * @var string|null `'throw'` throws, null succeeds
     */
    public static ?string $behavior = null;

    /**
     * @var list<\Crustum\Queue\Contract\MiddlewareInterface>
     */
    public static array $stack = [];

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        self::$executed[] = (array)$message->getArgument();

        if (self::$behavior === 'throw') {
            throw new RuntimeException('job boom');
        }

        return Processor::ACK;
    }

    /**
     * @param \Cake\Queue\Job\Message $message Job message
     * @return list<\Crustum\Queue\Contract\MiddlewareInterface>
     */
    public function middleware(Message $message): array
    {
        return self::$stack;
    }
}
