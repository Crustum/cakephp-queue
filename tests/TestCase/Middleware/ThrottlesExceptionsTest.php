<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Middleware;

use Cake\Cache\Cache;
use Cake\Queue\Consumption\LimitAttemptsExtension;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Limiter\RateLimiter;
use Crustum\Queue\Middleware\ThrottlesExceptions;
use Crustum\Queue\MiddlewareRunner;
use Crustum\Queue\Test\TestCase\Job\ExampleFailingJob;
use Interop\Queue\Processor;
use InvalidArgumentException;
use RuntimeException;

/**
 * ThrottlesExceptions Test Case
 */
class ThrottlesExceptionsTest extends TestCase
{
    use MiddlewareTestTrait;
    use QueueTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('test_throttle', [
            'className' => 'Array',
            'prefix' => 'test_throttle_',
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Cache::drop('test_throttle');
        parent::tearDown();
    }

    /**
     * @param int $maxAttempts Failure budget
     * @param int $decaySeconds Failure window
     * @return \Crustum\Queue\Middleware\ThrottlesExceptions
     */
    protected function middleware(int $maxAttempts = 10, int $decaySeconds = 600): ThrottlesExceptions
    {
        $middleware = new ThrottlesExceptions($maxAttempts, $decaySeconds, 'test_throttle');
        $middleware->setLimiter(new RateLimiter('test_throttle'));

        return $middleware;
    }

    /**
     * @return void
     */
    public function testSuccessClearsCounter(): void
    {
        $middleware = $this->middleware(10, 60);
        $key = 'crustum-throttles-exceptions:' . md5(ExampleFailingJob::class);
        $limiter = new RateLimiter('test_throttle');

        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 1]);
        MiddlewareRunner::run(
            new ExampleFailingJob(),
            $message,
            static function (): string {
                throw new RuntimeException('flaky');
            },
            [$middleware],
        );
        $this->assertSame(1, $limiter->getAttempts($key));

        $result = MiddlewareRunner::run(
            new ExampleFailingJob(),
            $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 1]),
            static fn(): string => Processor::ACK,
            [$middleware],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertSame(0, $limiter->getAttempts($key));
    }

    /**
     * @return void
     */
    public function testOverBudgetDefersWithAvailableIn(): void
    {
        $middleware = $this->middleware(1, 60);
        $limiter = new RateLimiter('test_throttle');
        $limiter->hit('crustum-throttles-exceptions:' . md5(ExampleFailingJob::class), 60);

        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 2]);
        $coreCalled = false;
        $result = MiddlewareRunner::run(
            new ExampleFailingJob(),
            $message,
            static function () use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [$middleware],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertFalse($coreCalled);

        $queued = $this->getQueuedJobsByClass(ExampleFailingJob::class);
        $this->assertCount(1, $queued);
    }

    /**
     * @return void
     */
    public function testExceptionHitsAndDefersWithBackoff(): void
    {
        $middleware = $this->middleware(10, 60);
        $middleware->setBackoff(5);

        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 3]);
        $result = MiddlewareRunner::run(
            new ExampleFailingJob(),
            $message,
            static function (): string {
                throw new RuntimeException('flaky');
            },
            [$middleware],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertJobQueuedWithDelay(ExampleFailingJob::class, 300);
    }

    /**
     * @return void
     */
    public function testDeleteWhenRejectsWithoutFailedRow(): void
    {
        $middleware = $this->middleware(10, 60);
        $middleware->addDeleteWhen(InvalidArgumentException::class);

        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 4]);
        $result = MiddlewareRunner::run(
            new ExampleFailingJob(),
            $message,
            static function (): string {
                throw new InvalidArgumentException('bad input');
            },
            [$middleware],
        );

        $this->assertSame(Processor::REJECT, $result);
        $this->assertSame([], $this->getQueuedJobsByClass(ExampleFailingJob::class));
    }

    /**
     * @return void
     */
    public function testFailWhenBumpsAttemptsAndRethrows(): void
    {
        $middleware = $this->middleware(10, 60);
        $middleware->addFailWhen(RuntimeException::class);

        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 5]);

        try {
            MiddlewareRunner::run(
                new ExampleFailingJob(),
                $message,
                static function (): string {
                    throw new RuntimeException('fatal');
                },
                [$middleware],
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
    public function testWhenGateSkipsThrottling(): void
    {
        $middleware = $this->middleware(10, 60);
        $middleware->setWhen(static fn(): bool => false);

        $limiter = new RateLimiter('test_throttle');

        $message = $this->buildMiddlewareMessage(ExampleFailingJob::class, ['id' => 6]);

        try {
            MiddlewareRunner::run(
                new ExampleFailingJob(),
                $message,
                static function (): string {
                    throw new RuntimeException('untracked');
                },
                [$middleware],
            );
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('untracked', $runtimeException->getMessage());
        }

        $this->assertSame(0, $limiter->getAttempts(
            'crustum-throttles-exceptions:' . md5(ExampleFailingJob::class),
        ));
        $this->assertSame([], $this->getQueuedJobsByClass(ExampleFailingJob::class));
    }
}
