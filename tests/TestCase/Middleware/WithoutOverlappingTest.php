<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Middleware;

use Cake\Lock\Engine\FileLockEngine;
use Cake\Lock\Lock;
use Cake\Lock\LockRegistry;
use Cake\Queue\Job\Message;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Middleware\WithoutOverlapping;
use Crustum\Queue\MiddlewareRunner;
use Crustum\Queue\Test\TestCase\Job\ExampleMiddlewareJob;
use Interop\Queue\Processor;
use RuntimeException;

/**
 * WithoutOverlapping Test Case
 */
class WithoutOverlappingTest extends TestCase
{
    use MiddlewareTestTrait;
    use QueueTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Lock::setConfig('test_overlap', [
            'className' => FileLockEngine::class,
            'path' => TMP . 'locks_overlap_test' . DS,
            'prefix' => 'overlap_test_',
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Lock::drop('test_overlap');
        Lock::setRegistry(new LockRegistry());
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testFirstHolderRunsAndReleases(): void
    {
        $middleware = new WithoutOverlapping('email', 30, 60, 'test_overlap');
        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 1]);
        $coreCalled = false;

        $result = MiddlewareRunner::run(
            new ExampleMiddlewareJob(),
            $message,
            static function (object $job, Message $message) use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [$middleware],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertTrue($coreCalled);
        $this->assertFalse(Lock::isLocked($middleware->getLockKey(new ExampleMiddlewareJob()), 'test_overlap'));
    }

    /**
     * @return void
     */
    public function testContentionRepushesWithDelay(): void
    {
        $middleware = new WithoutOverlapping('email', 30, 60, 'test_overlap');
        $held = Lock::acquire($middleware->getLockKey(new ExampleMiddlewareJob()), 60, 'test_overlap');
        $this->assertNotNull($held);

        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 2]);
        $coreCalled = false;

        try {
            $result = MiddlewareRunner::run(
                new ExampleMiddlewareJob(),
                $message,
                static function () use (&$coreCalled): string {
                    $coreCalled = true;

                    return Processor::ACK;
                },
                [$middleware],
            );
        } finally {
            $held->release();
        }

        $this->assertSame(Processor::ACK, $result);
        $this->assertFalse($coreCalled);
        $this->assertJobQueuedWithDelay(ExampleMiddlewareJob::class, 30);
    }

    /**
     * @return void
     */
    public function testDisableReleaseDropsOnContention(): void
    {
        $middleware = (new WithoutOverlapping('email', 30, 60, 'test_overlap'))->disableRelease();
        $held = Lock::acquire($middleware->getLockKey(new ExampleMiddlewareJob()), 60, 'test_overlap');
        $this->assertNotNull($held);

        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 3]);
        $coreCalled = false;

        try {
            $result = MiddlewareRunner::run(
                new ExampleMiddlewareJob(),
                $message,
                static function () use (&$coreCalled): string {
                    $coreCalled = true;

                    return Processor::ACK;
                },
                [$middleware],
            );
        } finally {
            $held->release();
        }

        $this->assertSame(Processor::ACK, $result);
        $this->assertFalse($coreCalled);
        $this->assertSame([], $this->getQueuedJobsByClass(ExampleMiddlewareJob::class));
    }

    /**
     * @return void
     */
    public function testLockKeys(): void
    {
        $job = new ExampleMiddlewareJob();

        $scoped = new WithoutOverlapping('email', 0, 0, 'test_overlap');
        $this->assertSame(
            'crustum-queue-overlap:' . md5(ExampleMiddlewareJob::class) . ':email',
            $scoped->getLockKey($job),
        );

        $shared = (new WithoutOverlapping('email', 0, 0, 'test_overlap'))->setShared();
        $this->assertSame('crustum-queue-overlap:email', $shared->getLockKey($job));
    }

    /**
     * @return void
     */
    public function testContentionThrowsInSync(): void
    {
        $middleware = new WithoutOverlapping('email', 30, 60, 'test_overlap');
        $held = Lock::acquire($middleware->getLockKey(new ExampleMiddlewareJob()), 60, 'test_overlap');
        $this->assertNotNull($held);

        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 4], true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('sync mode');

            MiddlewareRunner::run(
                new ExampleMiddlewareJob(),
                $message,
                static fn(): string => Processor::ACK,
                [$middleware],
            );
        } finally {
            $held->release();
        }
    }
}
