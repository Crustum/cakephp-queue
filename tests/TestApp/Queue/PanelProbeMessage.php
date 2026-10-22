<?php
declare(strict_types=1);

namespace TestApp\Queue;

/**
 * Probe processor message with fixed arguments.
 */
class PanelProbeMessage
{
    /**
     * @param array<string, mixed> $args Message arguments.
     */
    public function __construct(protected array $args = [])
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getArgument(): array
    {
        return $this->args;
    }
}
