<?php
declare(strict_types=1);

namespace TestApp\Queue\Command;

use Crustum\Queue\CommandMessage;

/**
 * Test command for CommandBus tests (independent of Codex jobs).
 */
final class TestPingCommand implements CommandMessage
{
    public function __construct(
        public readonly int $value = 1,
    ) {
    }
}
