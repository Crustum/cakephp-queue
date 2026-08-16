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

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['value' => $this->value];
    }

    /**
     * @param array<string, mixed> $data Payload
     * @return static
     */
    public static function fromPayload(array $data): static
    {
        return new self((int)($data['value'] ?? 0));
    }
}
