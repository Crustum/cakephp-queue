<?php
declare(strict_types=1);

namespace Crustum\Queue;

/**
 * Typed job payload contract for {@see CommandBus}.
 *
 * A command message is a leaf DTO: callers construct it, {@see CommandBus} maps
 * its class to a handler job, the job restores it in execute() via
 * `Message::getDto()`.
 *
 * Commands are plain DTOs hydrated by `DtoManager` (constructor mapping, nested
 * DTOs, `#[CollectionOf]`); no methods, traits, or base classes required. The
 * two optional core hooks still apply when needed: `JsonSerializable` controls
 * serialization, a static `createFromArray(array, bool)` controls hydration.
 */
interface CommandMessage
{
}
