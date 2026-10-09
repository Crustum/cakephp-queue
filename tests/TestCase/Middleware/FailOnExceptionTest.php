<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Middleware;

use Cake\Queue\Consumption\LimitAttemptsExtension;
use Cake\Queue\Job\Message;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Middleware\FailOnException;
use Crustum\Queue\MiddlewareRunner;
use Crustum\Queue\Test\TestCase\Job\ExampleFailingJob;
use Interop\Queue\Processor;
use InvalidArgumentException;
use RuntimeException;

/**
 * FailOnException Test Case
 */
class FailOnExceptionTest extends TestCase
{
    use MiddlewareTestTrait;

    /**
     * @return void
     */
    public function testMatchedExceptionBumpsAttemptsAndRethrows(): void
    {
        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 1]);
        $job = new ExampleFailingJob();

        try {
            MiddlewareRunner::run(
                $job,
                $message,
                static function (): string {
                    throw new RuntimeException('fatal');
                },
                [new FailOnException([RuntimeException::class])],
            );
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('fatal', $runtimeException->getMessage());
        }

        $this->assertSame(
            ExampleFailingJob::$maxAttempts,
            $message->getOriginalMessage()->getProperty(LimitAttemptsExtension::ATTEMPTS_PROPERTY),
        );
    }

    /**
     * @return void
     */
    public function testUnmatchedExceptionRethrowsWithoutBump(): void
    {
        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 2]);
        $job = new ExampleFailingJob();

        try {
            MiddlewareRunner::run(
                $job,
                $message,
                static function (): string {
                    throw new InvalidArgumentException('retryable');
                },
                [new FailOnException([RuntimeException::class])],
            );
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertSame('retryable', $invalidArgumentException->getMessage());
        }

        $this->assertNull(
            $message->getOriginalMessage()->getProperty(LimitAttemptsExtension::ATTEMPTS_PROPERTY),
        );
    }

    /**
     * @return void
     */
    public function testClosureCallbackAndPassThrough(): void
    {
        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 3]);
        $job = new ExampleFailingJob();
        $seen = [];

        $result = MiddlewareRunner::run(
            $job,
            $message,
            static fn(object $job, Message $message): string => Processor::ACK,
            [new FailOnException(static function (object $e, object $job) use (&$seen): bool {
                $seen[] = $e::class;

                return true;
            })],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertSame([], $seen);
    }
}
