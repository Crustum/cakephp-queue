<?php
declare(strict_types=1);

namespace Crustum\Queue\Limiter;

/**
 * Unlimited
 *
 * Pass-through limit (port of `Illuminate\Cache\RateLimiting\Unlimited`).
 * Jobs guarded by it always run.
 */
class Unlimited extends GlobalLimit
{
    /**
     * Create an unlimited limit instance.
     */
    public function __construct()
    {
        parent::__construct(PHP_INT_MAX);
    }
}
