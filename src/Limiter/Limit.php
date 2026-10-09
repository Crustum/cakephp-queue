<?php
declare(strict_types=1);

namespace Crustum\Queue\Limiter;

use Closure;

/**
 * Limit
 *
 * Rate limit definition (port of `Illuminate\Cache\RateLimiting\Limit`,
 * Cake naming applied). Consumed by named limiter resolvers and the
 * `RateLimited` job middleware.
 */
class Limit
{
    /**
     * @var string|int Limit signature key
     */
    protected string|int $key = '';

    /**
     * @var int Maximum attempts within the decay window
     */
    protected int $maxAttempts = 60;

    /**
     * @var int Window length in seconds
     */
    protected int $decaySeconds = 60;

    /**
     * @var \Closure|null Hit gate callback
     */
    protected ?Closure $afterCallback = null;

    /**
     * @var \Closure|null Limit-exceeded response callback (HTTP use)
     */
    protected ?Closure $responseCallback = null;

    /**
     * @param string|int $key Limit signature key
     * @param int $maxAttempts Maximum attempts within the decay window
     * @param int $decaySeconds Window length in seconds
     */
    public function __construct(string|int $key = '', int $maxAttempts = 60, int $decaySeconds = 60)
    {
        $this->key = $key;
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
    }

    /**
     * @param int $maxAttempts Maximum attempts
     * @param int $decaySeconds Window length in seconds
     * @return static
     */
    public static function perSecond(int $maxAttempts, int $decaySeconds = 1): static
    {
        return new static('', $maxAttempts, $decaySeconds);
    }

    /**
     * @param int $maxAttempts Maximum attempts
     * @param int $decayMinutes Window length in minutes
     * @return static
     */
    public static function perMinute(int $maxAttempts, int $decayMinutes = 1): static
    {
        return new static('', $maxAttempts, 60 * $decayMinutes);
    }

    /**
     * @param int $decayMinutes Window length in minutes
     * @param int $maxAttempts Maximum attempts
     * @return static
     */
    public static function perMinutes(int $decayMinutes, int $maxAttempts): static
    {
        return new static('', $maxAttempts, 60 * $decayMinutes);
    }

    /**
     * @param int $maxAttempts Maximum attempts
     * @param int $decayHours Window length in hours
     * @return static
     */
    public static function perHour(int $maxAttempts, int $decayHours = 1): static
    {
        return new static('', $maxAttempts, 60 * 60 * $decayHours);
    }

    /**
     * @param int $maxAttempts Maximum attempts
     * @param int $decayDays Window length in days
     * @return static
     */
    public static function perDay(int $maxAttempts, int $decayDays = 1): static
    {
        return new static('', $maxAttempts, 60 * 60 * 24 * $decayDays);
    }

    /**
     * @return \Crustum\Queue\Limiter\Unlimited
     */
    public static function none(): Unlimited
    {
        return new Unlimited();
    }

    /**
     * Set the limit signature key.
     *
     * @param string|int $key Limit signature key
     * @return $this
     */
    public function setKey(string|int $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Set the hit gate callback.
     *
     * @param \Closure $callback Gate callback
     * @return $this
     */
    public function setAfterCallback(Closure $callback): static
    {
        $this->afterCallback = $callback;

        return $this;
    }

    /**
     * Set the limit-exceeded response callback (HTTP use).
     *
     * @param \Closure $callback Response callback
     * @return $this
     */
    public function setResponseCallback(Closure $callback): static
    {
        $this->responseCallback = $callback;

        return $this;
    }

    /**
     * @return string|int
     */
    public function getKey(): string|int
    {
        return $this->key;
    }

    /**
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * @return int
     */
    public function getDecaySeconds(): int
    {
        return $this->decaySeconds;
    }

    /**
     * @return \Closure|null
     */
    public function getAfterCallback(): ?Closure
    {
        return $this->afterCallback;
    }

    /**
     * @return \Closure|null
     */
    public function getResponseCallback(): ?Closure
    {
        return $this->responseCallback;
    }

    /**
     * Get a collision-free fallback key for duplicated limit keys.
     *
     * @return string
     */
    public function getFallbackKey(): string
    {
        $prefix = $this->key !== '' ? "{$this->key}:" : '';

        return "{$prefix}attempts:{$this->maxAttempts}:decay:{$this->decaySeconds}";
    }
}
