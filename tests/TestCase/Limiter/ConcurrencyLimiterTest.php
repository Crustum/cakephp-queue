<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Limiter;

use Cake\Lock\Engine\FileLockEngine;
use Cake\Lock\Lock;
use Cake\Lock\LockRegistry;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Limiter\ConcurrencyLimiter;
use Crustum\Queue\Limiter\ConcurrencyLimiterBuilder;
use Crustum\Queue\Limiter\LimiterTimeoutException;

/**
 * ConcurrencyLimiter Test Case
 */
class ConcurrencyLimiterTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Lock::setConfig('test_lock', [
            'className' => FileLockEngine::class,
            'path' => TMP . 'locks_test' . DS,
            'prefix' => 'test_',
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Lock::drop('test_lock');
        Lock::setRegistry(new LockRegistry());
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testBlockRunsCallbackInsideSlot(): void
    {
        $limiter = new ConcurrencyLimiter('test_lock', 'slots', 1, 60);

        $result = $limiter->block(1, static fn(): string => 'done');

        $this->assertSame('done', $result);
        $this->assertFalse(Lock::isLocked('slots1', 'test_lock'));
    }

    /**
     * @return void
     */
    public function testBlockTimesOutWhenSlotsBusy(): void
    {
        $held = Lock::acquire('slots1', 60, 'test_lock');
        $this->assertNotNull($held);

        $limiter = new ConcurrencyLimiter('test_lock', 'slots', 1, 60);

        try {
            $limiter->block(0, static fn(): string => 'done', 10);
            $this->fail('Expected LimiterTimeoutException.');
        } catch (LimiterTimeoutException $limiterTimeoutException) {
            $this->assertStringContainsString('slots', $limiterTimeoutException->getMessage());
        } finally {
            $held->release();
        }
    }

    /**
     * @return void
     */
    public function testSecondSlotAllowsConcurrency(): void
    {
        $held = Lock::acquire('slots1', 60, 'test_lock');
        $this->assertNotNull($held);

        $limiter = new ConcurrencyLimiter('test_lock', 'slots', 2, 60);

        try {
            $this->assertSame('done', $limiter->block(1, static fn(): string => 'done'));
        } finally {
            $held->release();
        }
    }

    /**
     * @return void
     */
    public function testBuilderThenFallsBackOnTimeout(): void
    {
        $held = Lock::acquire('slots1', 60, 'test_lock');
        $this->assertNotNull($held);

        $result = (new ConcurrencyLimiterBuilder('test_lock', 'slots'))
            ->setMaxLocks(1)
            ->setTimeout(0)
            ->setSleep(10)
            ->then(static fn(): string => 'done', static fn(): string => 'fallback');

        $held->release();

        $this->assertSame('fallback', $result);
    }
}
