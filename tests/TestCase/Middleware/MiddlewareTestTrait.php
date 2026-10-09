<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Middleware;

use Cake\Queue\Job\Message;
use Enqueue\Null\NullContext;
use Enqueue\Null\NullMessage;

/**
 * Builds live `Message` instances for middleware tests.
 */
trait MiddlewareTestTrait
{
    /**
     * @param class-string $jobClass Job class
     * @param array<string, mixed> $data Job data
     * @param bool $sync Annotate as a sync message
     * @return \Cake\Queue\Job\Message
     */
    protected function buildMiddlewareMessage(string $jobClass, array $data, bool $sync = false): Message
    {
        $message = new NullMessage((string)json_encode([
            'class' => [$jobClass, 'execute'],
            'args' => [$data],
            'data' => $data,
            'requeueOptions' => [
                'config' => 'default',
                'priority' => null,
                'queue' => 'default',
            ],
        ]));
        if ($sync) {
            $message->setProperty('sync', true);
        }

        return new Message($message, new NullContext());
    }
}
