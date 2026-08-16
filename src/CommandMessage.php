<?php
declare(strict_types=1);

namespace Crustum\Queue;

/**
 * Typed job payload contract (prototype for crustum/cakephp-queue CommandBus).
 *
 * A command message is a leaf DTO: callers construct it, {@see CommandBus} maps
 * its class to a handler job, the job restores it in execute() via fromPayload().
 */
interface CommandMessage
{
    /**
     * Build the JSON-safe queue payload for the handler job.
     *
     * @return array<string, mixed> JSON-safe payload for the queue
     */
    public function payload(): array;

    /**
     * Rebuild the command from a queue message payload.
     *
     * @param array<string, mixed> $data Payload from the queue message
     * @return static
     */
    public static function fromPayload(array $data): static;
}
