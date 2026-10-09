<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Job;

use Cake\Queue\Job\Message;
use Crustum\Queue\Job\MiddlewareJob;
use Crustum\Queue\Middleware\Skip;
use Interop\Queue\Processor;
use Override;

/**
 * MiddlewareJob base-class fixture for pipeline tests.
 */
class ExampleMiddlewareBaseJob extends MiddlewareJob
{
    /**
     * @var list<array<string, mixed>>
     */
    public static array $executed = [];

    /**
     * @var bool
     */
    public static bool $skip = false;

    /**
     * @param \Cake\Queue\Job\Message $message Job message
     * @return string|null
     */
    protected function doExecute(Message $message): ?string
    {
        self::$executed[] = (array)$message->getArgument();

        return Processor::ACK;
    }

    /**
     * @param \Cake\Queue\Job\Message $message Job message
     * @return list<\Crustum\Queue\Contract\MiddlewareInterface>
     */
    #[Override]
    public function middleware(Message $message): array
    {
        if (!self::$skip) {
            return [];
        }

        return [Skip::when(true)];
    }
}
