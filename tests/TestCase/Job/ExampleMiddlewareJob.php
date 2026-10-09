<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Queue\Contract\MiddlewareAwareInterface;
use Crustum\Queue\Job\DispatchableInterface;
use Crustum\Queue\Job\DispatchableTrait;
use Crustum\Queue\Middleware\Skip;
use Interop\Queue\Processor;

/**
 * Middleware-aware job for pipeline tests (plain JobInterface path).
 */
class ExampleMiddlewareJob implements JobInterface, DispatchableInterface, MiddlewareAwareInterface
{
    use DispatchableTrait;

    /**
     * @var list<array<string, mixed>>
     */
    public static array $executed = [];

    /**
     * @var bool
     */
    public static bool $skip = false;

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        self::$executed[] = (array)$message->getArgument();

        return Processor::ACK;
    }

    /**
     * @param \Cake\Queue\Job\Message $message Job message
     * @return list<\Crustum\Queue\Contract\MiddlewareInterface>
     */
    public function middleware(Message $message): array
    {
        if (!self::$skip) {
            return [];
        }

        return [Skip::when(true)];
    }
}
