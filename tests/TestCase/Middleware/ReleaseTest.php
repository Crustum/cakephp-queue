<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Middleware;

use Cake\Queue\Job\Message;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Middleware\Release;
use Crustum\Queue\MiddlewareRunner;
use Crustum\Queue\Test\TestCase\Job\ExampleMiddlewareJob;
use Interop\Queue\Processor;
use RuntimeException;

/**
 * Release Test Case
 */
class ReleaseTest extends TestCase
{
    use MiddlewareTestTrait;
    use QueueTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        ExampleMiddlewareJob::$executed = [];
        ExampleMiddlewareJob::$skip = false;
    }

    /**
     * @return void
     */
    public function testReleaseRepushesWithDelayAndSkipsCore(): void
    {
        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 1]);
        $job = new ExampleMiddlewareJob();
        $coreCalled = false;

        $result = MiddlewareRunner::run(
            $job,
            $message,
            static function () use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [Release::when(true, 30)],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertFalse($coreCalled);
        $this->assertJobQueuedWithDelay(ExampleMiddlewareJob::class, 30);
    }

    /**
     * @return void
     */
    public function testReleaseUnlessAndPassThrough(): void
    {
        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 2]);
        $job = new ExampleMiddlewareJob();
        $coreCalled = false;

        $result = MiddlewareRunner::run(
            $job,
            $message,
            static function (object $job, Message $message) use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [Release::unless(true)],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertTrue($coreCalled);
        $this->assertSame([], $this->getQueuedJobsByClass(ExampleMiddlewareJob::class));
    }

    /**
     * @return void
     */
    public function testReleaseThrowsInSync(): void
    {
        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 3], true);
        $job = new ExampleMiddlewareJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sync mode');

        MiddlewareRunner::run(
            $job,
            $message,
            static fn(): string => Processor::ACK,
            [Release::when(true, 10)],
        );
    }
}
