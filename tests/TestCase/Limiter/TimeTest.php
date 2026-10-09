<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Limiter;

use Cake\Cache\Cache;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Limiter\RateLimiter;
use Crustum\Queue\Limiter\Time;

/**
 * Time Test Case
 *
 * Proves the clock freezes via `DateTime::setTestNow()`: decay windows
 * expire without `sleep()`.
 */
class TimeTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        DateTime::setTestNow();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testNowFreezes(): void
    {
        $frozen = new DateTime('2026-01-01 00:00:00');
        DateTime::setTestNow($frozen);

        $this->assertSame($frozen->getTimestamp(), Time::now());
        $this->assertSame($frozen->getTimestamp() + 60, Time::availableAt(60));
    }

    /**
     * @return void
     */
    public function testLimiterWindowExpiresWithoutSleep(): void
    {
        Cache::setConfig('test_time', [
            'className' => 'Array',
            'prefix' => 'test_time_',
        ]);

        try {
            $limiter = new RateLimiter('test_time');
            DateTime::setTestNow(new DateTime('2026-01-01 00:00:00'));
            $limiter->hit('job', 60);

            $this->assertTrue($limiter->isLimited('job', 1));

            DateTime::setTestNow(new DateTime('2026-01-01 00:02:00'));

            $this->assertFalse($limiter->isLimited('job', 1));
            $this->assertSame(0, $limiter->getAttempts('job'));
        } finally {
            DateTime::setTestNow();
            Cache::drop('test_time');
        }
    }
}
