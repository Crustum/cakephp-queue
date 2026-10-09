<?php
declare(strict_types=1);

namespace Crustum\Queue\Limiter;

/**
 * Global Limit
 *
 * Class-wide limit without a signature key (port of
 * `Illuminate\Cache\RateLimiting\GlobalLimit`).
 */
class GlobalLimit extends Limit
{
    /**
     * @param int $maxAttempts Maximum attempts within the decay window
     * @param int $decaySeconds Window length in seconds
     */
    public function __construct(int $maxAttempts, int $decaySeconds = 60)
    {
        parent::__construct('', $maxAttempts, $decaySeconds);
    }
}
