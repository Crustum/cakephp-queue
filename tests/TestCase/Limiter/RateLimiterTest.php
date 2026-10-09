<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Limiter;

use Cake\Cache\Cache;
use Cake\Queue\Job\Message;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Limiter\RateLimiter;
use stdClass;

/**
 * RateLimiter Test Case
 */
class RateLimiterTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('test_limiter', [
            'className' => 'Array',
            'prefix' => 'test_limiter_',
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Cache::drop('test_limiter');
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testHitCountsAndLimits(): void
    {
        $limiter = new RateLimiter('test_limiter');

        $this->assertSame(1, $limiter->hit('job', 60));
        $this->assertSame(2, $limiter->hit('job', 60));
        $this->assertSame(2, $limiter->getAttempts('job'));
        $this->assertTrue($limiter->isLimited('job', 2));
        $this->assertFalse($limiter->isLimited('job', 3));
        $this->assertSame(0, $limiter->getRemaining('job', 2));
        $this->assertSame(1, $limiter->getRemaining('job', 3));
    }

    /**
     * @return void
     */
    public function testClearResetsCountAndTimer(): void
    {
        $limiter = new RateLimiter('test_limiter');
        $limiter->hit('job', 60);

        $this->assertTrue($limiter->isLimited('job', 1));

        $limiter->clear('job');

        $this->assertSame(0, $limiter->getAttempts('job'));
        $this->assertFalse($limiter->isLimited('job', 1));
        $this->assertSame(0, $limiter->getAvailableIn('job'));
    }

    /**
     * @return void
     */
    public function testAvailableInFollowsTimer(): void
    {
        $limiter = new RateLimiter('test_limiter');
        $limiter->hit('job', 60);

        $availableIn = $limiter->getAvailableIn('job');

        $this->assertGreaterThan(0, $availableIn);
        $this->assertLessThanOrEqual(60, $availableIn);
    }

    /**
     * @return void
     */
    public function testExpiredTimerResetsAttempts(): void
    {
        $limiter = new RateLimiter('test_limiter');
        $limiter->hit('job', 60);

        Cache::write('job:timer', time() - 10, 'test_limiter');

        $this->assertFalse($limiter->isLimited('job', 1));
        $this->assertSame(0, $limiter->getAttempts('job'));
    }

    /**
     * @return void
     */
    public function testAttemptRunsCallbackAndHits(): void
    {
        $limiter = new RateLimiter('test_limiter');

        $result = $limiter->attempt('job', 1, static fn(): string => 'done', 60);

        $this->assertSame('done', $result);
        $this->assertSame(1, $limiter->getAttempts('job'));
        $this->assertFalse($limiter->attempt('job', 1, static fn(): string => 'done', 60));
    }

    /**
     * @return void
     */
    public function testDecrementLowersCount(): void
    {
        $limiter = new RateLimiter('test_limiter');
        $limiter->hit('job', 60);
        $limiter->hit('job', 60);

        $this->assertSame(1, $limiter->decrement('job', 60));
        $this->assertFalse($limiter->isLimited('job', 2));
    }

    /**
     * @return void
     */
    public function testNamedLimiterRegistry(): void
    {
        $limiter = new RateLimiter('test_limiter');

        $this->assertFalse($limiter->hasLimiter('emails'));

        $limiter->setLimiter('emails', static fn(): string => 'limit');

        $this->assertTrue($limiter->hasLimiter('emails'));
        $this->assertNull($limiter->getLimiter('missing'));

        $resolver = $limiter->getLimiter('emails');
        $this->assertNotNull($resolver);
        $this->assertSame('limit', $resolver(new stdClass(), $this->createStub(Message::class)));
    }
}
