<?php
declare(strict_types=1);

namespace Crustum\Queue\Limiter;

use RuntimeException;

/**
 * Thrown when a concurrency limiter slot cannot be acquired within the timeout.
 */
class LimiterTimeoutException extends RuntimeException
{
}
