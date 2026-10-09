<?php
declare(strict_types=1);

namespace Crustum\Queue\Test\TestCase\Limiter;

use Cake\Queue\Job\Message;
use Cake\TestSuite\TestCase;
use Crustum\Queue\Limiter\GlobalLimit;
use Crustum\Queue\Limiter\Limit;
use Crustum\Queue\Limiter\RateLimiter;
use Crustum\Queue\Limiter\Unlimited;
use stdClass;

/**
 * Limit Test Case
 */
class LimitTest extends TestCase
{
    /**
     * @return void
     */
    public function testFactories(): void
    {
        $this->assertSame([60, 1], $this->pair(Limit::perSecond(60)));
        $this->assertSame([100, 60], $this->pair(Limit::perMinute(100)));
        $this->assertSame([100, 300], $this->pair(Limit::perMinutes(5, 100)));
        $this->assertSame([500, 3600], $this->pair(Limit::perHour(500)));
        $this->assertSame([1000, 86400], $this->pair(Limit::perDay(1000)));
        $this->assertInstanceOf(Unlimited::class, Limit::none());
    }

    /**
     * @return void
     */
    public function testFluentConfiguration(): void
    {
        $limit = Limit::perMinute(100)->setKey('emails');

        $this->assertSame('emails', $limit->getKey());
        $this->assertSame(100, $limit->getMaxAttempts());
        $this->assertSame(60, $limit->getDecaySeconds());
        $this->assertSame('emails:attempts:100:decay:60', $limit->getFallbackKey());
    }

    /**
     * @return void
     */
    public function testGlobalAndUnlimited(): void
    {
        $global = new GlobalLimit(10, 30);

        $this->assertSame('', $global->getKey());
        $this->assertSame('attempts:10:decay:30', $global->getFallbackKey());

        $unlimited = new Unlimited();

        $this->assertSame(PHP_INT_MAX, $unlimited->getMaxAttempts());
    }

    /**
     * @return void
     */
    public function testRegistryAppliesFallbackKeysToDuplicates(): void
    {
        $limiter = new RateLimiter('default');
        $limiter->setLimiter('sending', static fn(): array => [
            Limit::perMinute(100)->setKey('emails'),
            Limit::perMinute(10)->setKey('emails'),
        ]);

        $resolver = $limiter->getLimiter('sending');
        $this->assertNotNull($resolver);

        [$first, $second] = $resolver(new stdClass(), $this->createStub(Message::class));

        $this->assertSame('emails:attempts:100:decay:60', $first->getKey());
        $this->assertSame('emails:attempts:10:decay:60', $second->getKey());
    }

    /**
     * Get the `[maxAttempts, decaySeconds]` pair.
     *
     * @param \Crustum\Queue\Limiter\Limit $limit Limit definition
     * @return array{int, int}
     */
    protected function pair(Limit $limit): array
    {
        return [$limit->getMaxAttempts(), $limit->getDecaySeconds()];
    }
}
