<?php
declare(strict_types=1);

namespace Crustum\Queue;

use Attribute;

/**
 * Marks a handler job as the executor for a {@see CommandMessage} class.
 *
 * Attribute is placed on the JOB (the handler), not the command — the direction
 * stays Job → CommandQueue (downward), so StructArmed sees no cycle. The resolver
 * reads it during {@see CommandBus::registerFromAttributes()}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Handles
{
    /**
     * @param class-string<\Crustum\Queue\CommandMessage> $commandClass Command message class
     */
    public function __construct(
        public string $commandClass,
    ) {
    }
}
