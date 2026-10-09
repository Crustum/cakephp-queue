<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Middleware;

use Cake\Cache\Cache;
use Cake\Queue\Job\Message;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Limiter\Limit;
use Crustum\Queue\Limiter\RateLimiter;
use Crustum\Queue\Middleware\RateLimited;
use Crustum\Queue\MiddlewareRunner;
use Crustum\Queue\Test\TestCase\Job\ExampleMiddlewareJob;
use Interop\Queue\Processor;
use RuntimeException;

/**
 * RateLimited Test Case
 */
class RateLimitedTest extends TestCase
{
    use MiddlewareTestTrait;
    use QueueTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('test_ratelimit', [
            'className' => 'Array',
            'prefix' => 'test_ratelimit_',
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Cache::drop('test_ratelimit');
        parent::tearDown();
    }

    /**
     * @param \Crustum\Queue\Limiter\RateLimiter $limiter Limiter holding the named resolvers
     * @param string $name Limiter name
     * @return \Crustum\Queue\Middleware\RateLimited
     */
    protected function middleware(RateLimiter $limiter, string $name = 'sending'): RateLimited
    {
        $middleware = new RateLimited($name, 'test_ratelimit');
        $middleware->setLimiter($limiter);

        return $middleware;
    }

    /**
     * @return void
     */
    public function testUnregisteredNamePassesThrough(): void
    {
        $middleware = $this->middleware(new RateLimiter('test_ratelimit'), 'missing');
        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 1]);
        $coreCalled = false;

        $result = MiddlewareRunner::run(
            new ExampleMiddlewareJob(),
            $message,
            static function () use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [$middleware],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertTrue($coreCalled);
    }

    /**
     * @return void
     */
    public function testUnlimitedPassesThrough(): void
    {
        $limiter = new RateLimiter('test_ratelimit');
        $limiter->setLimiter('sending', static fn(): Limit => Limit::none());

        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 2]);
        $coreCalled = false;

        $result = MiddlewareRunner::run(
            new ExampleMiddlewareJob(),
            $message,
            static function () use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [$this->middleware($limiter)],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertTrue($coreCalled);
    }

    /**
     * @return void
     */
    public function testUnderBudgetHitsAndRuns(): void
    {
        $limiter = new RateLimiter('test_ratelimit');
        $limiter->setLimiter('sending', static fn(): Limit => Limit::perMinute(2)->setKey('emails'));

        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 3]);
        $coreCalled = false;

        $result = MiddlewareRunner::run(
            new ExampleMiddlewareJob(),
            $message,
            static function (object $job, Message $message) use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [$this->middleware($limiter)],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertTrue($coreCalled);
        $this->assertSame(1, $limiter->getAttempts(md5('sendingemails')));
    }

    /**
     * @return void
     */
    public function testOverBudgetDefersWithAvailability(): void
    {
        $limiter = new RateLimiter('test_ratelimit');
        $limiter->setLimiter('sending', static fn(): Limit => Limit::perMinute(1)->setKey('emails'));
        $limiter->hit(md5('sendingemails'), 60);

        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 4]);
        $coreCalled = false;

        $result = MiddlewareRunner::run(
            new ExampleMiddlewareJob(),
            $message,
            static function () use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [$this->middleware($limiter)],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertFalse($coreCalled);

        $queued = $this->getQueuedJobsByClass(ExampleMiddlewareJob::class);
        $this->assertCount(1, $queued);
    }

    /**
     * @return void
     */
    public function testDisableReleaseDrops(): void
    {
        $limiter = new RateLimiter('test_ratelimit');
        $limiter->setLimiter('sending', static fn(): Limit => Limit::perMinute(1)->setKey('emails'));
        $limiter->hit(md5('sendingemails'), 60);

        $middleware = $this->middleware($limiter)->disableRelease();
        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 5]);
        $coreCalled = false;

        $result = MiddlewareRunner::run(
            new ExampleMiddlewareJob(),
            $message,
            static function () use (&$coreCalled): string {
                $coreCalled = true;

                return Processor::ACK;
            },
            [$middleware],
        );

        $this->assertSame(Processor::ACK, $result);
        $this->assertFalse($coreCalled);
        $this->assertSame([], $this->getQueuedJobsByClass(ExampleMiddlewareJob::class));
    }

    /**
     * @return void
     */
    public function testSyncDefersThrow(): void
    {
        $limiter = new RateLimiter('test_ratelimit');
        $limiter->setLimiter('sending', static fn(): Limit => Limit::perMinute(1)->setKey('emails'));
        $limiter->hit(md5('sendingemails'), 60);

        $message = $this->buildMiddlewareMessage(ExampleMiddlewareJob::class, ['id' => 6], true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sync mode');

        MiddlewareRunner::run(
            new ExampleMiddlewareJob(),
            $message,
            static fn(): string => Processor::ACK,
            [$this->middleware($limiter)],
        );
    }
}
